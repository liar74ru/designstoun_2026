<?php

namespace App\Http\Controllers;

use App\Models\RawMaterialBatch;
use App\Models\Product;
use App\Models\RawMaterialMovement;
use App\Services\MoySkladMoveService;
use App\Services\MoySkladProcessingService;
use App\Models\Store;
use App\Models\Worker;
use App\Models\ProductStock;
use App\Services\ProductGroupService;
use App\Support\DocumentNaming;
use App\Traits\ManagesStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        $baseQuery = RawMaterialBatch::with(['product', 'currentStore', 'currentWorker', 'latestMovement.fromStore', 'latestMovement.toStore']);

        $query = QueryBuilder::for($baseQuery)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('current_worker_id'),
                AllowedFilter::exact('product_id'),
                AllowedFilter::partial('batch_number'),
            ])
            ->defaultSort('-created_at')
            ->allowedSorts(['batch_number', 'created_at', 'quantity']);

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $batches = $query->paginate(15)->withQueryString();

        $filterCutters     = Worker::orderBy('name')->get();
        $filterRawProducts = Product::whereIn('id',
            RawMaterialBatch::distinct()->pluck('product_id')
        )->orderBy('name')->get();

        $statuses = [
            'new'      => 'Новые',
            'in_work'  => 'В работе',
            'used'     => 'Израсходованы',
            'returned' => 'Возвращены',
            'archived' => 'Архив',
        ];

        return view('raw-batches.index', compact('batches', 'filterCutters', 'filterRawProducts', 'statuses'));
    }

    public function show($id)
    {
        $batch = RawMaterialBatch::with([
            'product', 'currentStore', 'currentWorker',
            'movements' => fn($q) => $q->with(['fromStore', 'toStore', 'fromWorker', 'toWorker', 'movedBy'])->orderBy('created_at', 'desc'),
            'receptions' => fn($q) => $q->with(['items.product', 'receiver', 'cutter'])->orderBy('created_at', 'desc'),
        ])->findOrFail($id);

        $backUrl = back_url(route('raw-batches.index'));

        return view('raw-batches.show', compact('batch', 'backUrl'));
    }

    public function create(Request $request)
    {
        $products = Product::orderBy('name')->get();
        $stores   = Store::orderBy('name')->get();
        $workers  = Worker::orderBy('name')->get();

        $copyProductName = null;
        if ($copyProductId = $request->input('copy_product')) {
            $copyProductName = Product::find($copyProductId)?->name;
        }

        $recentBatches = RawMaterialBatch::with([
            'product',
            'currentWorker',
            'movements' => fn($q) => $q->where('movement_type', 'create')->oldest(),
        ])
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        return view('raw-batches.create', compact('products', 'stores', 'workers', 'copyProductName', 'recentBatches'));
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
        $firstMovement = $batch->movements()->orderBy('created_at')->first();

        return redirect()->route('raw-batches.create', [
            'copy_from_store' => $firstMovement?->from_store_id,
            'copy_to_store'   => $batch->current_store_id,
            'copy_worker'     => $batch->current_worker_id,
            'copy_product'    => $batch->product_id,
        ])->with('success', 'Данные скопированы — заполните количество и сохраните');
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

        $batch = null;
        DB::transaction(function () use ($data, $createdAt, &$batch) {
            $batch = RawMaterialBatch::create([
                'product_id'         => $data['product_id'],
                'initial_quantity'   => $data['quantity'],
                'remaining_quantity' => $data['quantity'],
                'current_store_id'   => $data['to_store_id'],
                'current_worker_id'  => $data['worker_id'],
                'batch_number'       => $data['batch_number'] ?? null,
                'status'             => RawMaterialBatch::STATUS_NEW,
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

        if ($request->input('and_reception')) {
            return redirect()->route('stone-receptions.create', [
                'cutter_id'            => $data['worker_id'],
                'raw_material_batch_id' => $batch->id,
            ])->with('success', 'Партия создана. Оформите приёмку.');
        }

        return redirect()->route('raw-batches.index')
            ->with('success', 'Партия сырья успешно создана.');
    }

    /**
     * Форма редактирования "новой" партии.
     * Доступна только если статус = 'new'.
     */
    public function edit(RawMaterialBatch $batch)
    {
        if (!$batch->canBeEditedOrDeleted()) {
            return redirect()->route('raw-batches.show', $batch)
                ->with('error', 'Редактировать можно только партии в статусе «Новая».');
        }

        $products = Product::orderBy('name')->get();
        $backUrl  = back_url(route('raw-batches.index'));

        return view('raw-batches.edit', compact('batch', 'products', 'backUrl'));
    }

    /**
     * Сохранение изменений "новой" партии.
     * Меняем product_id и/или initial_quantity/remaining_quantity.
     * Синхронизируем перемещение в МойСклад (обновляем или пересоздаём).
     */
    public function update(Request $request, RawMaterialBatch $batch)
    {
        if (!$batch->canBeEditedOrDeleted()) {
            return redirect()->route('raw-batches.show', $batch)
                ->with('error', 'Редактировать можно только партии в статусе «Новая».');
        }

        $data = $request->validate([
            'product_id'         => 'required|exists:products,id',
            'quantity'           => 'required|numeric|min:0.001',
            'manual_created_at'  => 'nullable|date',
        ]);

        $oldProductId = $batch->product_id;
        $oldQuantity  = (float) $batch->initial_quantity;
        $newProductId = (int) $data['product_id'];
        $newQuantity  = (float) $data['quantity'];

        $manualDate  = $request->input('manual_created_at');
        $newCreatedAt = (auth()->user()?->isAdmin() && $manualDate)
            ? \Carbon\Carbon::parse($manualDate)
            : null;

        $productChanged  = $oldProductId !== $newProductId;
        $quantityChanged = abs($oldQuantity - $newQuantity) > 0.0001;
        $dateChanged     = $newCreatedAt && $newCreatedAt->ne($batch->created_at);

        if (!$productChanged && !$quantityChanged && !$dateChanged) {
            return redirect()->route('raw-batches.show', $batch)
                ->with('info', 'Изменений не обнаружено.');
        }

        DB::transaction(function () use ($batch, $oldProductId, $oldQuantity, $newProductId, $newQuantity, $productChanged, $quantityChanged, $newCreatedAt) {
            $storeId = $batch->current_store_id;

            // Корректируем product_stocks
            if ($productChanged) {
                $this->adjustStock($oldProductId, $storeId, -$oldQuantity);
                $this->adjustStock($newProductId, $storeId, $newQuantity);
            } elseif ($quantityChanged) {
                $this->adjustStock($oldProductId, $storeId, $newQuantity - $oldQuantity);
            }

            $updateData = [
                'product_id'         => $newProductId,
                'initial_quantity'   => $newQuantity,
                'remaining_quantity' => $newQuantity,
            ];
            if ($newCreatedAt) {
                $updateData['created_at'] = $newCreatedAt;
                // Синхронизируем дату и в таблице движений (первое движение)
                $batch->movements()
                    ->where('movement_type', 'create')
                    ->orderBy('created_at')
                    ->first()
                    ?->update(['created_at' => $newCreatedAt, 'updated_at' => $newCreatedAt]);
            }

            $batch->update($updateData);
        });

        // Синхронизация с МойСклад — вне транзакции
        $this->syncEditedBatchWithMoySklad($batch, $oldProductId, $newProductId, $newQuantity, $newCreatedAt);

        return redirect()->route('raw-batches.show', $batch)
            ->with('success', 'Партия обновлена.');
    }

    /**
     * Синхронизирует изменения партии с МойСклад:
     * если есть исходное перемещение — обновляем его, иначе создаём новое.
     */
    private function syncEditedBatchWithMoySklad(RawMaterialBatch $batch, int $oldProductId, int $newProductId, float $newQuantity, ?\Carbon\Carbon $newCreatedAt = null): void
    {
        $batch->refresh();
        $product = $batch->product;

        if (!$product?->moysklad_id) {
            Log::warning('Продукт партии не синхронизирован с МойСклад при редактировании', [
                'batch_id'   => $batch->id,
                'product_id' => $batch->product_id,
            ]);
            return;
        }

        try {
            $originalMovement = $batch->movements()
                ->where('movement_type', 'create')
                ->whereNotNull('moysklad_move_id')
                ->orderBy('created_at')
                ->first();

            if ($originalMovement) {
                $updateData = [
                    'from_store_id' => $originalMovement->from_store_id,
                    'to_store_id'   => $originalMovement->to_store_id,
                    'products'      => [['product_id' => $product->moysklad_id, 'quantity' => $newQuantity]],
                    'name'          => 'Партия: ' . ($batch->batch_number ?? '№' . $batch->id),
                    'description'   => 'Обновлено через систему. Количество: ' . number_format($newQuantity, 3) . ' м³',
                ];

                if ($newCreatedAt) {
                    $updateData['created_at'] = $newCreatedAt;
                }

                $result = $this->moySkladMoveService->updateMove($originalMovement->moysklad_move_id, $updateData);

                if ($result['success']) {
                    $originalMovement->update(['quantity' => $newQuantity]);
                    Log::info('Перемещение партии обновлено в МойСклад', [
                        'move_id'  => $originalMovement->moysklad_move_id,
                        'batch_id' => $batch->id,
                    ]);
                } else {
                    Log::warning('Ошибка обновления перемещения партии в МойСклад', [
                        'error'    => $result['message'],
                        'batch_id' => $batch->id,
                    ]);
                }
            } else {
                Log::info('Нет синхронизированного перемещения для обновления', ['batch_id' => $batch->id]);
            }
        } catch (\Exception $e) {
            Log::error('Исключение при синхронизации изменений партии с МойСклад', [
                'error'    => $e->getMessage(),
                'batch_id' => $batch->id,
            ]);
        }
    }

    /**
     * Удаление "новой" партии с синхронизацией в МойСклад.
     * Доступно только если статус = 'new'.
     */
    public function destroyNew(RawMaterialBatch $batch)
    {
        if (!$batch->canBeEditedOrDeleted()) {
            return redirect()->route('raw-batches.show', $batch)
                ->with('error', 'Удалить можно только партии в статусе «Новая».');
        }

        // Получаем данные перемещения ДО удаления
        $originalMovement = $batch->movements()
            ->where('movement_type', 'create')
            ->whereNotNull('moysklad_move_id')
            ->orderBy('created_at')
            ->first();

        DB::transaction(function () use ($batch) {
            // Возвращаем сырьё на склад (в product_stocks)
            if ($batch->remaining_quantity > 0) {
                $this->adjustStock($batch->product_id, $batch->current_store_id, -$batch->remaining_quantity);
            }
            $batch->movements()->delete();
            $batch->delete();
        });

        // Удаляем перемещение в МойСклад — вне транзакции
        if ($originalMovement?->moysklad_move_id) {
            try {
                $result = $this->moySkladMoveService->deleteMove($originalMovement->moysklad_move_id);
                if (!$result['success']) {
                    Log::warning('Не удалось удалить перемещение в МойСклад при удалении партии', [
                        'move_id'  => $originalMovement->moysklad_move_id,
                        'error'    => $result['message'],
                        'batch_id' => $batch->id,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Исключение при удалении перемещения в МойСклад', [
                    'move_id' => $originalMovement->moysklad_move_id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return redirect()->route('raw-batches.index')
            ->with('success', 'Партия удалена.');
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

        $stores  = Store::orderBy('name')->get();
        $backUrl = back_url(route('raw-batches.index'));
        return view('raw-batches.adjust', compact('batch', 'stores', 'backUrl'));
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
            // Корректировка только меняет remaining_quantity — статус не трогается.
            // Перевод в 'used' выполняется вручную через markAsUsed().
            $batch->update([
                'remaining_quantity' => $newRemaining,
            ]);

            // Синхронизируем product_stocks
            $this->adjustStock($batch->product_id, $batchStoreId, $delta);

            // Пишем перемещение в историю
            // При добавлении: основной склад → склад партии
            // При убавлении:  склад партии → основной склад
            $envStoreId = env('DEFAULT_STORE_ID') ?: null;
            $defaultStoreId = ($envStoreId && \App\Models\Store::where('id', $envStoreId)->exists())
                ? $envStoreId
                : $batchStoreId;
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
            try {
                $product = $batch->product;

                // Ищем исходное перемещение партии (тип 'create') с moysklad_move_id
                $originalMovement = $batch->movements()
                    ->where('movement_type', 'create')
                    ->whereNotNull('moysklad_move_id')
                    ->orderBy('created_at')
                    ->first();

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
                    // Исходного перемещения в МойСклад нет — корректировку не синхронизируем
                    \Illuminate\Support\Facades\Log::warning('Корректировка без синхронизации МойСклад — исходное перемещение не найдено', [
                        'batch_id' => $batch->id,
                    ]);
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
     * Форма корректировки остатка партии без синхронизации с МойСклад.
     * Логика аналогична изменению raw_quantity_used в приёмке.
     */
    public function adjustRemainingForm(RawMaterialBatch $batch)
    {
        if ($batch->status === 'archived') {
            return redirect()->route('raw-batches.show', $batch)
                ->with('error', 'Архивная партия недоступна для редактирования.');
        }

        $backUrl = back_url(route('raw-batches.index'));
        return view('raw-batches.adjust-remaining', compact('batch', 'backUrl'));
    }

    /**
     * Применяет корректировку остатка партии без синхронизации с МойСклад.
     * Меняет только remaining_quantity — аналогично handleBatchChanges в приёмках.
     */
    public function adjustRemaining(Request $request, RawMaterialBatch $batch)
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

        $batch->update(['remaining_quantity' => $newRemaining]);

        $backUrl = $request->input('back_url', route('raw-batches.show', $batch));
        $action  = $delta > 0 ? 'добавлено ' . number_format($delta, 3) . ' м³' : 'убрано ' . number_format(abs($delta), 3) . ' м³';
        return redirect($backUrl)
            ->with('success', "Остаток скорректирован: {$action}. Новый остаток: " . number_format($newRemaining, 3) . ' м³');
    }

    /**
     * Ручной перевод партии в статус «Израсходована».
     * Доступен только из статусов 'new' и 'in_work'.
     * Каскад: активная приёмка партии → 'completed'.
     */
    public function markAsUsed(RawMaterialBatch $batch)
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

        DB::transaction(function () use ($batch) {
            $batch->update(['status' => RawMaterialBatch::STATUS_USED]);

            // Каскад: активная приёмка → 'completed'
            $batch->receptions()
                ->where('status', \App\Models\StoneReception::STATUS_ACTIVE)
                ->each(fn($r) => $r->update(['status' => \App\Models\StoneReception::STATUS_COMPLETED]));
        });

        if (request()->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Партия переведена в «Израсходована»']);
        }

        return back()->with('success', 'Партия переведена в статус «Израсходована».');
    }

    /**
     * Ручной возврат партии из «Израсходована» в «В работе».
     * Каскад (сценарии а/б/в из плана):
     *   — приёмка 'completed' → 'active'   (продолжаем работу)
     *   — приёмка 'processed' → без изменений (будет создана новая)
     */
    public function markAsInWork(RawMaterialBatch $batch)
    {
        if ($batch->status !== RawMaterialBatch::STATUS_USED) {
            return back()->with('error', 'Вернуть в работу можно только партию со статусом «Израсходована».');
        }

        DB::transaction(function () use ($batch) {
            $batch->update(['status' => RawMaterialBatch::STATUS_IN_WORK]);

            // Каскад: если есть завершённая (но ещё не обработанная) приёмка — возвращаем её в работу
            $batch->receptions()
                ->where('status', \App\Models\StoneReception::STATUS_COMPLETED)
                ->each(fn($r) => $r->update(['status' => \App\Models\StoneReception::STATUS_ACTIVE]));
        });

        return back()->with('success', 'Партия возвращена в статус «В работе».');
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

    public function destroy(RawMaterialBatch $batch)
    {
        if ($batch->receptions()->exists()) {
            return back()->with('error', 'Нельзя удалить партию, к которой есть приемки.');
        }

        DB::transaction(function () use ($batch) {
            if ($batch->isWorkable() && $batch->remaining_quantity > 0) {
                $this->adjustStock($batch->product_id, $batch->current_store_id, -$batch->remaining_quantity);
            }
            $batch->movements()->delete();
            $batch->delete();
        });

        return redirect()->route('raw-batches.index')->with('success', 'Партия удалена.');
    }

    public function transferForm(RawMaterialBatch $batch)
    {
        if (!$batch->canBeTransferredOrReturned()) {
            return redirect()->route('raw-batches.show', $batch)->with('error', 'Передать можно только уточнённую партию с ненулевым остатком.');
        }

        $workers = Worker::orderBy('name')->get();
        $backUrl = back_url(route('raw-batches.index'));
        return view('raw-batches.transfer', compact('batch', 'workers', 'backUrl'));
    }

    public function transfer(Request $request, RawMaterialBatch $batch)
    {
        if (!$batch->canBeTransferredOrReturned()) {
            return back()->with('error', 'Передать можно только уточнённую партию с ненулевым остатком.');
        }

        $data = $request->validate([
            'to_worker_id' => 'required|exists:workers,id',
            'quantity'     => 'required|numeric|min:0.001|max:' . $batch->remaining_quantity,
        ]);

        $qty = (float) $data['quantity'];

        $originalMovement = $batch->movements()
            ->where('movement_type', 'create')
            ->orderBy('created_at')
            ->first();

        $toStoreId   = $originalMovement?->to_store_id   ?? $batch->current_store_id;
        $fromStoreId = $originalMovement?->from_store_id ?? null;

        $targetWorker = Worker::find($data['to_worker_id']);
        $newBatch     = null;
        $newMovement  = null;

        DB::transaction(function () use ($batch, $data, $qty, $toStoreId, $fromStoreId, $targetWorker, &$newBatch, &$newMovement) {
            // 1. Убавить остаток родительской партии
            $newRemaining = (float) $batch->remaining_quantity - $qty;
            $batch->remaining_quantity = $newRemaining;
            $batch->status = $newRemaining > 0
                ? RawMaterialBatch::STATUS_CONFIRMED
                : RawMaterialBatch::STATUS_IN_WORK;
            $batch->save();

            // 2. Создать новую партию для получателя
            $newBatchNumber = ($batch->batch_number ?? $batch->id) . '-' . $targetWorker->name;
            $newBatch = RawMaterialBatch::create([
                'product_id'         => $batch->product_id,
                'initial_quantity'   => $qty,
                'remaining_quantity' => $qty,
                'current_store_id'   => $toStoreId,
                'current_worker_id'  => $data['to_worker_id'],
                'batch_number'       => $newBatchNumber,
                'status'             => RawMaterialBatch::STATUS_NEW,
                'notes'              => 'Передана от партии №' . ($batch->batch_number ?? $batch->id),
            ]);

            // 3. Создать запись движения для новой партии
            $newMovement = RawMaterialMovement::create([
                'batch_id'       => $newBatch->id,
                'from_store_id'  => $fromStoreId,
                'to_store_id'    => $toStoreId,
                'from_worker_id' => $batch->current_worker_id,
                'to_worker_id'   => $data['to_worker_id'],
                'moved_by'       => auth()->user()?->worker_id ?? null,
                'movement_type'  => 'transfer_to_worker',
                'quantity'       => $qty,
            ]);
        });

        // 4. Новое МойСклад-перемещение для новой партии (только если есть оригинальное движение)
        if ($originalMovement) {
            $this->syncTransferMovement($batch, $newBatch, $newMovement, $originalMovement, $qty);

            // 5. Обновить оригинальное МойСклад-перемещение родительской (только если остаток > 0)
            if ((float) $batch->remaining_quantity > 0) {
                $this->updateParentBatchMove($batch);
            }
        } else {
            Log::warning('Передача без синхронизации МойСклад — исходное движение не найдено', ['batch_id' => $batch->id]);
        }

        return redirect()->route('raw-batches.show', $batch)->with('success', 'Часть партии передана пильщику.');
    }

    public function returnForm(RawMaterialBatch $batch)
    {
        if (!$batch->isWorkable()) {
            return redirect()->route('raw-batches.show', $batch)->with('error', 'Партия уже неактивна.');
        }

        $stores  = Store::orderBy('name')->get();
        $backUrl = back_url(route('raw-batches.index'));
        return view('raw-batches.return', compact('batch', 'stores', 'backUrl'));
    }

    private function syncTransferMovement(
        RawMaterialBatch $parentBatch,
        RawMaterialBatch $newBatch,
        RawMaterialMovement $movement,
        RawMaterialMovement $originalMovement,
        float $qty
    ): void {
        try {
            $product = $newBatch->product;
            if (!$product->moysklad_id) {
                Log::warning('Товар не синхронизирован с МойСклад (transfer)', ['product_id' => $product->id]);
                return;
            }

            $parentRef = $parentBatch->batch_number ?? '№' . $parentBatch->id;
            $name = 'Передача от партии: ' . $parentRef;
            $moveData = [
                'from_store_id' => $originalMovement->from_store_id,
                'to_store_id'   => $originalMovement->to_store_id,
                'products'      => [['product_id' => $product->moysklad_id, 'quantity' => $qty]],
                'name'          => $name,
                'description'   => 'Передача части партии другому пильщику',
                'external_id'   => 'movement_' . $movement->id,
            ];

            $result = $this->moySkladMoveService->createMove($moveData);

            if (!$result['success'] && $result['code'] === 'duplicate_name') {
                $moveData['name'] = DocumentNaming::nextSuffix($name);
                $result = $this->moySkladMoveService->createMove($moveData);
            }

            if ($result['success']) {
                $movement->update(['moysklad_move_id' => $result['move_id'], 'moysklad_synced' => true]);
                Log::info('Перемещение (transfer) синхронизировано', ['move_id' => $result['move_id']]);
            } else {
                Log::warning('Ошибка синхронизации передачи партии', ['error' => $result['message']]);
            }
        } catch (\Exception $e) {
            Log::error('Исключение при синхронизации передачи партии', ['error' => $e->getMessage()]);
        }
    }

    private function updateParentBatchMove(RawMaterialBatch $batch): void
    {
        try {
            $product = $batch->product;
            if (!$product->moysklad_id) {
                return;
            }

            $originalMovement = $batch->movements()
                ->where('movement_type', 'create')
                ->whereNotNull('moysklad_move_id')
                ->first();

            if (!$originalMovement) {
                return;
            }

            $result = $this->moySkladMoveService->updateMove($originalMovement->moysklad_move_id, [
                'from_store_id' => $originalMovement->from_store_id,
                'to_store_id'   => $originalMovement->to_store_id,
                'products'      => [['product_id' => $product->moysklad_id, 'quantity' => (float) $batch->remaining_quantity]],
            ]);

            if (!$result['success']) {
                Log::warning('Ошибка обновления перемещения родительской партии', ['error' => $result['message'], 'batch_id' => $batch->id]);
            }
        } catch (\Exception $e) {
            Log::error('Исключение при обновлении перемещения родительской партии', ['error' => $e->getMessage(), 'batch_id' => $batch->id]);
        }
    }

}
