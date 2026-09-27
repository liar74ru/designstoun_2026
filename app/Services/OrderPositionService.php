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
     * @param  array<int, array<string, mixed>>  $allocation  доля позиции в общем объёме товара
     *         (OrderProductionService::allocate) — [product_id => ['stock', 'produced', 'sharedWith']];
     *         позиции без доли считаются по снимку заявки и её окну
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(Order $order, ?string $defaultStoreId, array $produced = [], array $allocation = []): Collection
    {
        $settings = $order->positionSettings->keyBy('product_id');
        $inProduction = $order->production_started_at !== null;

        return $order->items->map(function (OrderItem $item) use ($settings, $defaultStoreId, $produced, $allocation, $inProduction) {
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
            $share = $product ? ($allocation[$product->id] ?? null) : null;

            if ($product && $storeIds) {
                // После входа в производство показываем снимок: изготовленное
                // приходуется на склад, и живой остаток задвоил бы «Всего».
                // Товар общий с другими заявками в производстве — берём свою долю.
                $storeQty = match (true) {
                    $share !== null       => $share['stock'],
                    $setting?->isFrozen() => $setting->frozen_stocks,
                    default               => $product->stocks->mapWithKeys(
                        fn ($s) => [$s->store_id => (float) $s->quantity],
                    )->all(),
                };

                $producedByStore = match (true) {
                    $share !== null => $share['produced'],
                    $inProduction   => $produced[$product->id] ?? [],
                    default         => [],
                };

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
                // Ручная отметка «готово»; не путать с 'ready' — долей закрытия остатка.
                'isReady'         => (bool) $setting?->isReady(),
                // Отделы, для которых позиция скрыта в списке заявок.
                'hiddenFor'       => $setting?->hiddenDepartmentIds() ?? [],
                // Заявки, с которыми позиция делит товар (выше и ниже по очереди).
                'sharedWith'      => $share['sharedWith'] ?? [],
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
     * @param  array<string, float>|null  $stockForProduct  доля остатка, если товар делится
     *         с другими заявками; null — база от снимка или живого остатка
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
        ?array $stockForProduct = null,
    ): OrderPositionSetting {
        $setting = OrderPositionSetting::firstOrNew([
            'order_id'   => $order->id,
            'product_id' => $product->id,
        ]);

        $setting->stores = array_values(array_unique($storeIds));

        if ($stockFact !== null) {
            $base = match (true) {
                $stockForProduct !== null => $this->sumMap($stockForProduct, $setting->stores),
                $setting->isFrozen()      => $this->sumMap($setting->frozen_stocks, $setting->stores),
                default                   => $this->sumStocks($product, $setting->stores),
            };

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
     * Отметить позицию готовой или снять отметку. Строка настроек создаётся при
     * необходимости: пустой stores у неё означает склад заявки.
     */
    public function setReady(Order $order, int $productId, bool $ready, ?User $user): OrderPositionSetting
    {
        $setting = OrderPositionSetting::firstOrNew([
            'order_id'   => $order->id,
            'product_id' => $productId,
        ]);

        $setting->ready_at      = $ready ? now() : null;
        $setting->ready_user_id = $ready ? $user?->id : null;
        $setting->save();

        return $setting;
    }

    /**
     * Скрыть позицию для отдела или вернуть её. Строка настроек создаётся при
     * необходимости: пустой stores у неё означает склад заявки.
     */
    public function setHidden(Order $order, int $productId, int $departmentId, bool $hidden): OrderPositionSetting
    {
        $setting = OrderPositionSetting::firstOrNew([
            'order_id'   => $order->id,
            'product_id' => $productId,
        ]);

        $ids = array_diff($setting->hiddenDepartmentIds(), [$departmentId]);
        if ($hidden) {
            $ids[] = $departmentId;
        }

        sort($ids);
        $setting->hidden_department_ids = $ids ? array_values($ids) : null;
        $setting->save();

        return $setting;
    }

    /**
     * Скрыта ли позиция для тех, кто смотрит список. Смотрящих отделов может быть
     * несколько (мастер в двух отделах, несколько отделов в фильтре) — позиция видна,
     * если нужна хоть одному из них. В расчёт идут только отделы самой заявки:
     * отметка отдела, снятого с заявки, ничего не прячет.
     *
     * @param  array<int, int>  $hiddenFor
     * @param  array<int, int>  $viewDepartmentIds
     * @param  array<int, int>  $orderDepartmentIds
     */
    public function isHiddenFor(array $hiddenFor, array $viewDepartmentIds, array $orderDepartmentIds): bool
    {
        $relevant = array_intersect($viewDepartmentIds, $orderDepartmentIds);

        return $relevant !== [] && array_diff($relevant, $hiddenFor) === [];
    }

    /**
     * Снять уточнения. Снимок остатка, отметка «готово» и скрытие для отделов при этом
     * сохраняются — они относятся не к правкам мастера: снимок — к моменту входа заявки
     * в производство, готовность и скрытие снимаются только вручную.
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

        if ($setting->isFrozen() || $setting->isReady() || $setting->hiddenDepartmentIds() !== []) {
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
