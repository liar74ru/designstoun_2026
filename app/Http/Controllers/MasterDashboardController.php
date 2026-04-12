<?php

namespace App\Http\Controllers;

use App\Models\RawMaterialBatch;
use App\Models\Setting;
use App\Models\StoneReception;
use App\Models\Worker;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MasterDashboardController extends Controller
{
    /**
     * Определяем границы "рабочей недели" (пятница–четверг).
     * Логика идентична WorkerDashboardController.
     */
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

    /**
     * Дашборд мастера: сводка по приёмкам и партиям за период.
     */
    public function show(Request $request, ?int $workerId = null)
    {
        $user = Auth::user();

        if ($workerId && $user->isAdmin()) {
            $worker = Worker::findOrFail($workerId);
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

        // Приёмки, где мастер является приёмщиком (receiver)
        $stoneReceptions = StoneReception::with([
                'items.product',
                'rawMaterialBatch.product',
                'cutter',
                'store',
            ])
            ->where('receiver_id', $worker->id)
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->orderBy('created_at', 'desc')
            ->get();

        // Партии сырья, задействованные в этих приёмках
        $batchIds = $stoneReceptions->pluck('raw_material_batch_id')->filter()->unique();
        $rawBatches = RawMaterialBatch::with(['product', 'currentStore'])
            ->whereIn('id', $batchIds)
            ->orderByRaw("CASE status WHEN 'in_work' THEN 0 WHEN 'new' THEN 1 ELSE 2 END")
            ->orderByDesc('updated_at')
            ->get();

        // Сводка по продуктам: все позиции всех приёмок, сгруппированные по product_id
        $allItems = $stoneReceptions->flatMap(fn($r) => $r->items);
        $summary = $allItems
            ->groupBy('product_id')
            ->map(function ($items) {
                return [
                    'product'  => $items->first()->product,
                    'quantity' => $items->sum(fn($i) => (float) $i->quantity),
                ];
            })
            ->filter(fn($row) => $row['quantity'] > 0.0001)
            ->sortBy(fn($row) => $row['product']?->sku ?? '')
            ->values();

        $totalQuantity = $summary->sum('quantity');

        // Ставки мастера из настроек (для отображения)
        $rates = [
            'base'      => (float) Setting::get('MASTER_BASE_RATE', 100),
            'undercut'  => (float) Setting::get('MASTER_UNDERCUT_RATE', 50),
            'packaging' => (float) Setting::get('MASTER_PACKAGING_RATE', 30),
            'smallTile' => (float) Setting::get('MASTER_SMALL_TILE_RATE', 50),
        ];

        return view('workers.dashboard.master', compact(
            'worker',
            'stoneReceptions',
            'rawBatches',
            'summary',
            'totalQuantity',
            'rates',
            'dateFrom',
            'dateTo',
        ));
    }
}
