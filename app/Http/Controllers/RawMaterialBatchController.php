<?php

namespace App\Http\Controllers;

use App\Models\RawMaterialBatch;
use App\Models\Product;
use App\Models\RawMaterialMovement;
use App\Services\MoySkladMoveService;
use App\Models\Store;
use App\Models\Worker;
use App\Models\ProductStock;
use App\Services\ProductGroupService;
use App\Traits\ManagesStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class RawMaterialBatchController extends Controller
{
    use ManagesStock;

    private ProductGroupService $productGroupService;
    private MoySkladMoveService $moySkladMoveService;

    public function __construct(ProductGroupService $productGroupService, MoySkladMoveService $moySkladMoveService)
    {
        $this->productGroupService = $productGroupService;
        $this->moySkladMoveService = $moySkladMoveService;
    }

    public function index(Request $request)
    {
        $baseQuery = RawMaterialBatch::with(['product', 'currentStore', 'currentWorker']);

        $batches = QueryBuilder::for($baseQuery)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('current_worker_id'),
                AllowedFilter::partial('batch_number'),
                AllowedFilter::exact('product_id'),
                AllowedFilter::callback('group_id', function ($query, $value) {
                    if (!empty($value)) {
                        $groupIds = $this->productGroupService->getGroupAndChildrenIds($value);
                        if (!empty($groupIds)) {
                            $query->whereHas('product', fn($q) => $q->whereIn('group_id', $groupIds));
                        }
                    }
                }),
            ])
            ->defaultSort('-created_at')
            ->allowedSorts(['batch_number', 'created_at', 'quantity'])
            ->paginate(15)
            ->withQueryString();

        $workers   = Worker::orderBy('name')->get();
        $products  = Product::orderBy('name')->get();
        $statuses  = ['active' => 'Активные', 'used' => 'Израсходованы', 'returned' => 'Возвращены', 'archived' => 'Архив'];
        $groupsTree = $this->productGroupService->getGroupsTree();

        return view('raw-batches.index', compact('batches', 'workers', 'products', 'statuses', 'groupsTree'));
    }

    public function show($id)
    {
        $batch = RawMaterialBatch::with([
            'product', 'currentStore', 'currentWorker',
            'movements' => fn($q) => $q->with(['fromStore', 'toStore', 'fromWorker', 'toWorker', 'movedBy'])->orderBy('created_at', 'desc'),
            'receptions' => fn($q) => $q->with(['items.product', 'receiver', 'cutter'])->orderBy('created_at', 'desc'),
        ])->findOrFail($id);

        return view('raw-batches.show', compact('batch'));
    }

    public function create()
    {
        $products = Product::orderBy('name')->get();
        $stores   = Store::orderBy('name')->get();
        $workers  = Worker::orderBy('name')->get();

        return view('raw-batches.create', compact('products', 'stores', 'workers'));
    }

    /**
     * API: следующий номер партии для пильщика на текущей неделе.
     * Формат: ГГ-НН-Фамилия-ПП
     */
    public function nextBatchNumber(Worker $worker)
    {
        return response()->json([
            'batch_number' => $this->generateBatchNumber($worker),
        ]);
    }

    /**
     * Генерируем номер партии.
     * ГГ  — две последние цифры года (26)
     * НН  — номер ISO-недели (01-53)
     * Фамилия — первое слово имени работника
     * ПП  — порядковый номер партий пильщика на этой неделе
     */
    public function generateBatchNumber(Worker $worker): string
    {
        $year   = now()->format('y');
        $week   = now()->format('W');
        $name   = explode(' ', trim($worker->name))[0];

        $count = RawMaterialBatch::where('current_worker_id', $worker->id)
            ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->count();

        return "{$year}-{$week}-{$name}-" . str_pad($count + 1, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Копировать партию — открыть форму создания с предзаполненными данными.
     * Передаём данные через сессию (как в stone-receptions).
     */
    public function copy(RawMaterialBatch $batch)
    {
        session()->put('copy_from', [
            'product_id'    => $batch->product_id,
            'product_name'  => $batch->product->name ?? '',
            'from_store_id' => $batch->movements()->orderBy('created_at')->first()?->from_store_id,
            'to_store_id'   => $batch->current_store_id,
            'worker_id'     => $batch->current_worker_id,
        ]);

        return redirect()->route('raw-batches.create')
            ->with('success', 'Данные скопированы — заполните количество и сохраните');
    }

    /**
     * Создание новой партии + запись перемещения + обновление остатков.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id'    => 'required|exists:products,id',
            'quantity'      => 'required|numeric|min:0.001',
            'worker_id'     => 'required|exists:workers,id',
            'from_store_id' => 'required|exists:stores,id',
            'to_store_id'   => 'required|exists:stores,id',
            'batch_number'  => 'nullable|string|max:255',
        ]);

        // Проверка наличия сырья на складе-источнике
        $sourceStock = ProductStock::where('product_id', $data['product_id'])
            ->where('store_id', $data['from_store_id'])
            ->first();

        if (!$sourceStock || $sourceStock->quantity < $data['quantity']) {
            return back()
                ->withErrors(['quantity' => 'Недостаточно сырья на складе-источнике.'])
                ->withInput();
        }

        $manualDate = $request->input('manual_created_at');
        $createdAt  = (auth()->user()?->isAdmin() && $manualDate)
            ? \Carbon\Carbon::parse($manualDate)
            : now();

        DB::transaction(function () use ($data, $createdAt) {
            $batch = RawMaterialBatch::create([
                'product_id'         => $data['product_id'],
                'initial_quantity'   => $data['quantity'],
                'remaining_quantity' => $data['quantity'],
                'current_store_id'   => $data['to_store_id'],
                'current_worker_id'  => $data['worker_id'],
                'batch_number'       => $data['batch_number'],
                'status'             => 'active',
                'created_at'         => $createdAt,
                'updated_at'         => $createdAt,
            ]);

            RawMaterialMovement::create([
                'batch_id'       => $batch->id,
                'from_store_id'  => $data['from_store_id'],
                'to_store_id'    => $data['to_store_id'],
                'from_worker_id' => null,
                'to_worker_id'   => $data['worker_id'],
                'moved_by'       => auth()->user()?->worker_id ?? null,
                'movement_type'  => 'create',
                'quantity'       => $data['quantity'],
            ]);

            $this->adjustStock($data['product_id'], $data['from_store_id'], -$data['quantity']);
            $this->adjustStock($data['product_id'], $data['to_store_id'],   +$data['quantity']);
        });

        session()->forget('copy_from');

        return redirect()->route('raw-batches.index')
            ->with('success', 'Партия сырья успешно создана.');
    }

    /**
     * Форма корректировки количества сырья в партии (+/-)
     */
    public function adjustForm(RawMaterialBatch $batch)
    {
        if ($batch->status === 'archived') {
            return redirect()->route('raw-batches.show', $batch)
                ->with('error', 'Архивная партия недоступна для редактирования.');
        }

        $stores = Store::orderBy('name')->get();
        return view('raw-batches.adjust', compact('batch', 'stores'));
    }

    /**
     * Применяет корректировку количества сырья.
     * delta > 0 — добавляем сырьё (поступление), delta < 0 — убавляем (списание).
     * Синхронизируется с product_stocks на складе партии.
     */
    public function adjust(Request $request, RawMaterialBatch $batch)
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

        // Запоминаем склад ДО транзакции (после update объект может обновиться)
        $batchStoreId  = $batch->current_store_id;
        $batchProductId = $batch->product_id;

        $movement = null;

        $manualDate = $request->input('manual_created_at');
        $createdAt  = (auth()->user()?->isAdmin() && $manualDate)
            ? \Carbon\Carbon::parse($manualDate)
            : now();

        DB::transaction(function () use ($batch, $delta, $newRemaining, $data, $batchStoreId, $createdAt, &$movement) {
            // Обновляем остаток и статус партии
            $newStatus = $batch->status;
            if ($newRemaining <= 0) {
                $newStatus = 'used';
            } elseif ($batch->status === 'used') {
                $newStatus = 'active';
            }

            $batch->update([
                'remaining_quantity' => $newRemaining,
                'status'             => $newStatus,
            ]);

            // Синхронизируем product_stocks
            $this->adjustStock($batch->product_id, $batchStoreId, $delta);

            // Пишем перемещение в историю
            // При добавлении: основной склад → склад партии
            // При убавлении:  склад партии → основной склад
            $envStoreId = env('DEFAULT_STORE_ID', \App\Http\Controllers\StoneReceptionController::DEFAULT_STORE_ID);
            // Если DEFAULT_STORE_ID не существует в БД — используем склад партии (актуально для тестов)
            $defaultStoreId = \App\Models\Store::where('id', $envStoreId)->exists() ? $envStoreId : $batchStoreId;
            $movement = RawMaterialMovement::create([
                'batch_id'       => $batch->id,
                'from_store_id'  => $delta < 0 ? $batchStoreId    : $defaultStoreId,
                'to_store_id'    => $delta > 0 ? $batchStoreId    : $defaultStoreId,
                'from_worker_id' => null,
                'to_worker_id'   => null,
                'moved_by'       => auth()->user()?->worker_id ?? null,
                'movement_type'  => $delta > 0 ? 'create' : 'use',
                'quantity'       => abs($delta),
                'created_at'     => $createdAt,
                'updated_at'     => $createdAt,
            ]);
        });

        // Синхронизация с МойСклад (вне транзакции — ошибка API не откатывает БД)
        if ($movement && $batch->product?->moysklad_id) {
            $defaultStoreId = env('DEFAULT_STORE_ID', \App\Http\Controllers\StoneReceptionController::DEFAULT_STORE_ID);
            try {
                $product = $batch->product;

                // Ищем исходное перемещение партии (тип 'create') с moysklad_move_id
                $originalMovement = $batch->movements()
                    ->where('movement_type', 'create')
                    ->whereNotNull('moysklad_move_id')
                    ->orderBy('created_at')
                    ->first();

                $moveData = [
                    'from_store_id' => $delta < 0 ? $batchStoreId : $defaultStoreId,
                    'to_store_id'   => $delta > 0 ? $batchStoreId : $defaultStoreId,
                    'products'      => [['product_id' => $product->moysklad_id, 'quantity' => abs($delta)]],
                    'name'          => ($delta > 0 ? 'Пополнение' : 'Списание') . ' партии: ' . ($batch->batch_number ?? '№'.$batch->id),
                    'description'   => 'Корректировка остатка партии через систему. Новый остаток: ' . number_format($newRemaining, 3) . ' м³',
                ];

                if ($originalMovement?->moysklad_move_id) {
                    // Обновляем существующее перемещение в МойСклад.
                    // ВАЖНО: сохраняем from/to склады из исходного перемещения —
                    // нельзя передавать одинаковые склады, МойСклад вернёт 412.
                    // Меняем только количество в позициях (новое суммарное = было + delta).
                    $originalQty = (float) $originalMovement->quantity;
                    $newTotalQty = $originalQty + $delta; // delta может быть отрицательной

                    if ($newTotalQty <= 0) {
                        // Нельзя обнулить перемещение — МойСклад не поддерживает кол-во = 0
                        // Оставляем минимум 0.001 и пишем в лог
                        $newTotalQty = 0.001;
                        \Illuminate\Support\Facades\Log::warning('Корректировка обнулила перемещение — выставлено минимальное значение', [
                            'batch_id'     => $batch->id,
                            'original_qty' => $originalQty,
                            'delta'        => $delta,
                        ]);
                    }

                    $updateData = [
                        // Склады берём из исходного перемещения — не меняем направление
                        'from_store_id' => $originalMovement->from_store_id,
                        'to_store_id'   => $originalMovement->to_store_id,
                        'products'      => [['product_id' => $product->moysklad_id, 'quantity' => $newTotalQty]],
                        'name'          => 'Партия: ' . ($batch->batch_number ?? '№'.$batch->id),
                        'description'   => 'Скорректировано. Новый остаток: ' . number_format($newRemaining, 3) . ' м³',
                    ];

                    $result = $this->moySkladMoveService->updateMove(
                        $originalMovement->moysklad_move_id,
                        $updateData
                    );

                    if ($result['success']) {
                        // Обновляем quantity исходного перемещения в локальной БД
                        $originalMovement->update(['quantity' => $newTotalQty]);
                        $movement->update([
                            'moysklad_move_id' => $originalMovement->moysklad_move_id,
                            'moysklad_synced'  => true,
                        ]);
                        \Illuminate\Support\Facades\Log::info('Перемещение обновлено в МойСклад', [
                            'move_id'     => $originalMovement->moysklad_move_id,
                            'batch_id'    => $batch->id,
                            'old_qty'     => $originalQty,
                            'delta'       => $delta,
                            'new_qty'     => $newTotalQty,
                        ]);
                    } else {
                        \Illuminate\Support\Facades\Log::warning('Ошибка обновления перемещения в МойСклад', [
                            'error'    => $result['message'],
                            'batch_id' => $batch->id,
                        ]);
                    }
                } else {
                    // Исходного перемещения нет — создаём новое
                    $result = $this->moySkladMoveService->createMove($moveData);
                    if ($result['success']) {
                        $movement->update([
                            'moysklad_move_id' => $result['move_id'],
                            'moysklad_synced'  => true,
                        ]);
                    } else {
                        \Illuminate\Support\Facades\Log::warning('Ошибка создания перемещения в МойСклад', [
                            'error'    => $result['message'],
                            'batch_id' => $batch->id,
                        ]);
                    }
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Исключение при синхронизации корректировки с МойСклад', [
                    'error'    => $e->getMessage(),
                    'batch_id' => $batch->id,
                ]);
            }
        }

        $action = $delta > 0 ? 'добавлено ' . number_format($delta, 3) . ' м³' : 'убрано ' . number_format(abs($delta), 3) . ' м³';
        return redirect()->route('raw-batches.show', $batch)
            ->with('success', "Количество обновлено: {$action}. Новый остаток: " . number_format($newRemaining, 3) . ' м³');
    }

    /**
     * Отправка партии в архив.
     * Только для статусов 'used' или 'returned', и только если остаток = 0.
     */
    public function archive(RawMaterialBatch $batch)
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

        $batch->update(['status' => 'archived']);

        return redirect()->route('raw-batches.show', $batch)
            ->with('success', 'Партия отправлена в архив.');
    }

    public function destroy(RawMaterialBatch $raw_batch)
    {
        if ($raw_batch->receptions()->exists()) {
            return back()->with('error', 'Нельзя удалить партию, к которой есть приемки.');
        }

        DB::transaction(function () use ($raw_batch) {
            if ($raw_batch->status === 'active' && $raw_batch->remaining_quantity > 0) {
                $this->adjustStock($raw_batch->product_id, $raw_batch->current_store_id, -$raw_batch->remaining_quantity);
            }
            $raw_batch->movements()->delete();
            $raw_batch->delete();
        });

        return redirect()->route('raw-batches.index')->with('success', 'Партия удалена.');
    }

    public function transferForm(RawMaterialBatch $batch)
    {
        if (!$batch->isActive()) {
            return redirect()->route('raw-batches.show', $batch)->with('error', 'Партия уже неактивна.');
        }

        $workers = Worker::orderBy('name')->get();
        return view('raw-batches.transfer', compact('batch', 'workers'));
    }

    public function transfer(Request $request, RawMaterialBatch $batch)
    {
        if (!$batch->isActive()) {
            return back()->with('error', 'Партия уже неактивна.');
        }

        $data = $request->validate(['to_worker_id' => 'required|exists:workers,id']);

        DB::transaction(function () use ($batch, $data) {
            RawMaterialMovement::create([
                'batch_id'       => $batch->id,
                'from_store_id'  => null,
                'to_store_id'    => null,
                'from_worker_id' => $batch->current_worker_id,
                'to_worker_id'   => $data['to_worker_id'],
                'moved_by'       => auth()->user()?->worker_id ?? null,
                'movement_type'  => 'transfer_to_worker',
                'quantity'       => $batch->remaining_quantity,
            ]);

            $batch->update(['current_worker_id' => $data['to_worker_id']]);
        });

        return redirect()->route('raw-batches.show', $batch)->with('success', 'Партия передана.');
    }

    public function returnForm(RawMaterialBatch $batch)
    {
        if (!$batch->isActive()) {
            return redirect()->route('raw-batches.show', $batch)->with('error', 'Партия уже неактивна.');
        }

        $stores = Store::orderBy('name')->get();
        return view('raw-batches.return', compact('batch', 'stores'));
    }
}
