<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoneReception\StoreStoneReceptionRequest;
use App\Http\Requests\StoneReception\UpdateStoneReceptionRequest;
use App\Models\RawMaterialBatch;
use App\Models\StoneReception;
use App\Models\Worker;
use App\Services\Moysklad\StoneReceptionSyncService;
use App\Services\StoneReceptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

// рефакторинг v2 от 26.04.2026 — controller → service → service/moysklad

class StoneReceptionController extends Controller
{
    public function __construct(
        private StoneReceptionService $service,
        private StoneReceptionSyncService $syncService,
    ) {}

    public function index(Request $request): View
    {
        $receptions = $this->service->getFilteredReceptions($request);
        [
            'filterRawProducts' => $filterRawProducts,
            'filterProducts'    => $filterProducts,
            'filterCutters'     => $filterCutters,
        ] = $this->service->getFilterData();

        return view('stone-receptions.index', compact(
            'receptions', 'filterRawProducts', 'filterProducts', 'filterCutters'
        ));
    }

    public function logs(Request $request): View
    {
        $logs = $this->service->getFilteredLogs($request);
        [
            'filterRawProducts' => $filterRawProducts,
            'filterProducts'    => $filterProducts,
            'filterCutters'     => $filterCutters,
        ] = $this->service->getFilterData();

        return view('stone-receptions.logs', compact(
            'logs', 'filterRawProducts', 'filterProducts', 'filterCutters'
        ));
    }

    public function create(Request $request): View
    {
        $cutterId = $request->input('cutter_id');
        $batchId  = $request->input('raw_material_batch_id');

        $data                    = $this->service->getFormOptions(null, $cutterId ? (int) $cutterId : null);
        $data['lastReceptions']  = $this->service->getLastReceptions();
        $data['filteredBatches'] = $cutterId ? $this->service->getActiveBatchesForWorker(Worker::find($cutterId)) : collect();
        $data['selectedCutterId'] = $cutterId;
        $data['selectedBatchId']  = $batchId;

        $copyItems = [];
        if ($copyFromId = $request->input('copy_from')) {
            $copyFrom = StoneReception::with('items.product')->find($copyFromId);
            if ($copyFrom) {
                $copyItems = $copyFrom->items->map(fn($item) => [
                    'product_id'    => $item->product_id,
                    'product_label' => $item->product?->name ?? '',
                    'is_undercut'   => (bool) $item->is_undercut,
                ])->toArray();
            }
        }
        $data['copyItems'] = $copyItems;

        return view('stone-receptions.create', $data);
    }

    public function store(StoreStoneReceptionRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if (!$request->input('cutter_id')) {
            return back()->withErrors(['cutter_id' => 'Выберите пильщика'])->withInput();
        }

        $batch = \App\Models\RawMaterialBatch::find($data['raw_material_batch_id']);
        if (!$batch) {
            return back()->withErrors(['raw_material_batch_id' => 'Партия сырья не найдена'])->withInput();
        }
        if ($batch->remaining_quantity < $data['raw_quantity_used']) {
            return back()->withErrors(['raw_quantity_used' => 'Недостаточно сырья'])->withInput();
        }

        try {
            $reception = $this->service->create(
                $data,
                auth()->user()?->isAdmin() ?? false,
                $request->input('processing_name') ?: null
            );

            if ($request->boolean('close_batch')) {
                $batch = $reception->rawMaterialBatch()->first();
                if ($batch && $this->service->closeBatch($batch)) {
                    return redirect()->route('stone-receptions.create', ['cutter_id' => $request->input('cutter_id')])
                        ->with('success', 'Приёмка создана. Партия закрыта.');
                }
            }

            return redirect()->route('stone-receptions.create', ['cutter_id' => $request->input('cutter_id')])
                ->with('success', 'Приемка создана');

        } catch (\Exception $e) {
            Log::error('Ошибка создания приёмки:', ['error' => $e->getMessage(), 'data' => $data]);
            return back()->withErrors(['error' => 'Ошибка: ' . $e->getMessage()])->withInput();
        }
    }

    public function show(StoneReception $stoneReception): View
    {
        $stoneReception->load([
            'receiver',
            'cutter',
            'store',
            'items.product',
            'rawMaterialBatch.product',
            'receptionLogs' => fn($q) => $q->orderBy('created_at', 'asc'),
            'receptionLogs.items.product',
            'receptionLogs.receiver',
            'receptionLogs.cutter',
        ]);

        $backUrl = back_url(route('stone-receptions.index'));

        return view('stone-receptions.show', compact('stoneReception', 'backUrl'));
    }

    public function edit(StoneReception $stoneReception): View
    {
        $data = $this->service->getFormOptions($stoneReception);

        $rawProductId        = $stoneReception->rawMaterialBatch?->product_id;
        $data['lastReceptions'] = $this->service->getLastReceptions(15, $rawProductId);

        return view('stone-receptions.edit', $data);
    }

    public function update(UpdateStoneReceptionRequest $request, StoneReception $stoneReception): RedirectResponse
    {
        $data = $request->validated();
        $data['raw_quantity_delta'] = (float) $request->input('raw_quantity_delta', 0);

        try {
            $this->service->update($stoneReception, $data, auth()->user()?->isAdmin() ?? false);

            if ($request->boolean('close_batch') && $stoneReception->rawMaterialBatch) {
                if ($this->service->closeBatch($stoneReception->rawMaterialBatch)) {
                    return redirect()->route('stone-receptions.index')
                        ->with('success', 'Приёмка обновлена. Партия закрыта.');
                }
            }

            return redirect()->route('stone-receptions.index')->with('success', 'Приемка обновлена');

        } catch (\Exception $e) {
            Log::error('Ошибка обновления приёмки:', ['error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Ошибка: ' . $e->getMessage()])->withInput();
        }
    }

    public function destroy(StoneReception $stoneReception): RedirectResponse
    {
        try {
            $this->service->delete($stoneReception);
            return redirect()->route('stone-receptions.index')->with('success', 'Приемка удалена');
        } catch (\Exception $e) {
            Log::error('Ошибка удаления приёмки:', ['error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Ошибка удаления']);
        }
    }

    public function copy(Request $request, StoneReception $stoneReception): RedirectResponse
    {
        return redirect()->route('stone-receptions.create', [
            'copy_from' => $stoneReception->id,
            'cutter_id' => $stoneReception->cutter_id,
        ]);
    }

    public function syncToProcessing(StoneReception $stoneReception): RedirectResponse
    {
        if (!$stoneReception->store_id) {
            return back()->with('error', 'Склад не указан — синхронизация невозможна.');
        }

        if (!$stoneReception->rawMaterialBatch) {
            return back()->with('error', 'Партия сырья не найдена.');
        }

        $this->syncService->syncReception($stoneReception);
        $stoneReception->refresh();

        if ($stoneReception->isSynced()) {
            return back()->with('success', 'Техоперация синхронизирована с МойСклад.');
        }

        return back()->with('error', 'Ошибка синхронизации: ' . $stoneReception->moysklad_sync_error);
    }

    public function resetStatus(StoneReception $stoneReception): RedirectResponse
    {
        $result = $this->service->resetStatus($stoneReception);

        if ($result !== true) {
            return back()->with('error', $result);
        }

        return back()->with('success', 'Статус сброшен на Активна');
    }

    public function markCompleted(StoneReception $stoneReception): RedirectResponse
    {
        abort_unless($stoneReception->status === StoneReception::STATUS_ACTIVE, 403, 'Завершить можно только активную приёмку');

        $result = $this->service->markCompleted($stoneReception);

        if (!$result['success']) {
            return back()->with('warning', $result['message']);
        }

        return back()->with('success', $result['message']);
    }

    public function updateItemCoeff(Request $request, StoneReception $stoneReception): RedirectResponse
    {
        $validated = $request->validate([
            'items'               => ['required', 'array'],
            'items.*.item_id'     => ['required', 'integer'],
            'items.*.base_coeff'  => ['required', 'numeric'],
            'items.*.is_undercut' => ['nullable', 'boolean'],
        ]);

        $this->service->updateItemCoeff($stoneReception, $validated);

        return back()->with('success', 'Коэффициенты обновлены');
    }

    public function refreshItemCoeffs(StoneReception $stoneReception): RedirectResponse
    {
        $this->service->refreshItemCoeffs($stoneReception);

        return back()->with('success', 'Коэффициенты обновлены из справочника');
    }

    public function getBatchesJson(Worker $worker): JsonResponse
    {
        $batches = $this->service->getActiveBatchesForWorker($worker)->map(fn($b) => [
            'id'                 => $b->id,
            'label'              => $b->product->name
                . ' (ост: ' . number_format($b->remaining_quantity, 2) . ' м³)'
                . ($b->batch_number ? ' №' . $b->batch_number : ''),
            'remaining_quantity' => (float) $b->remaining_quantity,
            'product_sku'        => $b->product->sku ?? '',
            'status'             => $b->status,
            'batch_number'       => $b->batch_number,
        ]);

        return response()->json($batches);
    }

    public function getActiveReceptionByBatchJson(RawMaterialBatch $batch): JsonResponse
    {
        $reception = $this->service->getActiveReceptionByBatch($batch);

        if (!$reception) {
            return response()->json(null);
        }

        return response()->json([
            'reception_id' => $reception->id,
            'edit_url'     => route('stone-receptions.edit', $reception),
        ]);
    }

    public function getReceptionsByBatchJson(RawMaterialBatch $batch): JsonResponse
    {
        $receptions = $this->service->getReceptionsByBatch($batch)
            ->map(fn($r) => [
                'id'                    => $r->id,
                'created_at'            => $r->created_at->format('d.m H:i'),
                'total_quantity'        => number_format($r->items->sum('quantity'), 2),
                'cutter_name'           => $r->cutter?->name,
                'cutter_id'             => $r->cutter_id,
                'raw_material_batch_id' => $r->raw_material_batch_id,
                'items'                 => $r->items->map(fn($i) => [
                    'product_id'    => $i->product_id,
                    'product_name'  => $i->product?->name ?? '—',
                    'product_label' => $i->product?->name ?? '—',
                    'quantity'      => number_format($i->quantity, 2),
                    'is_undercut'   => (bool) $i->is_undercut,
                ]),
            ]);

        return response()->json($receptions);
    }
}
