<?php

namespace App\Services;

use App\Exceptions\InsufficientRawMaterialException;
use App\Jobs\DeleteProcessingInMoysklad;
use App\Jobs\SyncReceptionToMoysklad;
use App\Models\Department;
use App\Models\DepartmentModifier;
use App\Models\Product;
use App\Models\RawMaterialBatch;
use App\Models\ReceptionLog;
use App\Models\ReceptionLogItem;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use App\Models\Store;
use App\Models\Worker;
use App\Support\BatchStock;
use App\Support\DepartmentSettings;
use App\Support\DocumentNaming;
use App\Support\ItemCost;
use App\Support\ModifierEngine;
use App\Support\ProductSearch;
use App\Traits\HandlesBatchStock;
use App\Traits\ManagesStock;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class StoneReceptionService
{
    use HandlesBatchStock, ManagesStock;

    public function __construct(
        private RawMaterialBatchService $batchService,
    ) {}

    public function getFormOptions(?StoneReception $reception = null, ?int $cutterId = null): array
    {
        // При редактировании сохраняем уже назначенных работников в списках,
        // даже если они переведены в архив (иначе <select> потеряет значение).
        $keep = $reception ? array_filter([$reception->cutter_id, $reception->receiver_id]) : [];

        $accessibleDepartmentIds = auth()->user()?->accessibleDepartmentIds();

        $data = [
            'masterWorkers' => $this->getMasterWorkers($keep),
            'workers'      => Worker::with('departments')->where(fn ($q) => $q->whereNull('archived_at')->orWhereIn('id', $keep))
                ->orderBy('name')->get(),
            'products'     => Product::orderBy('name')->get(),
            'stores'       => Store::orderBy('name')->get(),
            'defaultStore' => Store::getDefault(),
            'departments'  => Department::where('is_active', true)
                ->when($accessibleDepartmentIds !== null,
                    fn ($q) => $q->whereIn('id', $accessibleDepartmentIds ?: [-1]))
                ->with('defaultProductionStore')
                ->orderBy('name')->get(),
            'activeBatches' => collect(),
        ];

        if ($reception) {
            $reception->load('items.modifiers', 'rawMaterialBatch.currentWorker');
            $data['stoneReception'] = $reception;
            $data['activeBatches']  = $this->getBatchesForEdit($reception);
        } elseif ($cutterId) {
            $data['activeBatches'] = $this->getActiveBatches($cutterId);
        }

        return $data;
    }

    public function getFilterData(?Request $request = null): array
    {
        // Продукты-сырьё из партий, у которых есть приёмки, плюс из «рабочих» партий без приёмок
        // (чтобы фильтр по сырью работал и для новых партий, показанных в разделе «По партиям»).
        $rawProductIds = RawMaterialBatch::whereIn('id',
            StoneReception::whereNotNull('raw_material_batch_id')
                ->distinct()->pluck('raw_material_batch_id')
        )->distinct()->pluck('product_id')
            ->merge(
                RawMaterialBatch::whereIn('status', [
                    RawMaterialBatch::STATUS_NEW,
                    RawMaterialBatch::STATUS_IN_WORK,
                    RawMaterialBatch::STATUS_CONFIRMED,
                ])->whereDoesntHave('receptions')->distinct()->pluck('product_id')
            )->unique();

        $filterRawProducts = Product::whereIn('id', $rawProductIds)->orderBy('name')->get();

        $filterProducts = Product::whereIn('id',
            StoneReceptionItem::distinct()->pluck('product_id')
        )->orderBy('name')->get();

        $filterCutters = Worker::whereIn('id',
            StoneReception::whereNotNull('cutter_id')
                ->distinct()->pluck('cutter_id')
        )->orderBy('name')->get();

        // Короткий список по умолчанию: работники отделов пользователя без уволенных
        $cutterDefaultIds = Worker::whereIn('id', $filterCutters->pluck('id'))
            ->activeInDepartments($request?->user()?->accessibleDepartmentIds())
            ->pluck('id')->all();

        $filterDepartments = Department::orderBy('name')->get();

        return compact('filterRawProducts', 'filterProducts', 'filterCutters', 'cutterDefaultIds', 'filterDepartments');
    }

    public function getLastReceptions(int $perPage = 15, ?int $rawMaterialProductId = null, ?Request $request = null): LengthAwarePaginator
    {
        $accessible = $request?->user()?->accessibleDepartmentIds();

        $query = StoneReception::with(['receiver', 'cutter', 'store', 'items.product', 'items.modifiers.modifier', 'rawMaterialBatch.product'])
            ->orderBy('created_at', 'desc');

        if ($rawMaterialProductId) {
            $query->whereHas('rawMaterialBatch', fn ($q) => $q->where('product_id', $rawMaterialProductId));
        }

        if ($accessible !== null) {
            $query->whereIn('stone_receptions.department_id', $accessible ?: [-1]);
        }

        return $query->paginate($perPage);
    }

    public function getFilteredReceptions(Request $request): LengthAwarePaginator
    {
        $accessible = $request->user()?->accessibleDepartmentIds();

        return QueryBuilder::for(StoneReception::class)
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
                AllowedFilter::callback('raw_product_search', function ($query, $value) {
                    if ($ids = ProductSearch::matchIds($value)) {
                        $query->whereHas('rawMaterialBatch', fn ($q) => $q->whereIn('product_id', $ids));
                    }
                }),
                AllowedFilter::callback('product_id', function ($query, $value) {
                    $query->whereHas('items', fn ($q) => $q->where('product_id', $value));
                }),
                AllowedFilter::callback('product_search', function ($query, $value) {
                    if ($ids = ProductSearch::matchIds($value)) {
                        $query->whereHas('items', fn ($q) => $q->whereIn('product_id', $ids));
                    }
                }),
                AllowedFilter::callback('department_id', function ($query, $value) {
                    $query->whereIn('stone_receptions.department_id', (array) $value);
                }),
                AllowedFilter::exact('cutter_id'),
            ])
            ->with(['receiver', 'cutter', 'store', 'items.product', 'items.modifiers.modifier', 'rawMaterialBatch.product', 'department'])
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->when(
                !array_key_exists('status', $request->input('filter', [])),
                fn ($q) => $q->whereIn('status', ['active', 'error'])
            )
            ->when(
                $accessible !== null && !array_key_exists('department_id', $request->input('filter', [])),
                fn ($q) => $q->whereIn('stone_receptions.department_id', $accessible ?: [-1])
            )
            ->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();
    }

    /**
     * «Рабочие» партии сырья, у которых ещё нет ни одной приёмки.
     * Показываются отдельной группой в разделе «По партиям» как единая точка входа
     * для оформления приёмки от такой партии.
     */
    public function getBatchesWithoutReceptions(Request $request): Collection
    {
        $accessible = $request->user()?->accessibleDepartmentIds();
        $filter     = $request->input('filter', []);

        return RawMaterialBatch::query()
            ->whereDoesntHave('receptions')
            ->whereIn('status', [
                RawMaterialBatch::STATUS_NEW,
                RawMaterialBatch::STATUS_IN_WORK,
                RawMaterialBatch::STATUS_CONFIRMED,
            ])
            ->when(!empty($filter['raw_product_id']), fn ($q) => $q->where('product_id', $filter['raw_product_id']))
            ->when(ProductSearch::matchIds($filter['raw_product_search'] ?? null), fn ($q, $ids) => $q->whereIn('product_id', $ids))
            ->when(array_key_exists('department_id', $filter), fn ($q) => $q->whereIn('raw_material_batches.department_id', (array) $filter['department_id']))
            ->when(
                $accessible !== null && !array_key_exists('department_id', $filter),
                fn ($q) => $q->whereIn('raw_material_batches.department_id', $accessible ?: [-1])
            )
            ->with(['product', 'currentWorker', 'currentStore', 'department'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getFilteredLogs(Request $request): LengthAwarePaginator
    {
        $accessible = $request->user()?->accessibleDepartmentIds();

        return QueryBuilder::for(ReceptionLog::class)
            ->allowedFilters([
                AllowedFilter::exact('cutter_id'),
                AllowedFilter::callback('raw_product_id', function ($query, $value) {
                    $query->whereHas('rawMaterialBatch', fn ($q) => $q->where('product_id', $value));
                }),
                AllowedFilter::callback('raw_product_search', function ($query, $value) {
                    if ($ids = ProductSearch::matchIds($value)) {
                        $query->whereHas('rawMaterialBatch', fn ($q) => $q->whereIn('product_id', $ids));
                    }
                }),
                AllowedFilter::callback('product_id', function ($query, $value) {
                    $query->whereHas('items', fn ($q) => $q->where('product_id', $value));
                }),
                AllowedFilter::callback('product_search', function ($query, $value) {
                    if ($ids = ProductSearch::matchIds($value)) {
                        $query->whereHas('items', fn ($q) => $q->whereIn('product_id', $ids));
                    }
                }),
                AllowedFilter::callback('department_id', function ($query, $value) {
                    $ids = (array) $value;
                    $query->whereHas('stoneReception', fn ($q) => $q->whereIn('department_id', $ids));
                }),
                AllowedFilter::callback('status', fn () => null),
                AllowedFilter::callback('sync_status', function ($query, $value) {
                    $statuses = is_array($value) ? $value : [$value];
                    $query->whereHas('stoneReception', fn ($q) => $q->whereIn('moysklad_sync_status', $statuses));
                }),
            ])
            ->with(['cutter', 'receiver', 'items.product', 'items.chargedWorker',
                'stoneReception.items.modifiers.modifier',
                'stoneReception.store', 'stoneReception.department', 'rawMaterialBatch.product'])
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->when(
                $accessible !== null && !array_key_exists('department_id', $request->input('filter', [])),
                fn ($q) => $q->whereHas('stoneReception', fn ($sq) => $sq->whereIn('department_id', $accessible ?: [-1])
                )
            )
            ->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();
    }

    /**
     * Предварительная проверка остатка партии — до закрытия активной приёмки и split.
     * Окончательно остаток проверяется под блокировкой партии при списании
     * (StoneReception::updateStocks): между проверкой и сохранением его могла забрать
     * другая приёмка.
     *
     * @throws InsufficientRawMaterialException
     */
    public function ensureRawAvailable(int $batchId, float $qty): void
    {
        BatchStock::ensureAvailable(RawMaterialBatch::findOrFail($batchId), $qty);
    }

    /**
     * Создать приёмку. Всё локальное — закрытие прежней активной приёмки, split партии,
     * сама приёмка и списание сырья — одна транзакция под блокировкой партии: если
     * приёмка не сохранилась (например, сырья уже не хватает), партия не разделена.
     * МойСклад — только после commit: иначе при откате в нём остались бы документы
     * разделения, которых нет в БД.
     */
    public function create(array $data, bool $isAdmin, ?string $processingName = null): StoneReception
    {
        if ($isAdmin && !empty($data['manual_created_at'])) {
            $data['manual_created_at'] = Carbon::parse($data['manual_created_at']);
        }

        $reception   = null;
        $split       = null;
        $parentBatch = null;

        DB::transaction(function () use (&$data, &$reception, &$split, &$parentBatch) {
            $batch = RawMaterialBatch::whereKey($data['raw_material_batch_id'])->lockForUpdate()->firstOrFail();

            $existingActive = $batch->getActiveReception();
            if ($existingActive) {
                $existingActive->markAsCompleted();
            }

            // Если у партии уже есть завершённые/обработанные приёмки и остаётся сырьё —
            // разделяем партию: родитель сжимается до фактически использованного объёма,
            // дочерняя получает остаток и принимает новую приёмку.
            $hasCompletedReceptions = $batch->receptions()
                ->whereIn('status', [
                    StoneReception::STATUS_COMPLETED,
                    StoneReception::STATUS_PROCESSED,
                    StoneReception::STATUS_ERROR,
                ])
                ->exists();

            if ($hasCompletedReceptions && (float) $batch->remaining_quantity > 0) {
                $split       = $this->batchService->split($batch);
                $parentBatch = $batch;
                $batch       = $split['newBatch'];

                $data['raw_material_batch_id'] = $batch->id;
            }

            $batchSnapshotBefore = (float) $batch->remaining_quantity;

            $reception = StoneReception::create($this->prepareReceptionData($data));
            $this->createReceptionItems($reception, $data['products']);

            // Отдел приёмки ведёт за собой отдел партии сырья
            if ($reception->department_id && $batch->department_id !== $reception->department_id) {
                $batch->update(['department_id' => $reception->department_id]);
            }

            $reception->load('items');
            $itemDeltas = $reception->items
                ->mapWithKeys(fn ($i) => [$i->product_id => (float) $i->quantity])
                ->toArray();

            $this->writeReceptionLog(
                $reception,
                ReceptionLog::TYPE_CREATED,
                (float) $reception->raw_quantity_used,
                $batchSnapshotBefore,
                $itemDeltas,
                $reception->created_at,
                $reception->receiver_id
            );
        });

        // Порядок задач: сперва перемещения партий, чтобы техоперация нашла сырьё на складе
        if ($split) {
            $this->batchService->queueSync($split['newBatch']);
            $this->batchService->queueSync($parentBatch);
        }

        $this->queueSync($reception, $processingName);

        return $reception;
    }

    public function update(StoneReception $reception, array $data, bool $isAdmin): StoneReception
    {
        $rawDelta = (float) ($data['raw_quantity_delta'] ?? 0);

        if ($isAdmin && !empty($data['manual_created_at'])) {
            $data['manual_created_at'] = Carbon::parse($data['manual_created_at']);
        }
        $data['original_created_at'] = $reception->created_at;

        $batchSnapshotBefore = $reception->rawMaterialBatch
            ? (float) $reception->rawMaterialBatch->remaining_quantity
            : null;

        // Товары «до правки»: убранные позиции и сырьё прежней партии тоже двигаются
        // в МойСклад — их остатки перечитываются вместе с текущими после синхронизации.
        $previousMoyskladIds = $reception->items()->with('product')->get()
            ->map(fn ($item) => $item->product?->moysklad_id)
            ->push($reception->rawMaterialBatch?->product?->moysklad_id)
            ->filter()
            ->values()
            ->all();

        DB::transaction(function () use ($reception, $data, $rawDelta, $batchSnapshotBefore, $isAdmin) {
            $preSaveItems = $reception->items()
                ->get()
                ->pluck('quantity', 'product_id')
                ->map(fn ($q) => (float) $q)
                ->toArray();

            $this->handleBatchChanges($reception, $data);
            $reception->update($this->prepareReceptionData($data, false));
            $this->updateReceptionItems($reception, $data['products']);

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

            $newProductIds = array_column($data['products'], 'product_id');
            foreach ($preSaveItems as $productId => $oldQty) {
                if (!in_array($productId, $newProductIds) && abs($oldQty) > 0.0001) {
                    $deltas[$productId] = -$oldQty;
                }
            }

            if (empty($deltas) && abs($rawDelta) < 0.0001) {
                return;
            }

            $logDate = $data['manual_created_at'] ?? now();
            // Дельта засчитывается тому, кто фактически правит: не-админ — всегда сам,
            // админ — по выбору из формы (fallback на текущего пользователя в writeReceptionLog).
            $logReceiverId = $isAdmin
                ? ($data['receiver_id'] ?? null)
                : auth()->user()?->worker_id;
            $this->writeReceptionLog(
                $reception,
                ReceptionLog::TYPE_UPDATED,
                $rawDelta,
                $batchSnapshotBefore,
                $deltas,
                $logDate,
                $logReceiverId
            );
        });

        $reception->refresh();
        $this->queueSync($reception, null, $previousMoyskladIds);

        return $reception;
    }

    /**
     * Поставить отправку приёмки в МойСклад в очередь. Вызывается после транзакции.
     * Что отправлять, задача читает из записи при выполнении — см. pushReception().
     */
    public function queueSync(StoneReception $reception, ?string $customName = null, array $alsoRefresh = []): void
    {
        $reception->markSyncPending();

        SyncReceptionToMoysklad::dispatch($reception, $customName, $alsoRefresh);
    }

    public function delete(StoneReception $reception): void
    {
        // Отношения нужны в памяти: после cascade-удаления строк по ним
        // подтягиваются остатки затронутых товаров из МойСклад.
        $reception->loadMissing('items.product', 'rawMaterialBatch.product');

        DB::transaction(fn () => $reception->delete());

        // Запись уже удалена, поэтому в задачу — только значения. Без id техоперацию найдёт
        // по внешнему коду: приёмку могли удалить, пока её создание ждало в очереди.
        DeleteProcessingInMoysklad::dispatch(
            $reception->moysklad_processing_id,
            DocumentNaming::externalCode('reception', $reception->id),
            $reception->items
                ->toBase()
                ->map(fn ($item) => $item->product?->moysklad_id)
                ->push($reception->rawMaterialBatch?->product?->moysklad_id)
                ->filter()
                ->unique()
                ->values()
                ->all()
        );
    }

    /**
     * Список мастеров/помощников/администраторов для выбора приёмщика.
     * $keep — id, которые нужно сохранить в списке, даже если работник в архиве.
     */
    public function getMasterWorkers(array $keep = []): Collection
    {
        return Worker::with('departments')
            ->whereIn('position', Worker::MASTER_POSITIONS)
            ->where(fn ($q) => $q->whereNull('archived_at')->orWhereIn('id', $keep))
            ->orderBy('name')->get();
    }

    /**
     * Точечная правка приёмщика в записи журнала (только админ).
     * Меняет только атрибуцию выработки — синхронизация с МойСклад не требуется.
     */
    public function updateLogReceiver(ReceptionLog $log, int $receiverId): void
    {
        DB::transaction(function () use ($log, $receiverId) {
            $log->update(['receiver_id' => $receiverId]);
            // Выбор администратора важнее автоматического списания минуса
            $log->items()->update(['charged_worker_id' => null]);
        });
    }

    public function closeBatch(RawMaterialBatch $batch): bool
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
            BatchStock::lock($batch);
            $newStatus = (float) $batch->remaining_quantity <= 0
                ? RawMaterialBatch::STATUS_USED
                : RawMaterialBatch::STATUS_CONFIRMED;
            $batch->update(['status' => $newStatus]);
            $batch->receptions()->where('status', StoneReception::STATUS_ACTIVE)
                ->each(fn ($r) => $r->update(['status' => StoneReception::STATUS_COMPLETED]));
        });

        foreach ($activeReceptions as $reception) {
            $reception->refresh();
            $this->queueCompletion($reception);
        }

        return true;
    }

    public function markCompleted(StoneReception $reception): void
    {
        DB::transaction(function () use ($reception) {
            $reception->markAsCompleted();

            $batch = $reception->rawMaterialBatch;
            if ($batch) {
                BatchStock::lock($batch);
                $newStatus = (float) $batch->remaining_quantity <= 0
                    ? RawMaterialBatch::STATUS_USED
                    : RawMaterialBatch::STATUS_CONFIRMED;
                $batch->update(['status' => $newStatus]);
            }
        });

        $reception->refresh();

        $this->queueCompletion($reception);
    }

    /** Без склада и партии техоперацию не создать: отправлять нечего, если её ещё нет. */
    private function queueCompletion(StoneReception $reception): void
    {
        if ($reception->hasMoySkladProcessing() || ($reception->store_id && $reception->rawMaterialBatch)) {
            $this->queueSync($reception);
        }
    }

    public function updateStore(StoneReception $reception, string $storeId): void
    {
        $reception->update(['store_id' => $storeId]);
    }

    public function updateDepartment(StoneReception $reception, int $departmentId): void
    {
        $reception->update(['department_id' => $departmentId]);
        $reception->rawMaterialBatch?->update(['department_id' => $departmentId]);
    }

    public function resetStatus(StoneReception $reception): bool|string
    {
        if ($reception->raw_material_batch_id) {
            $newerExists = StoneReception::where('raw_material_batch_id', $reception->raw_material_batch_id)
                ->where('id', '!=', $reception->id)
                ->where('id', '>', $reception->id)
                ->whereNotIn('status', [StoneReception::STATUS_ERROR])
                ->exists();

            if ($newerExists) {
                return 'Невозможно активировать приёмку: для этой партии сырья есть более новая приёмка';
            }
        }

        DB::transaction(function () use ($reception) {
            $reception->update(['status' => StoneReception::STATUS_ACTIVE]);

            $batch = $reception->rawMaterialBatch;
            if ($batch && $batch->status === RawMaterialBatch::STATUS_USED) {
                $batch->update(['status' => RawMaterialBatch::STATUS_IN_WORK]);
            }
        });

        if ($reception->hasMoySkladProcessing()) {
            $this->queueSync($reception);
        }

        return true;
    }

    public function updateItemCoeff(StoneReception $reception, array $validated): void
    {
        DB::transaction(function () use ($reception, $validated) {
            foreach ($validated['items'] as $row) {
                $item = $reception->items()->with('product')->findOrFail($row['item_id']);

                // База берётся из формы как есть: правила её не переопределяют,
                // поэтому повторное сохранение не сдвигает коэффициент.
                // Сработавшие по SKU правила форма шлёт чекбоксами: неотмеченное — снято.
                $departmentId = $this->modifierDepartmentId($reception);
                $keys         = $row['modifiers'] ?? [];

                $cost = ItemCost::computeFromBase(
                    $item->product,
                    (float) $row['base_coeff'],
                    $departmentId,
                    DepartmentModifier::SCOPE_RECEPTION,
                    $keys,
                    $this->batchSku($reception),
                    (float) $row['master_base_coeff'],
                    ModifierEngine::disabledSkuKeys(
                        $departmentId,
                        DepartmentModifier::SCOPE_RECEPTION,
                        $item->product?->sku,
                        $keys,
                    ),
                );

                $item->update($cost['attributes']);
                ItemCost::syncSnapshot('stone_reception_item_id', $item->id, $cost['modifiers']);
            }

            $this->syncBatchProcessingSum($reception);
        });
    }

    public function refreshItemCoeffs(StoneReception $reception): void
    {
        $reception->loadMissing('items.product', 'items.modifiers');

        DB::transaction(function () use ($reception) {
            foreach ($reception->items as $item) {
                if (!$item->product || $item->product->prod_cost_coeff === null) {
                    continue;
                }

                // Массовый пересчёт от актуального коэффициента справочника.
                // Ручной выбор пользователя восстанавливается из снапшота позиции:
                // sku-правила движок определит сам, а отметки — больше неоткуда.
                // Снятые у позиции sku-правила остаются снятыми.
                $departmentId = $this->modifierDepartmentId($reception);

                $cost = ItemCost::compute(
                    $item->product,
                    $departmentId,
                    DepartmentModifier::SCOPE_RECEPTION,
                    ModifierEngine::manualKeysFromSnapshot($departmentId, $item->modifiers),
                    $this->batchSku($reception),
                    $item->disabled_modifier_keys ?? [],
                );

                $item->update($cost['attributes']);
                ItemCost::syncSnapshot('stone_reception_item_id', $item->id, $cost['modifiers']);
            }

            $this->syncBatchProcessingSum($reception);
        });
    }

    public function getActiveBatchesForWorker(Worker $worker): Collection
    {
        return $this->getActiveBatches($worker->id);
    }

    public function getActiveReceptionByBatch(RawMaterialBatch $batch): ?StoneReception
    {
        return $batch->getActiveReception();
    }

    public function getReceptionsByBatch(RawMaterialBatch $batch): Collection
    {
        $batchIds = RawMaterialBatch::where('product_id', $batch->product_id)->pluck('id');

        return StoneReception::with(['cutter', 'items.product', 'items.modifiers'])
            ->whereIn('raw_material_batch_id', $batchIds)
            ->orderBy('created_at', 'desc')
            ->limit(15)
            ->get();
    }

    private function getBatchesForEdit(StoneReception $reception): Collection
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
     * Отдел документа: явно выбранный → отдел партии сырья →
     * отдел создателя (админ — null) → отдел работника.
     */
    public function resolveDepartmentId(array $data): ?int
    {
        return $data['department_id']
            ?? RawMaterialBatch::find($data['raw_material_batch_id'] ?? null)?->department_id
            ?? auth()->user()?->primaryDepartmentId()
            ?? Worker::find($data['cutter_id'] ?? $data['receiver_id'] ?? null)?->department_id;
    }

    private function prepareReceptionData(array $data, bool $forCreate = true): array
    {
        $prepared = [
            'cutter_id'             => $data['cutter_id'] ?? null,
            'store_id'              => $data['store_id'],
            'raw_material_batch_id' => $data['raw_material_batch_id'],
            'raw_quantity_used'     => $data['raw_quantity_used'],
            'notes'                 => $data['notes'] ?? null,
        ];

        if ($forCreate) {
            // Отдел задаётся в форме создания; позже его меняет только show-страница (updateDepartment)
            $prepared['department_id'] = $data['department_id']
                ?? Worker::find($data['cutter_id'] ?? $data['receiver_id'] ?? null)?->department_id;
            $prepared['receiver_id'] = $data['receiver_id'];
            $prepared['created_at']  = $data['manual_created_at'] ?? now();
            $prepared['updated_at']  = $data['manual_created_at'] ?? now();
        } else {
            $prepared['created_at'] = $data['manual_created_at'] ?? $data['original_created_at'];
            $prepared['updated_at'] = now();
        }

        return $prepared;
    }

    private function createReceptionItems(StoneReception $reception, array $products): void
    {
        $productIds = array_column($products, 'product_id');
        $productMap = Product::whereIn('id', $productIds)->get()->keyBy('id');

        foreach ($products as $product) {
            $prod = $productMap->get($product['product_id']);

            $cost = ItemCost::compute(
                $prod,
                $this->modifierDepartmentId($reception),
                DepartmentModifier::SCOPE_RECEPTION,
                $product['modifiers'] ?? [],
                $this->batchSku($reception),
            );

            $item = $reception->items()->create(array_merge($cost['attributes'], [
                'product_id' => $product['product_id'],
                'quantity'   => $product['quantity'],
            ]));

            ItemCost::syncSnapshot('stone_reception_item_id', $item->id, $cost['modifiers']);
        }

        $this->syncBatchProcessingSum($reception);
    }

    /**
     * Отдел для правил себестоимости — эффективный, а не голая колонка:
     * у приёмок, созданных админом, department_id пуст, и правила молча
     * не применились бы.
     */
    private function modifierDepartmentId(StoneReception $reception): ?int
    {
        return $reception->effectiveDepartmentId();
    }

    /** SKU партии сырья — от него зависит доступность правил вроде торцовки. */
    private function batchSku(StoneReception $reception): ?string
    {
        return $reception->rawMaterialBatch?->product?->sku;
    }

    private function updateReceptionItems(StoneReception $reception, array $products): void
    {
        $products = array_values(array_filter($products, fn ($p) => (float) ($p['quantity'] ?? 0) > 0));

        $existingItems = $reception->items()->get()->keyBy('product_id');
        $newProductIds = array_column($products, 'product_id');

        $newIds     = array_diff($newProductIds, $existingItems->keys()->toArray());
        $productMap = $newIds
            ? Product::whereIn('id', $newIds)->get()->keyBy('id')
            : collect();

        foreach ($products as $product) {
            $productId = $product['product_id'];

            if ($existingItems->has($productId)) {
                $existingItems[$productId]->update(['quantity' => $product['quantity']]);
            } else {
                $prod = $productMap->get($productId);

                $cost = ItemCost::compute(
                    $prod,
                    $this->modifierDepartmentId($reception),
                    DepartmentModifier::SCOPE_RECEPTION,
                    $product['modifiers'] ?? [],
                    $this->batchSku($reception),
                );

                $item = $reception->items()->create(array_merge($cost['attributes'], [
                    'product_id' => $productId,
                    'quantity'   => $product['quantity'],
                ]));

                ItemCost::syncSnapshot('stone_reception_item_id', $item->id, $cost['modifiers']);
            }
        }

        $reception->items()->whereNotIn('product_id', $newProductIds)->delete();
        $this->syncBatchProcessingSum($reception);
    }

    private function syncBatchProcessingSum(StoneReception $reception): void
    {
        if (!$reception->raw_material_batch_id) {
            return;
        }

        $processingSum = DepartmentSettings::overheadPerUnit($reception->effectiveDepartmentId());

        $reception->rawMaterialBatch?->update(['processing_sum' => $processingSum]);
    }

    private function writeReceptionLog(
        StoneReception $reception,
        string $type,
        float $rawDelta,
        ?float $batchSnapshot,
        array $itemDeltas,
        ?\DateTimeInterface $createdAt = null,
        ?int $receiverId = null
    ): void {
        $receiverId ??= auth()->user()->worker_id;
        // До создания записи: минус считается по уже записанным строкам
        $lines = $this->logItemLines($reception, $itemDeltas, $receiverId);

        $log = ReceptionLog::create([
            'stone_reception_id'    => $reception->id,
            'raw_material_batch_id' => $reception->raw_material_batch_id,
            'cutter_id'             => $reception->cutter_id,
            'receiver_id'           => $receiverId,
            'type'                  => $type,
            'raw_quantity_delta'    => $rawDelta,
            'raw_quantity_snapshot' => $batchSnapshot,
            'created_at'            => $createdAt ?? now(),
        ]);

        if ($lines) {
            $now = now();
            ReceptionLogItem::insert(array_map(fn ($line) => $line + [
                'reception_log_id' => $log->id,
                'created_at'       => $now,
                'updated_at'       => $now,
            ], $lines));
        }
    }

    /**
     * Строки записи журнала. Плюс — приёмщику записи (тому, кто правит), минус — тому, кто получил
     * этот плюс: один минус может дать несколько строк, если плюс давали разные мастера.
     *
     * @param  array<int, float>  $itemDeltas  product_id => дельта
     * @return array<int, array{product_id: int, quantity_delta: float, charged_worker_id: ?int}>
     */
    private function logItemLines(StoneReception $reception, array $itemDeltas, ?int $receiverId): array
    {
        $lines = [];

        foreach ($itemDeltas as $productId => $delta) {
            if ($delta >= 0) {
                $lines[] = ['product_id' => $productId, 'quantity_delta' => $delta, 'charged_worker_id' => null];

                continue;
            }

            foreach ($this->minusShares($reception, $productId, -$delta) as $workerId => $quantity) {
                $lines[] = [
                    'product_id'        => $productId,
                    'quantity_delta'    => -$quantity,
                    'charged_worker_id' => $workerId === $receiverId ? null : $workerId,
                ];
            }
        }

        return $lines;
    }

    /**
     * Кому сколько списать: сначала с мастера, добавившего этот товар последним, затем с предыдущего.
     * Не хватило (старые записи) — остаток на приёмщике приёмки.
     *
     * @return array<int, float>  worker_id => количество
     */
    private function minusShares(StoneReception $reception, int $productId, float $quantity): array
    {
        $lines = ReceptionLogItem::with('receptionLog')
            ->where('product_id', $productId)
            ->whereHas('receptionLog', fn ($q) => $q->where('stone_reception_id', $reception->id))
            ->orderBy('id')
            ->get();

        // Порядок ключей — порядок добавления: мастер с новым плюсом уходит в конец
        $remaining = [];
        foreach ($lines as $line) {
            $workerId = $line->masterWorkerId();
            $delta    = (float) $line->quantity_delta;
            $value    = ($remaining[$workerId] ?? 0.0) + $delta;

            if ($delta > 0) {
                unset($remaining[$workerId]);
            }
            $remaining[$workerId] = $value;
        }

        $shares = [];
        foreach (array_reverse($remaining, true) as $workerId => $available) {
            if ($quantity < 0.0005) {
                break;
            }
            if ($available < 0.0005) {
                continue;
            }

            $shares[$workerId] = min($available, $quantity);
            $quantity          = round($quantity - $shares[$workerId], 3);
        }

        if ($quantity >= 0.0005) {
            $shares[$reception->receiver_id] = ($shares[$reception->receiver_id] ?? 0.0) + $quantity;
        }

        return $shares;
    }
}
