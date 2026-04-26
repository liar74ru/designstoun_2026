<?php

namespace App\Http\Controllers;

use App\Models\ProductStock;
use App\Models\RawMaterialBatch;
use App\Models\Store;
use App\Models\Worker;
use App\Services\Moysklad\MoySkladMoveService;
use App\Services\Moysklad\RawMaterialBatchSyncService;
use App\Services\RawMaterialBatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

# рефакторинг v2 от 26.04.2026  controller -> service -> service/moysklad

class RawMaterialBatchController extends Controller
{
    public function __construct(
        private RawMaterialBatchService $service,
        private RawMaterialBatchSyncService $syncService,
        private MoySkladMoveService $moySkladMoveService,
    ) {}

    public function index(Request $request): View
    {
        return view('raw-batches.index', $this->service->getIndexData($request));
    }

    public function show($id): View
    {
        $batch = RawMaterialBatch::with([
            'product', 'currentStore', 'currentWorker',
            'movements' => fn($q) => $q->with(['fromStore', 'toStore', 'fromWorker', 'toWorker', 'movedBy'])->orderBy('created_at', 'desc'),
            'receptions' => fn($q) => $q->with(['items.product', 'receiver', 'cutter'])->orderBy('created_at', 'desc'),
        ])->findOrFail($id);

        $backUrl = back_url(route('raw-batches.index'));

        return view('raw-batches.show', compact('batch', 'backUrl'));
    }

    public function create(Request $request): View
    {
        $formOptions     = $this->service->getCreateFormOptions();
        $copyProductName = null;

        if ($copyProductId = $request->input('copy_product')) {
            $copyProductName = \App\Models\Product::find($copyProductId)?->name;
        }

        return view('raw-batches.create', array_merge($formOptions, compact('copyProductName')));
    }

    public function nextBatchNumber(Worker $worker): JsonResponse
    {
        return response()->json([
            'batch_number' => $this->service->generateBatchNumber($worker),
        ]);
    }

    public function copy(RawMaterialBatch $batch): RedirectResponse
    {
        $firstMovement = $batch->movements()->orderBy('created_at')->first();

        return redirect()->route('raw-batches.create', [
            'copy_from_store' => $firstMovement?->from_store_id,
            'copy_to_store'   => $batch->current_store_id,
            'copy_worker'     => $batch->current_worker_id,
            'copy_product'    => $batch->product_id,
        ])->with('success', 'Данные скопированы — заполните количество и сохраните');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id'       => 'required|exists:products,id',
            'quantity'         => 'required|numeric|min:0.001',
            'worker_id'        => 'required|exists:workers,id',
            'from_store_id'    => 'required|exists:stores,id',
            'to_store_id'      => 'required|exists:stores,id',
            'batch_number'     => 'nullable|string|max:255',
            'manual_created_at' => 'nullable|date',
        ]);

        if ($data['from_store_id'] === $data['to_store_id']) {
            return back()
                ->withErrors(['to_store_id' => 'Склад-источник и склад-назначение не могут совпадать.'])
                ->withInput();
        }

        $sourceStock = ProductStock::where('product_id', $data['product_id'])
            ->where('store_id', $data['from_store_id'])
            ->first();

        if (!$sourceStock || $sourceStock->quantity < $data['quantity']) {
            return back()
                ->withErrors(['quantity' => 'Недостаточно сырья на складе-источнике.'])
                ->withInput();
        }

        ['batch' => $batch, 'movement' => $movement] = $this->service->create(
            $data,
            auth()->user()?->isAdmin() ?? false
        );

        $this->syncService->syncCreated($batch, $movement);

        if ($request->input('and_reception')) {
            return redirect()->route('stone-receptions.create', [
                'cutter_id'             => $data['worker_id'],
                'raw_material_batch_id' => $batch->id,
            ])->with('success', 'Партия создана. Оформите приёмку.');
        }

        return redirect()->route('raw-batches.index')
            ->with('success', 'Партия сырья успешно создана.');
    }

    public function edit(RawMaterialBatch $batch): View|RedirectResponse
    {
        if (!$batch->canEditDetails()) {
            return redirect()->route('raw-batches.show', $batch)
                ->with('error', 'Редактировать можно только партии в статусе «Новая», «Не уточнена» или «Уточнена».');
        }

        $products = \App\Models\Product::orderBy('name')->get();
        $backUrl  = back_url(route('raw-batches.index'));

        return view('raw-batches.edit', compact('batch', 'products', 'backUrl'));
    }

    public function update(Request $request, RawMaterialBatch $batch): RedirectResponse
    {
        if (!$batch->canEditDetails()) {
            return redirect()->route('raw-batches.show', $batch)
                ->with('error', 'Редактировать можно только партии в статусе «Новая», «Не уточнена» или «Уточнена».');
        }

        $data = $request->validate([
            'product_id'        => 'required|exists:products,id',
            'quantity'          => 'required|numeric|min:0.001',
            'manual_created_at' => 'nullable|date',
        ]);

        $usedQuantity = (float) $batch->initial_quantity - (float) $batch->remaining_quantity;
        $newRemaining = (float) $data['quantity'] - $usedQuantity;

        if ($newRemaining < 0) {
            return back()
                ->withErrors(['quantity' => 'Новое количество (' . number_format((float) $data['quantity'], 3) . ' м³) меньше уже израсходованного (' . number_format($usedQuantity, 3) . ' м³).'])
                ->withInput();
        }

        $result = $this->service->update($batch, $data, auth()->user()?->isAdmin() ?? false);

        if ($result === null) {
            return redirect()->route('raw-batches.show', $batch)
                ->with('info', 'Изменений не обнаружено.');
        }

        $this->syncService->syncEdited($result['batch'], $result['newQuantity'], $result['newCreatedAt']);

        return redirect()->route('raw-batches.show', $batch)
            ->with('success', 'Партия обновлена.');
    }

    public function destroyNew(RawMaterialBatch $batch): RedirectResponse
    {
        if (!$batch->canBeEditedOrDeleted()) {
            return redirect()->route('raw-batches.show', $batch)
                ->with('error', 'Удалить можно только партии в статусе «Новая».');
        }

        $moyskladMoveId = $this->service->deleteNew($batch);

        if ($moyskladMoveId) {
            try {
                $result = $this->moySkladMoveService->deleteMove($moyskladMoveId);
                if (!$result['success']) {
                    Log::warning('Не удалось удалить перемещение в МойСклад при удалении партии', [
                        'move_id' => $moyskladMoveId,
                        'error'   => $result['message'],
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Исключение при удалении перемещения в МойСклад', [
                    'move_id' => $moyskladMoveId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return redirect()->route('raw-batches.index')
            ->with('success', 'Партия удалена.');
    }

    public function adjustForm(RawMaterialBatch $batch): View|RedirectResponse
    {
        if ($batch->status === 'archived') {
            return redirect()->route('raw-batches.show', $batch)
                ->with('error', 'Архивная партия недоступна для редактирования.');
        }

        $stores  = Store::orderBy('name')->get();
        $backUrl = back_url(route('raw-batches.index'));

        return view('raw-batches.adjust', compact('batch', 'stores', 'backUrl'));
    }

    public function adjust(Request $request, RawMaterialBatch $batch): RedirectResponse
    {
        if ($batch->status === 'archived') {
            return back()->with('error', 'Архивная партия недоступна для редактирования.');
        }

        $data = $request->validate([
            'delta' => 'required|numeric|not_in:0',
            'notes' => 'nullable|string|max:500',
        ], [
            'delta.required' => 'Укажите величину изменения',
            'delta.not_in'   => 'Изменение не может быть равно нулю',
        ]);

        $delta        = (float) $data['delta'];
        $newRemaining = (float) $batch->remaining_quantity + $delta;

        if ($newRemaining < 0) {
            return back()
                ->withErrors(['delta' => 'Нельзя убрать больше чем есть в партии (остаток: ' . number_format($batch->remaining_quantity, 3) . ' м³)'])
                ->withInput();
        }

        $result = $this->service->adjust(
            $batch,
            $delta,
            $data['notes'] ?? null,
            auth()->user()?->isAdmin() ?? false,
            $request->input('manual_created_at')
        );

        $this->syncService->syncAdjusted($result['batch'], $result['movement'], $delta, $result['newRemaining']);

        $action = $delta > 0
            ? 'добавлено ' . number_format($delta, 3) . ' м³'
            : 'убрано ' . number_format(abs($delta), 3) . ' м³';

        return redirect()->route('raw-batches.show', $batch)
            ->with('success', "Количество обновлено: {$action}. Новый остаток: " . number_format($result['newRemaining'], 3) . ' м³');
    }

    public function adjustRemainingForm(RawMaterialBatch $batch): View|RedirectResponse
    {
        if ($batch->status === 'archived') {
            return redirect()->route('raw-batches.show', $batch)
                ->with('error', 'Архивная партия недоступна для редактирования.');
        }

        $backUrl = back_url(route('raw-batches.index'));

        return view('raw-batches.adjust-remaining', compact('batch', 'backUrl'));
    }

    public function adjustRemaining(Request $request, RawMaterialBatch $batch): RedirectResponse
    {
        if ($batch->status === 'archived') {
            return back()->with('error', 'Архивная партия недоступна для редактирования.');
        }

        $data = $request->validate([
            'delta' => 'required|numeric|not_in:0',
            'notes' => 'nullable|string|max:500',
        ], [
            'delta.required' => 'Укажите величину изменения',
            'delta.not_in'   => 'Изменение не может быть равно нулю',
        ]);

        $delta        = (float) $data['delta'];
        $newRemaining = (float) $batch->remaining_quantity + $delta;
        $initial      = (float) $batch->initial_quantity;

        if ($newRemaining < 0) {
            return back()
                ->withErrors(['delta' => 'Нельзя убрать больше чем есть в партии (остаток: ' . number_format($batch->remaining_quantity, 3) . ' м³)'])
                ->withInput();
        }

        if ($newRemaining > $initial) {
            return back()
                ->withErrors(['delta' => 'Остаток не может превышать начальное количество партии (' . number_format($initial, 3) . ' м³)'])
                ->withInput();
        }

        $this->service->adjustRemaining($batch, $newRemaining);

        $backUrl = $request->input('back_url', route('raw-batches.show', $batch));
        $action  = $delta > 0
            ? 'добавлено ' . number_format($delta, 3) . ' м³'
            : 'убрано ' . number_format(abs($delta), 3) . ' м³';

        return redirect($backUrl)
            ->with('success', "Остаток скорректирован: {$action}. Новый остаток: " . number_format($newRemaining, 3) . ' м³');
    }

    public function markAsUsed(RawMaterialBatch $batch): JsonResponse|RedirectResponse
    {
        if (!in_array($batch->status, [
            RawMaterialBatch::STATUS_NEW,
            RawMaterialBatch::STATUS_IN_WORK,
            RawMaterialBatch::STATUS_CONFIRMED,
        ])) {
            $msg = 'Перевести в «Израсходована» можно только партии в статусе «Новая», «Не уточнена» или «Уточнена».';
            if (request()->expectsJson()) {
                return response()->json(['error' => $msg], 422);
            }
            return back()->with('error', $msg);
        }

        if ((float) $batch->remaining_quantity > 0) {
            $msg = 'Перевести в «Израсходована» можно только партию с нулевым остатком. Текущий остаток: ' . number_format($batch->remaining_quantity, 3) . ' м³.';
            if (request()->expectsJson()) {
                return response()->json(['error' => $msg], 422);
            }
            return back()->with('error', $msg);
        }

        $this->service->markAsUsed($batch);

        if (request()->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Партия переведена в «Израсходована»']);
        }

        return back()->with('success', 'Партия переведена в статус «Израсходована».');
    }

    public function markAsInWork(RawMaterialBatch $batch): RedirectResponse
    {
        if ($batch->status !== RawMaterialBatch::STATUS_USED) {
            return back()->with('error', 'Вернуть в работу можно только партию со статусом «Израсходована».');
        }

        $this->service->markAsInWork($batch);

        return back()->with('success', 'Партия возвращена в статус «В работе».');
    }

    public function archive(RawMaterialBatch $batch): RedirectResponse
    {
        if ($batch->status === 'archived') {
            return back()->with('error', 'Партия уже в архиве.');
        }

        if (!in_array($batch->status, ['used', 'returned'])) {
            return back()->with('error', 'В архив можно отправить только партии со статусом «Израсходована» или «Возвращена».');
        }

        if ((float) $batch->remaining_quantity > 0) {
            return back()->with('error', 'Нельзя архивировать партию с ненулевым остатком (' . number_format($batch->remaining_quantity, 3) . ' м³). Сначала спишите или верните остаток.');
        }

        $this->service->archive($batch);

        return redirect()->route('raw-batches.show', $batch)
            ->with('success', 'Партия отправлена в архив.');
    }

    public function destroy(RawMaterialBatch $batch): RedirectResponse
    {
        if ($batch->receptions()->exists()) {
            return back()->with('error', 'Нельзя удалить партию, к которой есть приемки.');
        }

        $this->service->delete($batch);

        return redirect()->route('raw-batches.index')->with('success', 'Партия удалена.');
    }

    public function transferForm(RawMaterialBatch $batch): View|RedirectResponse
    {
        if (!$batch->canBeTransferredOrReturned()) {
            return redirect()->route('raw-batches.show', $batch)
                ->with('error', 'Передать можно только новую или уточнённую партию с ненулевым остатком.');
        }

        $workers = Worker::orderBy('name')->get();
        $backUrl = back_url(route('raw-batches.index'));

        return view('raw-batches.transfer', compact('batch', 'workers', 'backUrl'));
    }

    public function transfer(Request $request, RawMaterialBatch $batch): RedirectResponse
    {
        if (!$batch->canBeTransferredOrReturned()) {
            return back()->with('error', 'Передать можно только новую или уточнённую партию с ненулевым остатком.');
        }

        $data = $request->validate([
            'to_worker_id' => 'required|exists:workers,id',
            'quantity'     => 'required|numeric|min:0.001|max:' . $batch->remaining_quantity,
        ]);

        ['newBatch' => $newBatch, 'newMovement' => $newMovement] = $this->service->transfer($batch, $data);

        $this->syncService->syncCreated($newBatch, $newMovement);
        $this->syncService->updateParentMove($batch, (float) $batch->fresh()->initial_quantity);

        return redirect()->route('raw-batches.show', $batch)
            ->with('success', 'Часть партии передана пильщику.');
    }

    public function returnForm(RawMaterialBatch $batch): View|RedirectResponse
    {
        if (!$batch->isWorkable()) {
            return redirect()->route('raw-batches.show', $batch)
                ->with('error', 'Партия уже неактивна.');
        }

        $stores  = Store::orderBy('name')->get();
        $backUrl = back_url(route('raw-batches.index'));

        return view('raw-batches.return', compact('batch', 'stores', 'backUrl'));
    }

    public function return(Request $request, RawMaterialBatch $batch): RedirectResponse
    {
        if (!$batch->canBeTransferredOrReturned()) {
            return back()->withErrors(['batch' => 'Вернуть можно только новую или уточнённую партию с ненулевым остатком.']);
        }

        if (!$batch->current_worker_id) {
            return back()->withErrors(['batch' => 'Партия уже находится на складе.']);
        }

        $data = $request->validate([
            'to_store_id' => 'required|exists:stores,id',
            'quantity'    => 'required|numeric|min:0.001|max:' . $batch->remaining_quantity,
        ]);

        $result = $this->service->returnToStore($batch, $data);

        $this->syncService->syncReturned(
            $result['newBatch'],
            $result['movement'],
            $result['oldStore'],
            $result['toStoreId'],
            $result['qty']
        );
        $this->syncService->updateParentMove($batch);

        return redirect()->route('raw-batches.show', $batch)
            ->with('success', 'Часть партии возвращена на склад.');
    }

    public function syncBatch(RawMaterialBatch $batch): RedirectResponse
    {
        $result = $this->syncService->syncBatchMove($batch);
        $batch->refresh();

        if ($result['success']) {
            return redirect()->back()->with('success', 'Партия синхронизирована с МойСклад.');
        }

        return redirect()->back()->with('error', 'Ошибка синхронизации: ' . $result['message']);
    }
}
