<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderPositionSetting;
use App\Models\OrderState;
use App\Models\ProductStock;
use App\Models\WorkshopItem;
use App\Services\Moysklad\StockSyncService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Производство в разрезе заявки: окно «пока заявка была в производственном статусе»
 * и сколько за это окно изготовили по каждой позиции.
 *
 * Считаем по журналам дельт (reception_logs / workshop_logs), а не по позициям
 * документов: правка задним числом попадает в тот период, когда её сделали,
 * и ничего не задваивается. Тот же источник, что у сводки предприятия
 * (WorkerDashboardService::getEnterpriseDashboardData).
 */
class OrderProductionService
{
    public function __construct(private StockSyncService $stockSync)
    {
    }

    /**
     * Привести окно производства в соответствие со статусом заявки.
     * Зовётся и при смене статуса из программы, и при синхронизации с МойСклад.
     */
    public function syncPeriod(Order $order, ?string $newStateId): void
    {
        $was = $order->production_started_at !== null && $order->production_ended_at === null;
        $is  = $newStateId !== null && in_array($newStateId, $this->productionStateIds(), true);

        if ($was === $is) {
            return;
        }

        if ($is) {
            $this->start($order);
        } else {
            $order->update(['production_ended_at' => now()]);
        }
    }

    /**
     * Вход в производство: фиксируем остаток, от которого дальше пойдёт отсчёт.
     *
     * Перед снимком перечитываем остатки из МойСклад — product_stocks обновляется
     * ночью и после документов, и без этого снимок лёг бы по устаревшему кэшу.
     */
    private function start(Order $order): void
    {
        $order->loadMissing('items.product');
        $this->refreshStocks($order);

        $order->update([
            'production_started_at' => now(),
            'production_ended_at'   => null,
        ]);

        $this->freezeStocks($order);
    }

    /**
     * Пересчитать заявку по складам: перечитать остатки из МойСклад и снять снимок заново.
     *
     * Изготовленное при этом обнуляется — начало окна сдвигается на «сейчас». Новый снимок
     * уже включает всё, что произвели раньше, и без сброса окна «Всего» задвоило бы эти метры.
     *
     * Смотрим на текущий статус, а не на наличие окна: так пересчёт открывает отсчёт и
     * заявке, которая висит в производственном статусе с тех пор, как его ещё не считали.
     */
    public function recalculate(Order $order): void
    {
        $order->loadMissing('items.product');
        $this->refreshStocks($order);

        if (! in_array($order->state_moysklad_id, $this->productionStateIds(), true)) {
            return;
        }

        $order->update([
            'production_started_at' => now(),
            'production_ended_at'   => null,
        ]);

        $this->freezeStocks($order);
    }

    /** Перечитать остатки товаров заявки из МойСклад. Ошибки API сервис глотает и логирует. */
    private function refreshStocks(Order $order): void
    {
        $this->stockSync->refreshProducts(
            $order->items->pluck('product.moysklad_id')->filter()->all(),
        );
    }

    /**
     * Снимок остатков по всем неархивным складам для каждой позиции заявки.
     * Снимаем по всем складам, а не только по выбранным: иначе смена набора
     * складов после заморозки требовала бы пересъёмки.
     */
    public function freezeStocks(Order $order): void
    {
        $productIds = $order->items->pluck('product_id')->filter()->unique();

        if ($productIds->isEmpty()) {
            return;
        }

        $byProduct = ProductStock::query()
            ->whereIn('product_id', $productIds)
            ->whereHas('store', fn ($q) => $q->where('archived', false))
            ->get()
            ->groupBy('product_id');

        foreach ($productIds as $productId) {
            $snapshot = ($byProduct[$productId] ?? collect())
                ->mapWithKeys(fn (ProductStock $s) => [$s->store_id => (float) $s->quantity])
                ->all();

            OrderPositionSetting::updateOrCreate(
                ['order_id' => $order->id, 'product_id' => $productId],
                ['frozen_stocks' => $snapshot],
            );
        }
    }

    /**
     * Изготовленное по каждой заявке страницы — две выборки на всех, а не по заявке:
     * в списке их двадцать.
     *
     * @param  Collection<int, Order>  $orders
     * @return array<int, array<int, array<string, float>>>  [order_id => [product_id => [store_id => qty]]]
     */
    public function producedForOrders(Collection $orders): array
    {
        $active = $orders->filter(fn (Order $o) => $o->production_started_at !== null);

        if ($active->isEmpty()) {
            return [];
        }

        $productIds = $active->flatMap(fn (Order $o) => $o->items->pluck('product_id'))
            ->filter()->unique()->values();

        if ($productIds->isEmpty()) {
            return [];
        }

        $from = $active->min(fn (Order $o) => $o->production_started_at);
        $rows = $this->productionRows($productIds->all(), $from, now());

        $result = [];

        foreach ($active as $order) {
            $ownProducts = $order->items->pluck('product_id')->filter()->flip();
            $started = $order->production_started_at;
            $ended   = $order->production_ended_at;

            $map = [];
            foreach ($rows as $row) {
                if (! $ownProducts->has($row->product_id) || ! $row->store_id) {
                    continue;
                }

                $at = Carbon::parse($row->created_at);
                if ($at->lt($started) || ($ended && $at->gt($ended))) {
                    continue;
                }

                $map[$row->product_id][$row->store_id]
                    = ($map[$row->product_id][$row->store_id] ?? 0.0) + (float) $row->qty;
            }

            $result[$order->id] = $map;
        }

        return $result;
    }

    /** Изготовленное по одной заявке: [product_id => [store_id => qty]] */
    public function producedForOrder(Order $order): array
    {
        return $this->producedForOrders(collect([$order]))[$order->id] ?? [];
    }

    /**
     * Сырые строки журналов производства за окно. Без агрегации: окна у заявок
     * разные, раскладка по ним делается в PHP.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, object>
     */
    private function productionRows(array $productIds, Carbon $from, Carbon $to): array
    {
        // Приёмка: склад готовой продукции — stone_receptions.store_id
        $receptions = DB::table('reception_log_items as rli')
            ->join('reception_logs as rl', 'rl.id', '=', 'rli.reception_log_id')
            ->join('stone_receptions as sr', 'sr.id', '=', 'rl.stone_reception_id')
            ->whereIn('rli.product_id', $productIds)
            ->whereBetween('rl.created_at', [$from, $to])
            ->select([
                'rli.product_id',
                'sr.store_id',
                'rl.created_at',
                'rli.quantity_delta as qty',
            ])
            ->get();

        // Цех: изготовленное — строки role=product, склад продукта отдельный от склада сырья
        $workshops = DB::table('workshop_log_items as wli')
            ->join('workshop_logs as wl', 'wl.id', '=', 'wli.workshop_log_id')
            ->join('workshops as w', 'w.id', '=', 'wl.workshop_id')
            ->where('wli.role', WorkshopItem::ROLE_PRODUCT)
            ->whereIn('wli.product_id', $productIds)
            ->whereBetween('wl.created_at', [$from, $to])
            ->select([
                'wli.product_id',
                DB::raw('COALESCE(w.product_store_id, w.store_id) as store_id'),
                'wl.created_at',
                'wli.quantity_delta as qty',
            ])
            ->get();

        return $receptions->concat($workshops)->all();
    }

    /** @return array<int, string> */
    private function productionStateIds(): array
    {
        return OrderState::production()->pluck('id')->all();
    }
}
