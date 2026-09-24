<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPositionSetting;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Числа позиции заявки: склад, изготовлено, всего.
 *
 * Единственное место, где эта арифметика считается — карточка, список заявок и
 * мобильная карточка берут готовые строки. Раньше формула была выписана в трёх
 * шаблонах и копии успели разойтись.
 */
class OrderPositionService
{
    /**
     * @param  array<int, array<string, float>>  $produced  [product_id => [store_id => qty]]
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(Order $order, ?string $defaultStoreId, array $produced = []): Collection
    {
        $settings = $order->positionSettings->keyBy('product_id');
        $inProduction = $order->production_started_at !== null;

        return $order->items->map(function (OrderItem $item) use ($settings, $defaultStoreId, $produced, $inProduction) {
            $product = $item->product;
            $setting = $product ? $settings->get($product->id) : null;

            $ordered = (float) $item->quantity;
            $shipped = (float) $item->shipped;
            $left    = max(0, $ordered - $shipped);

            $storeIds = $this->storeIds($setting, $defaultStoreId);

            $warehouseQty = null;
            $producedAuto = null;
            $producedQty  = null;
            $totalQty     = null;
            $storeQty     = [];
            $producedByStore = [];

            if ($product && $storeIds) {
                // После входа в производство показываем снимок: изготовленное
                // приходуется на склад, и живой остаток задвоил бы «Всего».
                $storeQty = $setting?->isFrozen()
                    ? $setting->frozen_stocks
                    : $product->stocks->mapWithKeys(
                        fn ($s) => [$s->store_id => (float) $s->quantity],
                    )->all();

                $producedByStore = $inProduction ? ($produced[$product->id] ?? []) : [];

                // Приведение к float обязательно: max(0, 0.0) возвращает int,
                // и тип числа в строке плавал бы от данных.
                $base = $this->sumMap($storeQty, $storeIds);
                $warehouseQty = (float) max(0, $base + (float) ($setting->stock_delta ?? 0));

                $producedAuto = $this->sumMap($producedByStore, $storeIds);
                $producedQty  = (float) max(0, $producedAuto + (float) ($setting->produced_delta ?? 0));

                $totalQty = $warehouseQty + $producedQty;
            }

            return [
                'item'            => $item,
                'product'         => $product,
                'name'            => $product?->name ?? $item->product_name ?? '—',
                'ordered'         => $ordered,
                'shipped'         => $shipped,
                'left'            => $left,
                'done'            => $ordered > 0 && $shipped >= $ordered,
                'partial'         => $shipped > 0 && $shipped < $ordered,
                'stores'          => $storeIds,
                // Карты для модалки: остаток и изготовленное в разрезе складов.
                'storeQty'        => $storeQty,
                'producedByStore' => $producedByStore,
                'visibleStores'   => $this->visibleStores(
                    $storeQty,
                    $producedByStore,
                    $storeIds,
                    $defaultStoreId,
                ),
                'warehouseQty'    => $warehouseQty,
                'producedAuto'    => $producedAuto,
                'producedQty'     => $producedQty,
                'totalQty'        => $totalQty,
                'setting'         => $setting,
                'frozen'          => (bool) $setting?->isFrozen(),
                // Дефицит считаем от «Всего»: позиция, закрытая производством,
                // не должна показывать нехватку.
                'short'           => $totalQty === null ? null : (float) max(0, $left - $totalQty),
                'ready'           => $left > 0 ? ($totalQty === null ? null : min(1, $totalQty / $left)) : 1.0,
                'color'           => Product::getColorBySku($product?->sku),
                'icon'            => Product::getIconBySku($product?->sku),
            ];
        });
    }

    /**
     * Сохранить настройки позиции. Поправки хранятся дельтами к автоматическому
     * значению, поэтому пустое поле факта дельту не трогает.
     *
     * @param  array<int, string>  $storeIds
     * @param  array<string, float>  $producedForProduct  [store_id => qty]
     */
    public function save(
        Order $order,
        Product $product,
        array $storeIds,
        ?float $stockFact,
        ?float $producedFact,
        ?string $note,
        ?User $user,
        array $producedForProduct = [],
    ): OrderPositionSetting {
        $setting = OrderPositionSetting::firstOrNew([
            'order_id'   => $order->id,
            'product_id' => $product->id,
        ]);

        $setting->stores = array_values(array_unique($storeIds));

        if ($stockFact !== null) {
            $base = $setting->isFrozen()
                ? $this->sumMap($setting->frozen_stocks, $setting->stores)
                : $this->sumStocks($product, $setting->stores);

            $setting->stock_base  = $base;
            $setting->stock_delta = round($stockFact - $base, 3);
        }

        if ($producedFact !== null) {
            $auto = $this->sumMap($producedForProduct, $setting->stores);

            $setting->produced_base  = $auto;
            $setting->produced_delta = round($producedFact - $auto, 3);
        }

        $setting->note    = $note;
        $setting->user_id = $user?->id;
        $setting->save();

        return $setting;
    }

    /**
     * Снять уточнения. Снимок остатка при этом сохраняется — он относится не к
     * правкам мастера, а к моменту входа заявки в производство.
     */
    public function reset(Order $order, int $productId): void
    {
        $setting = OrderPositionSetting::query()
            ->where('order_id', $order->id)
            ->where('product_id', $productId)
            ->first();

        if (! $setting) {
            return;
        }

        if ($setting->isFrozen()) {
            $setting->update([
                'stores'         => null,
                'stock_delta'    => 0,
                'stock_base'     => 0,
                'produced_delta' => 0,
                'produced_base'  => 0,
                'note'           => null,
                'user_id'        => null,
            ]);

            return;
        }

        $setting->delete();
    }

    /**
     * Склады, предлагаемые в выборе: те, где что-то есть. Складов в базе десяток,
     * и список из одних нулей искать в нём мешает.
     *
     * Всегда оставляем уже отмеченные — иначе снять галочку со склада, опустевшего
     * после отгрузки, стало бы нечем. И склад заявки по умолчанию: он же остаётся
     * единственным вариантом, когда остаток нулевой везде.
     *
     * @param  array<string, float>  $storeQty
     * @param  array<string, float>  $producedByStore
     * @param  array<int, string>  $selected
     * @return array<int, string>
     */
    public function visibleStores(
        array $storeQty,
        array $producedByStore,
        array $selected,
        ?string $defaultStoreId,
    ): array {
        $visible = [];

        foreach ([$storeQty, $producedByStore] as $map) {
            foreach ($map as $storeId => $qty) {
                if ((float) $qty != 0.0) {
                    $visible[$storeId] = true;
                }
            }
        }

        foreach ($selected as $storeId) {
            $visible[$storeId] = true;
        }

        if ($defaultStoreId) {
            $visible[$defaultStoreId] = true;
        }

        return array_keys($visible);
    }

    /**
     * Склады позиции: выбранные мастером либо склад заявки по умолчанию.
     *
     * @return array<int, string>
     */
    public function storeIds(?OrderPositionSetting $setting, ?string $defaultStoreId): array
    {
        $selected = $setting?->stores;

        if (! empty($selected)) {
            return array_values($selected);
        }

        return $defaultStoreId ? [$defaultStoreId] : [];
    }

    /**
     * @param  array<string, float>|null  $map
     * @param  array<int, string>  $storeIds
     */
    private function sumMap(?array $map, array $storeIds): float
    {
        $sum = 0.0;

        foreach ($storeIds as $storeId) {
            $sum += (float) ($map[$storeId] ?? 0);
        }

        return $sum;
    }

    /** @param  array<int, string>  $storeIds */
    private function sumStocks(Product $product, array $storeIds): float
    {
        return (float) $product->stocks
            ->whereIn('store_id', $storeIds)
            ->sum('quantity');
    }
}
