<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Product;
use App\Models\RawMaterialBatch;
use App\Models\RawMaterialMovement;
use App\Models\ReceptionLog;
use App\Models\Setting;
use App\Models\StoneReception;
use App\Models\WorkshopItem;
use App\Models\WorkshopLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;

// рефакторинг v2 от 26.04.2026 — controller → service
class WorkerDashboardService
{
    public function getDefaultWeekRange(): array
    {
        $today = Carbon::today();

        $friday = $today->copy()->startOfDay();
        while ($friday->dayOfWeek !== Carbon::FRIDAY) {
            $friday->subDay();
        }

        $thursday = $friday->copy()->addDays(6)->endOfDay();

        return [$friday, $thursday];
    }

    public function getDashboardData(int $workerId, bool $isMaster, Carbon $dateFrom, Carbon $dateTo): array
    {
        $workerField = $isMaster ? 'receiver_id' : 'cutter_id';

        $logs = ReceptionLog::with([
                'items.product',
                'stoneReception.store',
                'stoneReception.items',
                'rawMaterialBatch.product',
                $isMaster ? 'cutter' : 'receiver',
            ])
            ->where($workerField, $workerId)
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->orderBy('created_at', 'desc')
            ->get();

        $stoneReceptionIds = $logs->pluck('stone_reception_id')->filter()->unique();
        $stoneReceptions = StoneReception::with([
                'items.product',
                'rawMaterialBatch.product',
                $isMaster ? 'cutter' : 'receiver',
                'store',
            ])
            ->whereIn('id', $stoneReceptionIds)
            ->orderBy('created_at', 'desc')
            ->get();

        $batchIds = $stoneReceptions->pluck('raw_material_batch_id')->filter()->unique();
        $rawBatches = RawMaterialBatch::with(['product', 'currentStore'])
            ->where(function ($q) use ($workerId, $batchIds, $isMaster) {
                $q->whereIn('id', $batchIds);
                if (!$isMaster) {
                    $q->orWhere(function ($q2) use ($workerId) {
                        $q2->whereIn('status', [RawMaterialBatch::STATUS_NEW, RawMaterialBatch::STATUS_IN_WORK])
                            ->where('current_worker_id', $workerId);
                    });
                }
            })
            ->orderByRaw("CASE status WHEN 'in_work' THEN 0 WHEN 'new' THEN 1 ELSE 2 END")
            ->orderByDesc('updated_at')
            ->get();

        $summary        = $this->buildProductSummary($logs);
        $totalPay       = $isMaster ? null : $summary->sum('pay');
        $totalMasterPay = $isMaster ? $summary->sum('masterPay') : null;
        $rates          = $isMaster ? [
            'base'      => (float) Setting::get('MASTER_BASE_RATE', 100),
            'undercut'  => (float) Setting::get('MASTER_UNDERCUT_RATE', 50),
            'packaging' => (float) Setting::get('MASTER_PACKAGING_RATE', 30),
            'smallTile' => (float) Setting::get('MASTER_SMALL_TILE_RATE', 50),
        ] : null;

        return compact(
            'logs',
            'stoneReceptions',
            'rawBatches',
            'summary',
            'totalPay',
            'totalMasterPay',
            'rates',
        );
    }

    /**
     * Общий дашборд предприятия: агрегация всего производства за период по всем приёмкам,
     * с группировкой по отделам (внутри — сводка по продуктам). Только для админа.
     */
    public function getEnterpriseDashboardData(
        ?Carbon $dateFrom,
        ?Carbon $dateTo,
        array $departmentIds = [],
        $rawProductId = null
    ): array {
        $logs = ReceptionLog::with([
                'items.product',
                'stoneReception.items',
                'stoneReception.department',
            ])
            ->when($dateFrom && $dateTo, fn ($q) => $q->whereBetween('created_at', [$dateFrom, $dateTo]))
            ->when($departmentIds, fn ($q) => $q->whereHas('stoneReception',
                fn ($q2) => $q2->whereIn('department_id', $departmentIds)))
            ->when($rawProductId, fn ($q) => $q->whereHas('rawMaterialBatch',
                fn ($q2) => $q2->where('product_id', $rawProductId)))
            ->orderBy('created_at', 'desc')
            ->get();

        // Привязать inverse-отношение log↔items, чтобы buildProductSummary не делал N+1.
        $logs->each(fn($log) => $log->items->each(fn($item) => $item->setRelation('receptionLog', $log)));

        // Производство цеха: логи по тому же паттерну, что и ReceptionLog.
        // Статус цеха не фильтруем — паритет с приёмками камня.
        $workshopLogs = WorkshopLog::with([
                'items.product',
                'workshop.items',
                'workshop.department',
            ])
            ->when($dateFrom && $dateTo, fn ($q) => $q->whereBetween('created_at', [$dateFrom, $dateTo]))
            ->when($departmentIds, fn ($q) => $q->whereHas('workshop',
                fn ($q2) => $q2->whereIn('department_id', $departmentIds)))
            ->when($rawProductId, fn ($q) => $q->whereHas('workshop.items',
                fn ($q2) => $q2->where('role', WorkshopItem::ROLE_RAW)->where('product_id', $rawProductId)))
            ->get();

        $workshopLogs->each(fn($log) => $log->items->each(fn($item) => $item->setRelation('workshopLog', $log)));

        $stoneByDept    = $logs->groupBy(fn($log) => $log->stoneReception?->department_id);
        $workshopByDept = $workshopLogs->groupBy(fn($log) => $log->workshop?->department_id);

        $departments = $stoneByDept->keys()
            ->merge($workshopByDept->keys())
            ->unique()
            ->map(function ($deptId) use ($stoneByDept, $workshopByDept) {
                $deptStoneLogs    = $stoneByDept->get($deptId, collect());
                $deptWorkshopLogs = $workshopByDept->get($deptId, collect());

                $summary = $this->mergeProductSummaries(
                    $this->buildProductSummary($deptStoneLogs),
                    $this->buildWorkshopProductSummary($deptWorkshopLogs),
                );

                return [
                    'department'     => $deptStoneLogs->first()?->stoneReception?->department
                        ?? $deptWorkshopLogs->first()?->workshop?->department,
                    'summary'        => $summary,
                    'totalQuantity'  => $summary->sum('quantity'),
                    'totalPay'       => $summary->sum('pay'),
                    'totalMasterPay' => $summary->sum('masterPay'),
                ];
            })
            ->filter(fn($row) => $row['summary']->isNotEmpty())
            ->sortBy(fn($row) => $row['department']?->name ?? "\u{FFFF}")
            ->values();

        $incomingRaw = $this->buildIncomingRawSummary($dateFrom, $dateTo, $departmentIds, $rawProductId);

        return [
            'departments'       => $departments,
            'grandQuantity'     => $departments->sum('totalQuantity'),
            'grandPay'          => $departments->sum('totalPay'),
            'grandMasterPay'    => $departments->sum('totalMasterPay'),
            'incomingRaw'       => $incomingRaw,
            'incomingRawTotal'  => $incomingRaw->sum('quantity'),
            'filterDepartments' => Department::orderBy('name')->get(),
            'filterRawProducts' => Product::whereIn('id',
                    RawMaterialBatch::query()->distinct()->pluck('product_id'))
                ->orderBy('name')->get(),
            'dateFrom'          => $dateFrom,
            'dateTo'            => $dateTo,
        ];
    }

    /**
     * Входящее сырьё за период: первичные поступления (движения 'create'),
     * сгруппированные по продукту (камню). Только 'create', чтобы не задваивать
     * объём дочерними партиями от передач/разделения.
     */
    private function buildIncomingRawSummary(
        ?Carbon $dateFrom,
        ?Carbon $dateTo,
        array $departmentIds = [],
        $rawProductId = null
    ): Collection {
        return RawMaterialMovement::query()
            ->where('movement_type', 'create')
            ->when($dateFrom && $dateTo, fn ($q) => $q->whereBetween('created_at', [$dateFrom, $dateTo]))
            ->when($departmentIds, fn ($q) => $q->whereHas('batch',
                fn ($q2) => $q2->whereIn('department_id', $departmentIds)))
            ->when($rawProductId, fn ($q) => $q->whereHas('batch',
                fn ($q2) => $q2->where('product_id', $rawProductId)))
            ->with('batch.product')
            ->get()
            ->groupBy(fn ($m) => $m->batch?->product_id)
            ->map(function ($movements) {
                $product = $movements->first()?->batch?->product;

                return [
                    'product'  => $product,
                    'uom'      => $product?->uom ?: 'м³',
                    'quantity' => $movements->sum(fn ($m) => (float) $m->quantity),
                ];
            })
            ->filter(fn ($row) => $row['quantity'] > 0)
            ->sortByDesc('quantity')
            ->values();
    }

    private function buildProductSummary(Collection $logs): Collection
    {
        $allItems = $logs->flatMap(fn($log) => $log->items);

        return $allItems
            ->groupBy(function ($logItem) {
                $receptionItem = $logItem->receptionLog?->stoneReception?->items
                    ->firstWhere('product_id', $logItem->product_id);
                $isUndercut = $receptionItem ? (bool) $receptionItem->is_undercut : false;
                $isEdging   = $receptionItem ? (bool) $receptionItem->is_edging   : false;
                return $logItem->product_id . '_' . ($isUndercut ? '1' : '0') . '_' . ($isEdging ? '1' : '0');
            })
            ->map(function ($items) {
                $firstLogItem       = $items->first();
                $product            = $firstLogItem->product;
                $firstReceptionItem = $firstLogItem->receptionLog?->stoneReception?->items
                    ->firstWhere('product_id', $firstLogItem->product_id);
                $isUndercut  = $firstReceptionItem ? (bool) $firstReceptionItem->is_undercut  : false;
                $isEdging    = $firstReceptionItem ? (bool) $firstReceptionItem->is_edging    : false;
                $isSmallTile = $firstReceptionItem ? (bool) $firstReceptionItem->is_small_tile : false;

                $quantity = $items->sum(fn($item) => (float) $item->quantity_delta);

                $pay = $items->sum(function ($logItem) use ($product) {
                    $delta = (float) $logItem->quantity_delta;
                    if (abs($delta) < 0.0001) return 0.0;

                    $receptionItem = $logItem->receptionLog?->stoneReception?->items
                        ->firstWhere('product_id', $logItem->product_id);

                    if ($receptionItem) {
                        return $delta * $receptionItem->effectiveProdCost();
                    }

                    return $product ? $product->calculateWorkerPay($delta) : 0.0;
                });

                $effCoeffDisplay = $items
                    ->map(fn($li) => $li->receptionLog?->stoneReception?->items->firstWhere('product_id', $li->product_id)?->effective_cost_coeff)
                    ->filter()
                    ->avg() ?? $product?->prod_cost_coeff ?? 0;

                $masterPay = $items->sum(function ($logItem) {
                    $delta = (float) $logItem->quantity_delta;
                    if (abs($delta) < 0.0001) return 0.0;
                    $receptionItem = $logItem->receptionLog?->stoneReception?->items
                        ->firstWhere('product_id', $logItem->product_id);
                    return $receptionItem ? $delta * (float) ($receptionItem->master_cost_per_m2 ?? 0) : 0.0;
                });

                return [
                    'product'       => $product,
                    'quantity'      => $quantity,
                    'coeff'         => $effCoeffDisplay,
                    'is_undercut'   => $isUndercut,
                    'is_edging'     => $isEdging,
                    'is_small_tile' => $isSmallTile,
                    'prodCost'      => $items
                        ->map(fn($li) => $li->receptionLog?->stoneReception?->items
                            ->firstWhere('product_id', $li->product_id)?->worker_cost_per_m2)
                        ->filter()
                        ->avg() ?? $product?->prodCost($effCoeffDisplay) ?? 0,
                    'masterCost'    => $items
                        ->map(fn($li) => $li->receptionLog?->stoneReception?->items
                            ->firstWhere('product_id', $li->product_id)?->master_cost_per_m2)
                        ->filter()
                        ->avg() ?? 0,
                    'pay'           => $pay,
                    'masterPay'     => $masterPay,
                ];
            })
            ->filter(fn($row) => abs($row['quantity']) > 0.0001)
            ->sortBy(fn($row) => ($row['product']?->sku ?? '') . '_' . ($row['is_undercut'] ? '1' : '0') . '_' . ($row['is_edging'] ? '1' : '0'))
            ->values();
    }

    /**
     * Сводка производства цеха по продуктам: аналог buildProductSummary,
     * но по дельтам WorkshopLogItem (role=product); стоимости и флаги —
     * из родительских позиций Workshop.items (role=product).
     */
    private function buildWorkshopProductSummary(Collection $logs): Collection
    {
        $allItems = $logs->flatMap(fn($log) => $log->items->where('role', WorkshopItem::ROLE_PRODUCT));

        $findWorkshopItem = fn($logItem) => $logItem->workshopLog?->workshop?->items
            ->first(fn($i) => $i->role === WorkshopItem::ROLE_PRODUCT && $i->product_id === $logItem->product_id);

        return $allItems
            ->groupBy(function ($logItem) use ($findWorkshopItem) {
                $wsItem     = $findWorkshopItem($logItem);
                $isUndercut = $wsItem ? (bool) $wsItem->is_undercut : false;
                $isEdging   = $wsItem ? (bool) $wsItem->is_edging   : false;
                return $logItem->product_id . '_' . ($isUndercut ? '1' : '0') . '_' . ($isEdging ? '1' : '0');
            })
            ->map(function ($items) use ($findWorkshopItem) {
                $firstLogItem = $items->first();
                $product      = $firstLogItem->product;
                $firstWsItem  = $findWorkshopItem($firstLogItem);
                $isUndercut   = $firstWsItem ? (bool) $firstWsItem->is_undercut  : false;
                $isEdging     = $firstWsItem ? (bool) $firstWsItem->is_edging    : false;
                $isSmallTile  = $firstWsItem ? (bool) $firstWsItem->is_small_tile : false;

                $quantity = $items->sum(fn($item) => (float) $item->quantity_delta);

                $pay = $items->sum(function ($logItem) use ($product, $findWorkshopItem) {
                    $delta = (float) $logItem->quantity_delta;
                    if (abs($delta) < 0.0001) return 0.0;

                    $wsItem = $findWorkshopItem($logItem);
                    if ($wsItem) {
                        return $delta * $wsItem->effectiveProdCost();
                    }

                    return $product ? $product->calculateWorkerPay($delta) : 0.0;
                });

                $effCoeffDisplay = $items
                    ->map(fn($li) => $findWorkshopItem($li)?->effective_cost_coeff)
                    ->filter()
                    ->avg() ?? $product?->prod_cost_coeff ?? 0;

                $masterPay = $items->sum(function ($logItem) use ($findWorkshopItem) {
                    $delta = (float) $logItem->quantity_delta;
                    if (abs($delta) < 0.0001) return 0.0;
                    $wsItem = $findWorkshopItem($logItem);
                    return $wsItem ? $delta * (float) ($wsItem->master_cost_per_m2 ?? 0) : 0.0;
                });

                return [
                    'product'       => $product,
                    'quantity'      => $quantity,
                    'coeff'         => $effCoeffDisplay,
                    'is_undercut'   => $isUndercut,
                    'is_edging'     => $isEdging,
                    'is_small_tile' => $isSmallTile,
                    'prodCost'      => $items
                        ->map(fn($li) => $findWorkshopItem($li)?->worker_cost_per_m2)
                        ->filter()
                        ->avg() ?? $product?->prodCost($effCoeffDisplay) ?? 0,
                    'masterCost'    => $items
                        ->map(fn($li) => $findWorkshopItem($li)?->master_cost_per_m2)
                        ->filter()
                        ->avg() ?? 0,
                    'pay'           => $pay,
                    'masterPay'     => $masterPay,
                ];
            })
            ->filter(fn($row) => abs($row['quantity']) > 0.0001)
            ->values();
    }

    /**
     * Слияние двух сводок по продуктам: строки с одним товаром и флагами
     * (is_undercut, is_edging) объединяются, суммы складываются.
     */
    private function mergeProductSummaries(Collection $a, Collection $b): Collection
    {
        return $a->concat($b)
            ->groupBy(fn($row) => ($row['product']?->id ?? 0)
                . '_' . ($row['is_undercut'] ? '1' : '0')
                . '_' . ($row['is_edging'] ? '1' : '0'))
            ->map(function ($rows) {
                if ($rows->count() === 1) {
                    return $rows->first();
                }

                $quantity = $rows->sum('quantity');
                $wavg = fn(string $key) => abs($quantity) > 0.0001
                    ? $rows->sum(fn($r) => $r[$key] * $r['quantity']) / $quantity
                    : $rows->avg($key);

                return [
                    'product'       => $rows->first()['product'],
                    'quantity'      => $quantity,
                    'coeff'         => $wavg('coeff'),
                    'is_undercut'   => $rows->first()['is_undercut'],
                    'is_edging'     => $rows->first()['is_edging'],
                    'is_small_tile' => $rows->contains(fn($r) => $r['is_small_tile']),
                    'prodCost'      => $wavg('prodCost'),
                    'masterCost'    => $wavg('masterCost'),
                    'pay'           => $rows->sum('pay'),
                    'masterPay'     => $rows->sum('masterPay'),
                ];
            })
            ->filter(fn($row) => abs($row['quantity']) > 0.0001)
            ->sortBy(fn($row) => ($row['product']?->sku ?? '') . '_' . ($row['is_undercut'] ? '1' : '0') . '_' . ($row['is_edging'] ? '1' : '0'))
            ->values();
    }
}
