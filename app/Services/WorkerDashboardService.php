<?php

namespace App\Services;

use App\Models\RawMaterialBatch;
use App\Models\ReceptionLog;
use App\Models\Setting;
use App\Models\StoneReception;
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
    public function getEnterpriseDashboardData(Carbon $dateFrom, Carbon $dateTo): array
    {
        $logs = ReceptionLog::with([
                'items.product',
                'stoneReception.items',
                'stoneReception.department',
            ])
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->orderBy('created_at', 'desc')
            ->get();

        // Привязать inverse-отношение log↔items, чтобы buildProductSummary не делал N+1.
        $logs->each(fn($log) => $log->items->each(fn($item) => $item->setRelation('receptionLog', $log)));

        $departments = $logs
            ->groupBy(fn($log) => $log->stoneReception?->department_id)
            ->map(function ($deptLogs) {
                $summary = $this->buildProductSummary($deptLogs);

                return [
                    'department'     => $deptLogs->first()?->stoneReception?->department,
                    'summary'        => $summary,
                    'totalQuantity'  => $summary->sum('quantity'),
                    'totalPay'       => $summary->sum('pay'),
                    'totalMasterPay' => $summary->sum('masterPay'),
                ];
            })
            ->filter(fn($row) => $row['summary']->isNotEmpty())
            ->sortBy(fn($row) => $row['department']?->name ?? "\u{FFFF}")
            ->values();

        return [
            'departments'    => $departments,
            'grandQuantity'  => $departments->sum('totalQuantity'),
            'grandPay'       => $departments->sum('totalPay'),
            'grandMasterPay' => $departments->sum('totalMasterPay'),
            'dateFrom'       => $dateFrom,
            'dateTo'         => $dateTo,
        ];
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
}
