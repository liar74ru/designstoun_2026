<?php

namespace App\Http\Controllers;

use App\Models\RawMaterialBatch;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use App\Models\Worker;
use App\Models\Product;
use App\Models\Store;
use App\Models\ReceptionLog;
use App\Models\ReceptionLogItem;
use App\Http\Requests\StoneReception\StoreStoneReceptionRequest;
use App\Http\Requests\StoneReception\UpdateStoneReceptionRequest;
use App\Services\MoySkladProcessingService;
use App\Support\DocumentNaming;
use App\Traits\ManagesStock;
use App\Traits\HandlesBatchStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StoneReceptionController extends Controller
{
    // Рефакторинг от 06.04.2026

    use ManagesStock, HandlesBatchStock;

    /**
     * Загружает общие данные для форм
     */
    private function getFormData(?StoneReception $reception = null, $selectedCutterId = null)
    {
        $data = [
            'masterWorkers' => Worker::whereIn('position', ['Мастер', 'Директор', 'Администратор'])->orderBy('name')->get(),
            'workers' => Worker::orderBy('name')->get(),
            'products' => Product::orderBy('name')->get(),
            'stores' => Store::orderBy('name')->get(),
            'defaultStore' => Store::getDefault(),
            'activeBatches' => collect(),
        ];

        if ($reception) {
            $reception->load('items', 'rawMaterialBatch.currentWorker');
            $data['stoneReception'] = $reception;
            $data['activeBatches'] = $this->getBatchesForEdit($reception);
        } elseif ($selectedCutterId) {
            $data['activeBatches'] = $this->getActiveBatches($selectedCutterId);
        }

        return $data;
    }

    /**
     * Получает партии для редактирования с учетом текущей партии
     */
    private function getBatchesForEdit(StoneReception $reception)
    {
        if (!$reception->cutter_id) {
            return collect();
        }

        $batches = $this->getActiveBatches($reception->cutter_id);

        if ($reception->rawMaterialBatch && !$batches->contains('id', $reception->raw_material_batch_id)) {
            $currentBatch = clone $reception->rawMaterialBatch;
            $currentBatch->remaining_quantity += $reception->raw_quantity_used;
            $batches->prepend($currentBatch);
        }

        return $batches;
    }

    /**
     * Получает последние приемки с пагинацией.
     * Если передан $rawMaterialProductId — фильтрует по сырью партии.
     */
    private function getLastReceptions($perPage = 15, ?int $rawMaterialProductId = null)
    {
        $query = StoneReception::with(['receiver', 'cutter', 'store', 'items.product', 'rawMaterialBatch.product'])
            ->orderBy('created_at', 'desc');

        if ($rawMaterialProductId) {
            $query->whereHas('rawMaterialBatch', fn($q) => $q->where('product_id', $rawMaterialProductId));
        }

        return $query->paginate($perPage);
    }

    /**
     * Отображает список приемок
     */
    public function index(Request $request)
    {
        $filterRawProducts = Product::whereIn('id',
            RawMaterialBatch::whereIn('id',
                StoneReception::whereNotNull('raw_material_batch_id')
                    ->distinct()->pluck('raw_material_batch_id')
            )->distinct()->pluck('product_id')
        )->orderBy('name')->get();

        $filterProducts = Product::whereIn('id',
            \App\Models\StoneReceptionItem::distinct()->pluck('product_id')
        )->orderBy('name')->get();

        $filterCutters = Worker::whereIn('id',
            StoneReception::whereNotNull('cutter_id')
                ->distinct()->pluck('cutter_id')
        )->orderBy('name')->get();

        $receptions = QueryBuilder::for(StoneReception::class)
            ->allowedFilters([
                AllowedFilter::callback('status', function ($query, $value) {
                    $query->whereIn('status', is_array($value) ? $value : [$value]);
                }),
                AllowedFilter::callback('sync_status', function ($query, $value) {
                    $query->whereIn('moysklad_sync_status', is_array($value) ? $value : [$value]);
                }),
                AllowedFilter::callback('raw_product_id', function ($query, $value) {
                    $batchIds = RawMaterialBatch::where('product_id', $value)->pluck('id');
                    $query->whereIn('raw_material_batch_id', $batchIds);
                }),
                AllowedFilter::callback('product_id', function ($query, $value) {
                    $query->whereHas('items', fn($q) => $q->where('product_id', $value));
                }),
                AllowedFilter::exact('cutter_id'),
            ])
            ->with(['receiver', 'cutter', 'store', 'items.product', 'rawMaterialBatch.product'])
            ->when($request->filled('date_from'), fn($q) =>
            $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn($q) =>
            $q->whereDate('created_at', '<=', $request->date_to))
            ->when(
                !array_key_exists('status', $request->input('filter', [])),
                fn($q) => $q->whereIn('status', ['active', 'error'])
            )
            ->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        return view('stone-receptions.index', compact(
            'receptions', 'filterRawProducts', 'filterProducts', 'filterCutters'
        ));
    }

    /**
     * Отображает список лога приемок
     */
    public function logs(Request $request)
    {
        // Уникальные типы сырья (продукты), которые встречались в партиях приёмок
        $filterRawProducts = Product::whereIn('id',
            RawMaterialBatch::whereIn('id',
                StoneReception::whereNotNull('raw_material_batch_id')
                    ->distinct()->pluck('raw_material_batch_id')
            )->distinct()->pluck('product_id')
        )->orderBy('name')->get();

        $filterProducts = Product::whereIn('id',
            StoneReceptionItem::distinct()->pluck('product_id')
        )->orderBy('name')->get();

        $filterCutters = Worker::whereIn('id',
            StoneReception::whereNotNull('cutter_id')
                ->distinct()->pluck('cutter_id')
        )->orderBy('name')->get();

        $logs = QueryBuilder::for(ReceptionLog::class)
            ->allowedFilters([
                AllowedFilter::exact('cutter_id'),
                // Фильтр по типу сырья (product_id партии, а не ID самой партии)
                AllowedFilter::callback('raw_material_product_id', function ($query, $value) {
                    $query->whereHas('rawMaterialBatch', fn($q) => $q->where('product_id', $value));
                }),
                AllowedFilter::callback('product_id', function ($query, $value) {
                    $query->whereHas('items', fn($q) => $q->where('product_id', $value));
                }),
            ])
            ->with(['cutter', 'receiver', 'items.product',
                'stoneReception.store', 'rawMaterialBatch.product'])
            ->when($request->filled('date_from'), fn($q) =>
            $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn($q) =>
            $q->whereDate('created_at', '<=', $request->date_to))
            ->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        return view('stone-receptions.logs', compact(
            'logs', 'filterRawProducts', 'filterProducts', 'filterCutters'
        ));
    }

    /**
     * Форма создания приемки
     */
    public function create(Request $request)
    {
        $cutterId = $request->input('cutter_id');
        $batchId = $request->input('raw_material_batch_id');

        $data = $this->getFormData(null, $cutterId);
        $data['lastReceptions'] = $this->getLastReceptions();
        $data['filteredBatches'] = $cutterId ? $this->getActiveBatches($cutterId) : collect();
        $data['selectedCutterId'] = $cutterId;
        $data['selectedBatchId'] = $batchId;

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

    /**
     * Сохраняет приемку
     */
    public function store(StoreStoneReceptionRequest $request)
    {
        $data = $request->validated();

        // Проверки
        if (!$request->input('cutter_id')) {
            return back()->withErrors(['cutter_id' => 'Выберите пильщика'])->withInput();
        }

        $batch = RawMaterialBatch::find($data['raw_material_batch_id']);
        if (!$batch) {
            return back()->withErrors(['raw_material_batch_id' => 'Партия сырья не найдена'])->withInput();
        }

        if ($batch->remaining_quantity < $data['raw_quantity_used']) {
            return back()->withErrors(['raw_quantity_used' => 'Недостаточно сырья'])->withInput();
        }

        try {
            // Автоматически завершаем существующую активную приёмку для этой партии
            $existingActive = $batch->getActiveReception();
            if ($existingActive) {
                $existingActive->markAsCompleted();
            }

            $batchSnapshotBefore = (float) $batch->remaining_quantity;
            $createdReception    = null;

            $processingName = $request->input('processing_name') ?: null;

            DB::transaction(function () use ($data, $request, $batchSnapshotBefore, &$createdReception) {
                $manualDate = $request->input('manual_created_at');
                if (auth()->user()?->isAdmin() && $manualDate) {
                    $data['manual_created_at'] = \Carbon\Carbon::parse($manualDate);
                }
                $reception = StoneReception::create($this->prepareReceptionData($data));
                $this->createReceptionItems($reception, $data['products']);

                // Пишем лог создания — перезагружаем items чтобы они точно были в коллекции
                $reception->load('items');
                $itemDeltas = $reception->items->mapWithKeys(fn($i) => [$i->product_id => (float) $i->quantity])->toArray();
                $this->writeReceptionLog($reception, ReceptionLog::TYPE_CREATED, (float) $reception->raw_quantity_used, $batchSnapshotBefore, $itemDeltas, $reception->created_at, $reception->receiver_id);
                $createdReception = $reception;
            });

            $batch->refresh();

            // Синхронизация с МойСклад (не блокирует сохранение при ошибке)
            if ($createdReception) {
                $this->syncReceptionProcessing($createdReception, $processingName);
            }

            if ($request->boolean('close_batch') && $this->closeBatch($batch)) {
                return redirect()->route('stone-receptions.create', ['cutter_id' => $request->input('cutter_id')])
                    ->with('success', 'Приёмка создана. Партия закрыта.');
            }

            return redirect()->route('stone-receptions.create', ['cutter_id' => $request->input('cutter_id')])
                ->with('success', 'Приемка создана');

        } catch (\Exception $e) {
            Log::error('Ошибка:', ['error' => $e->getMessage(), 'data' => $data]);
            return back()->withErrors(['error' => 'Ошибка: ' . $e->getMessage()])->withInput();
        }
    }

    /**
     * Детальная страница приемки
     */
    public function show(StoneReception $stoneReception)
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

    /**
     * Форма редактирования
     */
    public function edit(StoneReception $stoneReception)
    {
        $data = $this->getFormData($stoneReception);

        $rawProductId = $stoneReception->rawMaterialBatch?->product_id;
        $data['lastReceptions'] = $this->getLastReceptions(15, $rawProductId);

        return view('stone-receptions.edit', $data);
    }

    /**
     * Обновляет приемку
     */
    public function update(UpdateStoneReceptionRequest $request, StoneReception $stoneReception)
    {
        $data = $request->validated();
        $rawDelta = (float) $request->input('raw_quantity_delta', 0);

        // Передаём manual_created_at и оригинальную дату для prepareReceptionData
        $manualDate = $request->input('manual_created_at');
        if (auth()->user()?->isAdmin() && $manualDate) {
            $data['manual_created_at'] = \Carbon\Carbon::parse($manualDate);
        }
        $data['original_created_at'] = $stoneReception->created_at;

        try {
            $batchSnapshotBefore = $stoneReception->rawMaterialBatch
                ? (float) $stoneReception->rawMaterialBatch->remaining_quantity
                : null;

            DB::transaction(function () use ($stoneReception, $data, $rawDelta, $batchSnapshotBefore) {

                // Запоминаем старые значения продуктов ДО сохранения
                $preSaveItems = $stoneReception->items()
                    ->get()
                    ->pluck('quantity', 'product_id')
                    ->map(fn($q) => (float) $q)
                    ->toArray();

                // Обновляем партию сырья и саму приёмку
                $this->handleBatchChanges($stoneReception, $data);
                $stoneReception->update($this->prepareReceptionData($data, false));
                $this->updateReceptionItems($stoneReception, $data['products']);

                // Считаем дельты продуктов: новое (из формы) минус старое (из preSaveItems)
                $deltas = [];

                foreach ($data['products'] as $p) {
                    $productId = $p['product_id'];
                    $newQty    = (float) $p['quantity'];
                    $oldQty    = $preSaveItems[$productId] ?? 0.0;
                    $delta     = $newQty - $oldQty;
                    if (abs($delta) > 0.0001) {
                        $deltas[$productId] = $delta;
                    }
                }

                // Продукты которые были, но теперь удалены (не пришли в форме)
                $newProductIds = array_column($data['products'], 'product_id');
                foreach ($preSaveItems as $productId => $oldQty) {
                    if (!in_array($productId, $newProductIds) && abs($oldQty) > 0.0001) {
                        $deltas[$productId] = -$oldQty;
                    }
                }

                // Пишем лог только если реально что-то изменилось
                if (empty($deltas) && abs($rawDelta) < 0.0001) {
                    return;
                }
                $dataLog = $data['manual_created_at'] ?? Now();
                $this->writeReceptionLog($stoneReception, ReceptionLog::TYPE_UPDATED, $rawDelta, $batchSnapshotBefore, $deltas, $dataLog, $data['receiver_id'] ?? null);
            });

            // Синхронизация с МойСклад (не блокирует сохранение при ошибке)
            $stoneReception->refresh();
            $this->syncReceptionProcessing($stoneReception);

            if ($request->boolean('close_batch') && $stoneReception->rawMaterialBatch) {
                if ($this->closeBatch($stoneReception->rawMaterialBatch)) {
                    return redirect()->route('stone-receptions.index')
                        ->with('success', 'Приёмка обновлена. Партия закрыта.');
                }
            }

            return redirect()->route('stone-receptions.index')->with('success', 'Приемка обновлена');

        } catch (\Exception $e) {
            Log::error('Ошибка обновления:', ['error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Ошибка: ' . $e->getMessage()])->withInput();
        }
    }


    /**
     * Создаёт запись ReceptionLog + ReceptionLogItem для одного события (создание или обновление).
     *
     * @param array<int, float> $itemDeltas  [product_id => quantity_delta]
     */
    private function writeReceptionLog(
        StoneReception $reception,
        string $type,
        float $rawDelta,
        ?float $batchSnapshot,
        array $itemDeltas,
        ?\DateTimeInterface $createdAt = null,
        ?int $receiverId = null
    ): void {
        $log = ReceptionLog::create([
            'stone_reception_id'    => $reception->id,
            'raw_material_batch_id' => $reception->raw_material_batch_id,
            'cutter_id'             => $reception->cutter_id,
            'receiver_id'           => $receiverId ?? auth()->user()->worker_id,
            'type'                  => $type,
            'raw_quantity_delta'    => $rawDelta,
            'raw_quantity_snapshot' => $batchSnapshot,
            'created_at'            => $createdAt ?? now(),
        ]);

        if ($itemDeltas) {
            $now = now();
            ReceptionLogItem::insert(collect($itemDeltas)->map(fn($delta, $productId) => [
                'reception_log_id' => $log->id,
                'product_id'       => $productId,
                'quantity_delta'   => $delta,
                'created_at'       => $now,
                'updated_at'       => $now,
            ])->values()->toArray());
        }
    }

    /**
     * Закрывает партию и завершает все активные приёмки по ней.
     * Возвращает true если партия была закрыта, false если уже в неподходящем статусе.
     */
    private function closeBatch(RawMaterialBatch $batch): bool
    {
        if (!in_array($batch->status, [
            RawMaterialBatch::STATUS_NEW,
            RawMaterialBatch::STATUS_IN_WORK,
            RawMaterialBatch::STATUS_CONFIRMED,
        ])) {
            return false;
        }

        $activeReceptions = $batch->receptions()
            ->where('status', StoneReception::STATUS_ACTIVE)
            ->get();

        DB::transaction(function () use ($batch) {
            $newStatus = (float) $batch->remaining_quantity <= 0
                ? RawMaterialBatch::STATUS_USED
                : RawMaterialBatch::STATUS_CONFIRMED;
            $batch->update(['status' => $newStatus]);
            $batch->receptions()->where('status', StoneReception::STATUS_ACTIVE)
                ->each(fn($r) => $r->update(['status' => StoneReception::STATUS_COMPLETED]));
        });

        $service = app(MoySkladProcessingService::class);
        foreach ($activeReceptions as $reception) {
            $reception->refresh();
            if ($reception->hasMoySkladProcessing()) {
                $result = $service->completeProcessing($reception->moysklad_processing_id);
                if ($result['success']) {
                    $reception->markSynced($reception->moysklad_processing_id);
                } else {
                    $reception->markSyncError($result['message']);
                    Log::warning('closeBatch: не удалось завершить техоперацию', [
                        'reception_id' => $reception->id,
                        'error'        => $result['message'],
                    ]);
                }
            }
        }

        return true;
    }

    /**
     * Синхронизировать приёмку с МойСклад: создать техоперацию (первая синхронизация)
     * или обновить продукты/материалы (повторная синхронизация).
     *
     * Не бросает исключение — результат записывается в поля приёмки.
     */
    private function syncReceptionProcessing(StoneReception $reception, ?string $customName = null): void
    {
        $batch = $reception->rawMaterialBatch;
        if (!$batch) {
            return;
        }

        /** @var MoySkladProcessingService $service */
        $service = app(MoySkladProcessingService::class);

        try {
            if (!$reception->hasMoySkladProcessing()) {
                // Техоперации у приёмки нет — создаём новую
                $reception->loadMissing('items.product', 'rawMaterialBatch.product');
                $result = $service->createProcessingForReception($reception, $customName);

                if ($result['success']) {
                    $reception->markSynced($result['processing_id'], $result['processing_name']);
                } else {
                    $reception->markSyncError($result['message']);
                    Log::warning('syncReceptionProcessing: не удалось создать техоперацию', [
                        'reception_id' => $reception->id,
                        'message'      => $result['message'],
                    ]);
                }
            } else {
                // Техоперация уже есть — обновляем продукты и материал
                $reception->loadMissing('items.product', 'rawMaterialBatch.product');
                $result = $service->updateProcessingProducts(
                    $reception->moysklad_processing_id,
                    $reception->items,
                    $reception->store_id ?? '',
                    (float) $reception->raw_quantity_used,
                    $batch->product->moysklad_id ?? ''
                );

                if ($result['success']) {
                    $reception->markSynced($reception->moysklad_processing_id);
                } else {
                    $reception->markSyncError($result['message']);
                    Log::warning('syncReceptionProcessing: не удалось обновить техоперацию', [
                        'reception_id'  => $reception->id,
                        'processing_id' => $reception->moysklad_processing_id,
                        'message'       => $result['message'],
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('syncReceptionProcessing: исключение', [
                'reception_id' => $reception->id,
                'error'        => $e->getMessage(),
            ]);
            $reception->markSyncError('Ошибка: ' . $e->getMessage());
        }
    }

    /**
     * Ручная синхронизация приёмки с МойСклад.
     */
    public function syncToProcessing(StoneReception $stoneReception)
    {
        if (!$stoneReception->store_id) {
            return back()->with('error', 'Склад не указан — синхронизация невозможна.');
        }

        if (!$stoneReception->rawMaterialBatch) {
            return back()->with('error', 'Партия сырья не найдена.');
        }

        $this->syncReceptionProcessing($stoneReception);
        $stoneReception->refresh();

        if ($stoneReception->isSynced()) {
            return back()->with('success', 'Техоперация синхронизирована с МойСклад.');
        }

        return back()->with('error', 'Ошибка синхронизации: ' . $stoneReception->moysklad_sync_error);
    }

    /**
     * Удаляет приемку
     */
    public function destroy(StoneReception $stoneReception)
    {
        $batch = $stoneReception->rawMaterialBatch;

        try {
            DB::transaction(fn() => $stoneReception->delete());

            return redirect()->route('stone-receptions.index')->with('success', 'Приемка удалена');
        } catch (\Exception $e) {
            Log::error('Ошибка удаления:', ['error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Ошибка удаления']);
        }
    }

    /**
     * Копирует приемку
     */
    public function copy(Request $request, StoneReception $stoneReception)
    {
        return redirect()->route('stone-receptions.create', [
            'copy_from' => $stoneReception->id,
            'cutter_id' => $stoneReception->cutter_id,
        ]);
    }

    /**
     * Подготавливает данные приемки
     */
    private function prepareReceptionData(array $data, bool $forCreate = true, bool $forCopy = false): array
    {
        $prepared = [
            'cutter_id' => $data['cutter_id'] ?? null,
            'store_id' => $data['store_id'],
            'raw_material_batch_id' => $data['raw_material_batch_id'],
            'raw_quantity_used' => $data['raw_quantity_used'],
            'notes' => $data['notes'] ?? null,
        ];

        if ($forCreate) {
            $prepared['receiver_id'] = $data['receiver_id'];
        }

        if (!$forCopy) {
            if ($forCreate) {
                $prepared['created_at'] = $data['manual_created_at'] ?? now();
                $prepared['updated_at'] = $data['manual_created_at'] ?? now();
            } else {
                $prepared['created_at'] = $data['manual_created_at'] ?? $data['original_created_at'];
                $prepared['updated_at'] = now();
            }
        }

        return $prepared;
    }

    /**
     * Создает позиции продуктов.
     * Фиксирует effective_cost_coeff на момент создания приёмки —
     * последующие изменения справочника продуктов не затронут эту приёмку.
     */
    private function createReceptionItems(StoneReception $reception, array $products): void
    {
        $productIds = array_column($products, 'product_id');
        $productMap = Product::whereIn('id', $productIds)->get()->keyBy('id');

        foreach ($products as $product) {
            $prod        = $productMap->get($product['product_id']);
            $baseCoeff   = (float) ($prod?->prod_cost_coeff ?? 0);
            $isUndercut  = !empty($product['is_undercut']);
            $isSmallTile = StoneReceptionItem::skuIsSmallTile($prod?->sku);
            $effCoeff    = StoneReceptionItem::computeEffectiveCoeff($baseCoeff, $isUndercut);

            $reception->items()->create([
                'product_id'           => $product['product_id'],
                'quantity'             => $product['quantity'],
                'effective_cost_coeff' => $effCoeff,
                'is_undercut'          => $isUndercut,
                'is_small_tile'        => $isSmallTile,
                'worker_cost_per_m2'   => $prod?->prodCost($effCoeff),
                'master_cost_per_m2'   => StoneReceptionItem::computeMasterCost($isUndercut, $isSmallTile),
            ]);
        }

        $this->syncBatchProcessingSum($reception);
    }

    /**
     * Обновляет позиции продуктов.
     *
     * Для СУЩЕСТВУЮЩИХ позиций — количество обновляется, но effective_cost_coeff
     * и is_undercut НЕ трогаются (история зафиксирована).
     * Для НОВЫХ позиций — фиксируем коэффициент на текущий момент.
     */
    private function updateReceptionItems(StoneReception $reception, array $products): void
    {
        // Продукты с нулевым количеством считаются удалёнными
        $products = array_values(array_filter($products, fn($p) => (float)($p['quantity'] ?? 0) > 0));

        $existingItems = $reception->items()->get()->keyBy('product_id');
        $newProductIds = array_column($products, 'product_id');

        // Загружаем только новые продукты (которых нет среди существующих позиций)
        $newIds     = array_diff($newProductIds, $existingItems->keys()->toArray());
        $productMap = $newIds
            ? Product::whereIn('id', $newIds)->get()->keyBy('id')
            : collect();

        foreach ($products as $product) {
            $productId = $product['product_id'];

            if ($existingItems->has($productId)) {
                // Существующая позиция — обновляем только quantity
                $existingItems[$productId]->update(['quantity' => $product['quantity']]);
            } else {
                // Новая позиция — фиксируем коэффициент на текущий момент
                $prod        = $productMap->get($productId);
                $baseCoeff   = (float) ($prod?->prod_cost_coeff ?? 0);
                $isUndercut  = !empty($product['is_undercut']);
                $isSmallTile = StoneReceptionItem::skuIsSmallTile($prod?->sku);
                $effCoeff    = StoneReceptionItem::computeEffectiveCoeff($baseCoeff, $isUndercut);

                $reception->items()->create([
                    'product_id'           => $productId,
                    'quantity'             => $product['quantity'],
                    'effective_cost_coeff' => $effCoeff,
                    'is_undercut'          => $isUndercut,
                    'is_small_tile'        => $isSmallTile,
                    'worker_cost_per_m2'   => $prod?->prodCost($effCoeff),
                    'master_cost_per_m2'   => StoneReceptionItem::computeMasterCost($isUndercut, $isSmallTile),
                ]);
            }
        }

        // Удаляем позиции которых нет в новых данных
        $reception->items()->whereNotIn('product_id', $newProductIds)->delete();

        $this->syncBatchProcessingSum($reception);
    }

    /**
     * Inline-обновление effective_cost_coeff и is_undercut позиции из страницы show.
     * Позволяет скорректировать коэффициент постфактум (например, если он был
     * неверно задан в справочнике продуктов на момент приёмки).
     */
    public function updateItemCoeff(Request $request, StoneReception $stoneReception)
    {
        $validated = $request->validate([
            'items'              => ['required', 'array'],
            'items.*.item_id'    => ['required', 'integer'],
            'items.*.base_coeff' => ['required', 'numeric'],
            'items.*.is_undercut'=> ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($stoneReception, $validated) {
            foreach ($validated['items'] as $row) {
                $item = $stoneReception->items()->with('product')->findOrFail($row['item_id']);

                $isUndercut  = !empty($row['is_undercut']);
                $baseCoeff   = (float) $row['base_coeff'];
                $isSmallTile = StoneReceptionItem::skuIsSmallTile($item->product?->sku);
                $effCoeff    = StoneReceptionItem::computeEffectiveCoeff($baseCoeff, $isUndercut);

                $item->update([
                    'effective_cost_coeff' => $effCoeff,
                    'is_undercut'          => $isUndercut,
                    'is_small_tile'        => $isSmallTile,
                    'worker_cost_per_m2'   => $item->product?->prodCost($effCoeff),
                    'master_cost_per_m2'   => StoneReceptionItem::computeMasterCost($isUndercut, $isSmallTile),
                ]);
            }

            $this->syncBatchProcessingSum($stoneReception);
        });

        return back()->with('success', 'Коэффициенты обновлены');
    }

    /**
     * Обновляет effective_cost_coeff позиций приёмки из текущего справочника товаров (prod_cost_coeff).
     * Флаг is_undercut при этом сохраняется, коэффициент пересчитывается.
     */
    public function refreshItemCoeffs(StoneReception $stoneReception)
    {
        $stoneReception->loadMissing('items.product');

        DB::transaction(function () use ($stoneReception) {
            foreach ($stoneReception->items as $item) {
                if (!$item->product || $item->product->prod_cost_coeff === null) {
                    continue;
                }

                $baseCoeff   = (float) $item->product->prod_cost_coeff;
                $isUndercut  = (bool) $item->is_undercut;
                $isSmallTile = StoneReceptionItem::skuIsSmallTile($item->product->sku);
                $effCoeff    = StoneReceptionItem::computeEffectiveCoeff($baseCoeff, $isUndercut);

                $item->update([
                    'effective_cost_coeff' => $effCoeff,
                    'is_small_tile'        => $isSmallTile,
                    'worker_cost_per_m2'   => $item->product->prodCost($effCoeff),
                    'master_cost_per_m2'   => StoneReceptionItem::computeMasterCost($isUndercut, $isSmallTile),
                ]);
            }

            $this->syncBatchProcessingSum($stoneReception);
        });

        return back()->with('success', "Коэффициенты обновлены из справочника");
    }

    /**
     * Сбрасывает статус приемки
     */
    public function resetStatus(StoneReception $stoneReception)
    {
        // Блокируем активацию если для этой партии есть более новая приёмка
        if ($stoneReception->raw_material_batch_id) {
            $newerExists = StoneReception::where('raw_material_batch_id', $stoneReception->raw_material_batch_id)
                ->where('id', '!=', $stoneReception->id)
                ->where('id', '>', $stoneReception->id)
                ->whereNotIn('status', [StoneReception::STATUS_ERROR])
                ->exists();

            if ($newerExists) {
                return back()->with('error', 'Невозможно активировать приёмку: для этой партии сырья есть более новая приёмка');
            }
        }

        DB::transaction(function () use ($stoneReception) {
            $stoneReception->update([
                'status'                   => StoneReception::STATUS_ACTIVE,
                'moysklad_processing_id'   => null,
                'moysklad_processing_name' => null,
                'moysklad_sync_status'     => null,
                'moysklad_sync_error'      => null,
                'synced_at'                => null,
            ]);

            // Если партия была переведена в «Израсходована» — возвращаем в «В работе»
            $batch = $stoneReception->rawMaterialBatch;
            if ($batch && $batch->status === RawMaterialBatch::STATUS_USED) {
                $batch->update(['status' => RawMaterialBatch::STATUS_IN_WORK]);
            }
        });

        return back()->with('success', 'Статус сброшен на Активна');
    }

    public function markCompleted(StoneReception $stoneReception)
    {
        abort_unless($stoneReception->status === StoneReception::STATUS_ACTIVE, 403, 'Завершить можно только активную приёмку');

        // Ставим финальный статус локально всегда
        DB::transaction(function () use ($stoneReception) {
            $stoneReception->markAsCompleted();

            // Автоматически обновляем статус партии
            $batch = $stoneReception->rawMaterialBatch;
            if ($batch) {
                $newStatus = (float) $batch->remaining_quantity <= 0
                    ? RawMaterialBatch::STATUS_USED
                    : RawMaterialBatch::STATUS_CONFIRMED;
                $batch->update(['status' => $newStatus]);
            }
        });

        // Завершаем техоперацию в МойСклад (не блокирует)
        $stoneReception->refresh();
        if ($stoneReception->hasMoySkladProcessing()) {
            $result = app(MoySkladProcessingService::class)
                ->completeProcessing($stoneReception->moysklad_processing_id);

            if ($result['success']) {
                $stoneReception->markSynced($stoneReception->moysklad_processing_id);
                return back()->with('success', 'Приёмка завершена.');
            } else {
                $stoneReception->markSyncError($result['message']);
                return back()->with('warning',
                    'Приёмка завершена локально, но ошибка синхронизации с МойСклад: ' . $result['message']);
            }
        }

        return back()->with('success', 'Приёмка завершена.');
    }

    /**
     * AJAX: партии сырья для пильщика (используется в форме приёмки без перезагрузки страницы)
     */
    public function getBatchesJson(\App\Models\Worker $worker)
    {
        $batches = $this->getActiveBatches($worker->id)->map(fn($b) => [
            'id'                 => $b->id,
            'label'              => $b->product->name
                . ' (ост: ' . number_format($b->remaining_quantity, 2) . ' м³)'
                . ($b->batch_number ? ' №' . $b->batch_number : ''),
            'remaining_quantity' => (float) $b->remaining_quantity,
            'product_sku'        => $b->product->sku ?? '',
            'status'             => $b->status,
        ]);

        return response()->json($batches);
    }

    /**
     * AJAX: возвращает активную приёмку партии (статус 'active'), если есть.
     * Используется в create.blade.php для редиректа вместо создания новой приёмки.
     */
    public function getActiveReceptionByBatchJson(\App\Models\RawMaterialBatch $batch)
    {
        $reception = $batch->getActiveReception();

        if (!$reception) {
            return response()->json(null);
        }

        return response()->json([
            'reception_id' => $reception->id,
            'edit_url'     => route('stone-receptions.edit', $reception),
        ]);
    }

    /**
     * AJAX: последние приёмки в которых использовалось то же сырьё (по product_id партии)
     */
    public function getReceptionsByBatchJson(\App\Models\RawMaterialBatch $batch)
    {
        // Находим все партии с тем же сырьём
        $batchIds = \App\Models\RawMaterialBatch::where('product_id', $batch->product_id)
            ->pluck('id');

        $receptions = StoneReception::with(['cutter', 'items.product'])
            ->whereIn('raw_material_batch_id', $batchIds)
            ->orderBy('created_at', 'desc')
            ->limit(15)
            ->get()
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

    /**
     * Сохраняет текущее значение накладных расходов (processing_sum) в партию сырья,
     * связанную с данной приёмкой. Вызывается при любом изменении позиций или
     * коэффициентов приёмки, чтобы зафиксировать значение на момент расчёта.
     */
    private function syncBatchProcessingSum(StoneReception $reception): void
    {
        if (!$reception->raw_material_batch_id) {
            return;
        }

        $keys = ['BLADE_WEAR', 'RECEPTION_COST', 'PACKAGING_COST', 'WASTE_REMOVAL',
                 'ELECTRICITY', 'PPE_COST', 'FORKLIFT_COST', 'MACHINE_COST', 'RENT_COST', 'OTHER_COSTS'];
        $processingSum = (float) array_sum(array_map(
            fn ($key) => (float) \App\Models\Setting::get($key, 0), $keys
        ));

        $reception->rawMaterialBatch?->update(['processing_sum' => $processingSum]);
    }
}
