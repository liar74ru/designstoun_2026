<?php

namespace App\Http\Controllers;

use App\Models\RawMaterialBatch;
use App\Models\ReceptionLog;
use App\Models\Setting;
use App\Models\StoneReception;
use App\Models\Worker;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CutterWorkerDashboardController extends Controller
{
    public function showWorker(Request $request, ?int $workerId = null)
    {
        return $this->show($request, $workerId, isMaster: false);
    }

    public function showMaster(Request $request, ?int $workerId = null)
    {
        return $this->show($request, $workerId, isMaster: true);
    }

    private function show(Request $request, ?int $workerId, bool $isMaster)
    {
        $user = Auth::user();

        if ($user->isAdmin() && $workerId) {
            $worker = Worker::findOrFail($workerId);
        } elseif ($user->isMaster() && $workerId) {
            $worker = Worker::findOrFail($workerId);
            $masterDeptId = $user->worker?->department_id;
            if ($masterDeptId && $worker->department_id !== $masterDeptId) {
                abort(403, 'Нет доступа к дашборду этого работника');
            }
        } else {
            abort_unless($user->worker_id, 403, 'Ваш аккаунт не привязан к работнику');
            $worker = $user->worker;
        }

        [$defaultFrom, $defaultTo] = $this->getDefaultWeekRange();

        $dateFrom = $request->filled('date_from')
            ? Carbon::parse($request->input('date_from'))->startOfDay()
            : $defaultFrom;

        $dateTo = $request->filled('date_to')
            ? Carbon::parse($request->input('date_to'))->endOfDay()
            : $defaultTo;

        $workerField = $isMaster ? 'receiver_id' : 'cutter_id';

        $logs = ReceptionLog::with([
                'items.product',
                'stoneReception.store',
                'stoneReception.items',
                'rawMaterialBatch.product',
                $isMaster ? 'cutter' : 'receiver',
            ])
            ->where($workerField, $worker->id)
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->orderBy('created_at', 'desc')
            ->get();

        $receptions = $logs;

        if ($isMaster) {
            $stoneReceptionIds = $logs->pluck('stone_reception_id')->filter()->unique();
            $stoneReceptions = StoneReception::with([
                    'items.product',
                    'rawMaterialBatch.product',
                    'cutter',
                    'store',
                ])
                ->whereIn('id', $stoneReceptionIds)
                ->orderBy('created_at', 'desc')
                ->get();
        } else {
            $stoneReceptions = StoneReception::with([
                    'items.product',
                    'rawMaterialBatch.product',
                    'receiver',
                    'store',
                ])
                ->where('cutter_id', $worker->id)
                ->whereBetween('created_at', [$dateFrom, $dateTo])
                ->orderBy('created_at', 'desc')
                ->get();
        }

        if ($isMaster) {
            $batchIds = $stoneReceptions->pluck('raw_material_batch_id')->filter()->unique();
            $rawBatches = RawMaterialBatch::with(['product', 'currentStore'])
                ->whereIn('id', $batchIds)
                ->orderByRaw("CASE status WHEN 'in_work' THEN 0 WHEN 'new' THEN 1 ELSE 2 END")
                ->orderByDesc('updated_at')
                ->get();
        } else {
            $rawBatches = RawMaterialBatch::with(['product', 'currentStore'])
                ->where(function ($q) use ($worker, $dateFrom, $dateTo) {
                    $q->where(function ($q2) use ($worker) {
                        $q2->whereIn('status', [RawMaterialBatch::STATUS_NEW, RawMaterialBatch::STATUS_IN_WORK])
                            ->where('current_worker_id', $worker->id);
                    })->orWhereHas('receptions', function ($q2) use ($worker, $dateFrom, $dateTo) {
                        $q2->where('cutter_id', $worker->id)
                            ->whereBetween('created_at', [$dateFrom, $dateTo]);
                    });
                })
                ->orderByRaw("CASE status WHEN 'in_work' THEN 0 WHEN 'new' THEN 1 ELSE 2 END")
                ->orderByDesc('updated_at')
                ->get();
        }

        $summary = $this->buildProductSummary($logs);

        $totalPay        = $isMaster ? null : $summary->sum('pay');
        $totalMasterPay  = $isMaster ? $summary->sum('masterPay') : null;
        $rates    = $isMaster ? [
            'base'      => (float) Setting::get('MASTER_BASE_RATE', 100),
            'undercut'  => (float) Setting::get('MASTER_UNDERCUT_RATE', 50),
            'packaging' => (float) Setting::get('MASTER_PACKAGING_RATE', 30),
            'smallTile' => (float) Setting::get('MASTER_SMALL_TILE_RATE', 50),
        ] : null;

        return view('workers.dashboard.show', compact(
            'worker',
            'isMaster',
            'receptions',
            'stoneReceptions',
            'rawBatches',
            'summary',
            'totalPay',
            'totalMasterPay',
            'rates',
            'dateFrom',
            'dateTo',
        ));
    }

    private function getDefaultWeekRange(): array
    {
        $today = Carbon::today();

        $friday = $today->copy()->startOfDay();
        while ($friday->dayOfWeek !== Carbon::FRIDAY) {
            $friday->subDay();
        }

        $thursday = $friday->copy()->addDays(6)->endOfDay();

        return [$friday, $thursday];
    }

    private function buildProductSummary($logs)
    {
        $allItems = $logs->flatMap(fn($log) => $log->items);

        return $allItems
            ->groupBy(function ($logItem) {
                $receptionItem = $logItem->receptionLog?->stoneReception?->items
                    ->firstWhere('product_id', $logItem->product_id);
                $isUndercut = $receptionItem ? (bool) $receptionItem->is_undercut : false;
                return $logItem->product_id . '_' . ($isUndercut ? '1' : '0');
            })
            ->map(function ($items) {
                $firstLogItem       = $items->first();
                $product            = $firstLogItem->product;
                $firstReceptionItem = $firstLogItem->receptionLog?->stoneReception?->items
                    ->firstWhere('product_id', $firstLogItem->product_id);
                $isUndercut  = $firstReceptionItem ? (bool) $firstReceptionItem->is_undercut  : false;
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
                    'product'      => $product,
                    'quantity'     => $quantity,
                    'coeff'        => $effCoeffDisplay,
                    'is_undercut'  => $isUndercut,
                    'is_small_tile' => $isSmallTile,
                    'prodCost'     => $items
                        ->map(fn($li) => $li->receptionLog?->stoneReception?->items
                            ->firstWhere('product_id', $li->product_id)?->worker_cost_per_m2)
                        ->filter()
                        ->avg() ?? $product?->prodCost($effCoeffDisplay) ?? 0,
                    'masterCost'   => $items
                        ->map(fn($li) => $li->receptionLog?->stoneReception?->items
                            ->firstWhere('product_id', $li->product_id)?->master_cost_per_m2)
                        ->filter()
                        ->avg() ?? 0,
                    'pay'          => $pay,
                    'masterPay'    => $masterPay,
                ];
            })
            ->filter(fn($row) => abs($row['quantity']) > 0.0001)
            ->sortBy(fn($row) => ($row['product']?->sku ?? '') . '_' . ($row['is_undercut'] ? '1' : '0'))
            ->values();
    }
}
