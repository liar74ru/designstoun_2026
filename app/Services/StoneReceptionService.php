<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Product;
use App\Models\RawMaterialBatch;
use App\Models\ReceptionLog;
use App\Models\ReceptionLogItem;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use App\Models\Worker;
use App\Services\Moysklad\RawMaterialBatchSyncService;
use App\Services\Moysklad\StoneReceptionSyncService;
use App\Traits\HandlesBatchStock;
use App\Traits\ManagesStock;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class StoneReceptionService
{
    use ManagesStock, HandlesBatchStock;

    public function __construct(
        private StoneReceptionSyncService $syncService,
        private RawMaterialBatchService $batchService,
        private RawMaterialBatchSyncService $batchSyncService,
    ) {}

    public function getFormOptions(?StoneReception $reception = null, ?int $cutterId = null): array
    {
        // При редактировании сохраняем уже назначенных работников в списках,
        // даже если они переведены в архив (иначе <select> потеряет значение).
        $keep = $reception ? array_filter([$reception->cutter_id, $reception->receiver_id]) : [];

        $data = [
            'masterWorkers' => $this->getMasterWorkers($keep),
            'workers'      => Worker::with('departments')->where(fn($q) => $q->whereNull('archived_at')->orWhereIn('id', $keep))
                ->orderBy('name')->get(),
            'products'     => Product::orderBy('name')->get(),
            'stores'       => Store::orderBy('name')->get(),
            'defaultStore' => Store::getDefault(),
            'activeBatches' => collect(),
        ];

        if ($reception) {
            $reception->load('items', 'rawMaterialBatch.currentWorker');
            $data['stoneReception'] = $reception;
            $data['activeBatches']  = $this->getBatchesForEdit($reception);
        } elseif ($cutterId) {
            $data['activeBatches'] = $this->getActiveBatches($cutterId);
        }

        return $data;
    }

    public function getFilterData(): array
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

        $filterDepartments = Department::orderBy('name')->get();

        return compact('filterRawProducts', 'filterProducts', 'filterCutters', 'filterDepartments');
    }

    public function getLastReceptions(int $perPage = 15, ?int $rawMaterialProductId = null, ?Request $request = null): LengthAwarePaginator
    {
        $accessible = $request?->user()?->accessibleDepartmentIds();

        $query = StoneReception::with(['receiver', 'cutter', 'store', 'items.product', 'rawMaterialBatch.product'])
            ->orderBy('created_at', 'desc');

        if ($rawMaterialProductId) {
            $query->whereHas('rawMaterialBatch', fn($q) => $q->where('product_id', $rawMaterialProductId));
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
                AllowedFilter::callback('product_id', function ($query, $value) {
                    $query->whereHas('items', fn($q) => $q->where('product_id', $value));
                }),
                AllowedFilter::callback('department_id', function ($query, $value) {
                    $query->whereIn('stone_receptions.department_id', (array) $value);
                }),
                AllowedFilter::exact('cutter_id'),
            ])
            ->with(['receiver', 'cutter', 'store', 'items.product', 'rawMaterialBatch.product', 'department'])
            ->when($request->filled('date_from'), fn($q) =>
                $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn($q) =>
                $q->whereDate('created_at', '<=', $request->date_to))
            ->when(
                !array_key_exists('status', $request->input('filter', [])),
                fn($q) => $q->whereIn('status', ['active', 'error'])
            )
            ->when(
                $accessible !== null && !array_key_exists('department_id', $request->input('filter', [])),
                fn($q) => $q->whereIn('stone_receptions.department_id', $accessible ?: [-1])
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
            ->when(!empty($filter['raw_product_id']), fn($q) =>
                $q->where('product_id', $filter['raw_product_id']))
            ->when(array_key_exists('department_id', $filter), fn($q) =>
                $q->whereIn('raw_material_batches.department_id', (array) $filter['department_id']))
            ->when(
                $accessible !== null && !array_key_exists('department_id', $filter),
                fn($q) => $q->whereIn('raw_material_batches.department_id', $accessible ?: [-1])
            )
            ->with(['product', 'currentWorker', 'currentStore', 'department'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getFilteredLogs(Request $request): LengthAwarePaginator
    {
        $accessible = $request->user()?->accessibleDepartmentIds();

        return QueryBuilder::for(\App\Models\ReceptionLog::class)
            ->allowedFilters([
                AllowedFilter::exact('cutter_id'),
                AllowedFilter::callback('raw_product_id', function ($query, $value) {
                    $query->whereHas('rawMaterialBatch', fn($q) => $q->where('product_id', $value));
                }),
                AllowedFilter::callback('product_id', function ($query, $value) {
                    $query->whereHas('items', fn($q) => $q->where('product_id', $value));
                }),
                AllowedFilter::callback('department_id', function ($query, $value) {
                    $ids = (array) $value;
                    $query->whereHas('stoneReception', fn($q) => $q->whereIn('department_id', $ids));
                }),
                AllowedFilter::callback('status', fn() => null),
                AllowedFilter::callback('sync_status', fn() => null),
            ])
            ->with(['cutter', 'receiver', 'items.product',
                'stoneReception.store', 'stoneReception.department', 'rawMaterialBatch.product'])
            ->when($request->filled('date_from'), fn($q) =>
                $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn($q) =>
                $q->whereDate('created_at', '<=', $request->date_to))
            ->when(
                $accessible !== null && !array_key_exists('department_id', $request->input('filter', [])),
                fn($q) => $q->whereHas('stoneReception', fn($sq) =>
                    $sq->whereIn('department_id', $accessible ?: [-1])
                )
            )
            ->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();
    }

    public function create(array $data, bool $isAdmin, ?string $processingName = null): StoneReception
    {
        $batch = RawMaterialBatch::findOrFail($data['raw_material_batch_id']);
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
            $split = $this->batchService->split($batch);
            $data['raw_material_batch_id'] = $split['newBatch']->id;

            $this->batchSyncService->syncCreated($split['newBatch'], $split['newMovement']);
            $this->batchSyncService->updateParentMove($batch, (float) $batch->fresh()->initial_quantity);

            $batch = $split['newBatch'];
        }

        $batchSnapshotBefore = (float) $batch->remaining_quantity;
        $reception           = null;

        if ($isAdmin && !empty($data['manual_created_at'])) {
            $data['manual_created_at'] = Carbon::parse($data['manual_created_at']);
        }

        DB::transaction(function () use ($data, $batchSnapshotBefore, &$reception) {
            $reception = StoneReception::create($this->prepareReceptionData($data));
            $this->createReceptionItems($reception, $data['products']);

            $reception->load('items');
            $itemDeltas = $reception->items
                ->mapWithKeys(fn($i) => [$i->product_id => (float) $i->quantity])
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

        $batch->refresh();
        $this->syncService->syncReception($reception, $processingName);

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

        DB::transaction(function () use ($reception, $data, $rawDelta, $batchSnapshotBefore, $isAdmin) {
            $preSaveItems = $reception->items()
                ->get()
                ->pluck('quantity', 'product_id')
                ->map(fn($q) => (float) $q)
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
        $this->syncService->syncReception($reception);

        return $reception;
    }

    public function delete(StoneReception $reception): void
    {
        DB::transaction(fn() => $reception->delete());
    }

    /**
     * Список мастеров/помощников/администраторов для выбора приёмщика.
     * $keep — id, которые нужно сохранить в списке, даже если работник в архиве.
     */
    public function getMasterWorkers(array $keep = []): Collection
    {
        return Worker::with('departments')
            ->whereIn('position', Worker::MASTER_POSITIONS)
            ->where(fn($q) => $q->whereNull('archived_at')->orWhereIn('id', $keep))
            ->orderBy('name')->get();
    }

    /**
     * Точечная правка приёмщика в записи журнала (только админ).
     * Меняет только атрибуцию выработки — синхронизация с МойСклад не требуется.
     */
    public function updateLogReceiver(ReceptionLog $log, int $receiverId): void
    {
        $log->update(['receiver_id' => $receiverId]);
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
            $newStatus = (float) $batch->remaining_quantity <= 0
                ? RawMaterialBatch::STATUS_USED
                : RawMaterialBatch::STATUS_CONFIRMED;
            $batch->update(['status' => $newStatus]);
            $batch->receptions()->where('status', StoneReception::STATUS_ACTIVE)
                ->each(fn($r) => $r->update(['status' => StoneReception::STATUS_COMPLETED]));
        });

        foreach ($activeReceptions as $reception) {
            $reception->refresh();
            if ($reception->hasMoySkladProcessing()) {
                $result = $this->syncService->completeProcessing($reception->moysklad_processing_id);
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

    public function markCompleted(StoneReception $reception): array
    {
        DB::transaction(function () use ($reception) {
            $reception->markAsCompleted();

            $batch = $reception->rawMaterialBatch;
            if ($batch) {
                $newStatus = (float) $batch->remaining_quantity <= 0
                    ? RawMaterialBatch::STATUS_USED
                    : RawMaterialBatch::STATUS_CONFIRMED;
                $batch->update(['status' => $newStatus]);
            }
        });

        $reception->refresh();

        if ($reception->store_id && $reception->rawMaterialBatch) {
            $this->syncService->syncReception($reception);
            $reception->refresh();
        }

        if ($reception->hasMoySkladProcessing() && !$reception->hasSyncError()) {
            $result = $this->syncService->completeProcessing($reception->moysklad_processing_id);

            if ($result['success']) {
                $reception->markSynced($reception->moysklad_processing_id);
                return ['success' => true, 'message' => 'Приёмка завершена и синхронизирована с МойСклад.'];
            }

            $reception->markSyncError($result['message']);
            return [
                'success' => false,
                'message' => 'Приёмка завершена локально, но ошибка синхронизации с МойСклад: ' . $result['message'],
            ];
        }

        if ($reception->hasSyncError()) {
            return [
                'success' => false,
                'message' => 'Приёмка завершена локально, но ошибка синхронизации с МойСклад: ' . $reception->moysklad_sync_error,
            ];
        }

        return ['success' => true, 'message' => 'Приёмка завершена.'];
    }

    public function updateStore(StoneReception $reception, string $storeId): void
    {
        $reception->update(['store_id' => $storeId]);
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

        $processingId = $reception->moysklad_processing_id;

        DB::transaction(function () use ($reception) {
            $reception->update(['status' => StoneReception::STATUS_ACTIVE]);

            $batch = $reception->rawMaterialBatch;
            if ($batch && $batch->status === RawMaterialBatch::STATUS_USED) {
                $batch->update(['status' => RawMaterialBatch::STATUS_IN_WORK]);
            }
        });

        if ($processingId) {
            $result = $this->syncService->reactivateProcessing($processingId);
            if ($result['success']) {
                $reception->markSynced($processingId);
            } else {
                $reception->markSyncError($result['message']);
                return 'Статус сброшен локально, но ошибка синхронизации с МойСклад: ' . $result['message'];
            }
        }

        return true;
    }

    public function updateItemCoeff(StoneReception $reception, array $validated): void
    {
        DB::transaction(function () use ($reception, $validated) {
            foreach ($validated['items'] as $row) {
                $item = $reception->items()->with('product')->findOrFail($row['item_id']);

                $isUndercut  = !empty($row['is_undercut']);
                $isEdging    = !empty($row['is_edging']);
                $baseCoeff   = (float) $row['base_coeff'];
                $isSmallTile = StoneReceptionItem::skuIsSmallTile($item->product?->sku);
                $effCoeff    = StoneReceptionItem::computeEffectiveCoeff($baseCoeff, $isUndercut, $isEdging, $item->product?->sku);

                $item->update([
                    'effective_cost_coeff' => $effCoeff,
                    'is_undercut'          => $isUndercut,
                    'is_edging'            => $isEdging,
                    'is_small_tile'        => $isSmallTile,
                    'worker_cost_per_m2'   => $item->product?->prodCost($effCoeff),
                    'master_cost_per_m2'   => StoneReceptionItem::computeMasterCost($isUndercut, $isSmallTile),
                ]);
            }

            $this->syncBatchProcessingSum($reception);
        });
    }

    public function refreshItemCoeffs(StoneReception $reception): void
    {
        $reception->loadMissing('items.product');

        DB::transaction(function () use ($reception) {
            foreach ($reception->items as $item) {
                if (!$item->product || $item->product->prod_cost_coeff === null) {
                    continue;
                }

                $baseCoeff   = (float) $item->product->prod_cost_coeff;
                $isUndercut  = (bool) $item->is_undercut;
                $isEdging    = (bool) $item->is_edging;
                $isSmallTile = StoneReceptionItem::skuIsSmallTile($item->product->sku);
                $effCoeff    = StoneReceptionItem::computeEffectiveCoeff($baseCoeff, $isUndercut, $isEdging, $item->product->sku);

                $item->update([
                    'effective_cost_coeff' => $effCoeff,
                    'is_small_tile'        => $isSmallTile,
                    'worker_cost_per_m2'   => $item->product->prodCost($effCoeff),
                    'master_cost_per_m2'   => StoneReceptionItem::computeMasterCost($isUndercut, $isSmallTile),
                ]);
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

        return StoneReception::with(['cutter', 'items.product'])
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

    private function prepareReceptionData(array $data, bool $forCreate = true): array
    {
        $departmentId = $data['department_id']
            ?? Worker::find($data['cutter_id'] ?? $data['receiver_id'] ?? null)?->department_id;

        $prepared = [
            'cutter_id'             => $data['cutter_id'] ?? null,
            'store_id'              => $data['store_id'],
            'department_id'         => $departmentId,
            'raw_material_batch_id' => $data['raw_material_batch_id'],
            'raw_quantity_used'     => $data['raw_quantity_used'],
            'notes'                 => $data['notes'] ?? null,
        ];

        if ($forCreate) {
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
            $prod        = $productMap->get($product['product_id']);
            $baseCoeff   = (float) ($prod?->prod_cost_coeff ?? 0);
            $isUndercut  = !empty($product['is_undercut']);
            $isEdging    = !empty($product['is_edging']);
            $isSmallTile = StoneReceptionItem::skuIsSmallTile($prod?->sku);
            $effCoeff    = StoneReceptionItem::computeEffectiveCoeff($baseCoeff, $isUndercut, $isEdging, $prod?->sku);

            $reception->items()->create([
                'product_id'           => $product['product_id'],
                'quantity'             => $product['quantity'],
                'effective_cost_coeff' => $effCoeff,
                'is_undercut'          => $isUndercut,
                'is_edging'            => $isEdging,
                'is_small_tile'        => $isSmallTile,
                'worker_cost_per_m2'   => $prod?->prodCost($effCoeff),
                'master_cost_per_m2'   => StoneReceptionItem::computeMasterCost($isUndercut, $isSmallTile),
            ]);
        }

        $this->syncBatchProcessingSum($reception);
    }

    private function updateReceptionItems(StoneReception $reception, array $products): void
    {
        $products = array_values(array_filter($products, fn($p) => (float) ($p['quantity'] ?? 0) > 0));

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
                $prod        = $productMap->get($productId);
                $baseCoeff   = (float) ($prod?->prod_cost_coeff ?? 0);
                $isUndercut  = !empty($product['is_undercut']);
                $isEdging    = !empty($product['is_edging']);
                $isSmallTile = StoneReceptionItem::skuIsSmallTile($prod?->sku);
                $effCoeff    = StoneReceptionItem::computeEffectiveCoeff($baseCoeff, $isUndercut, $isEdging, $prod?->sku);

                $reception->items()->create([
                    'product_id'           => $productId,
                    'quantity'             => $product['quantity'],
                    'effective_cost_coeff' => $effCoeff,
                    'is_undercut'          => $isUndercut,
                    'is_edging'            => $isEdging,
                    'is_small_tile'        => $isSmallTile,
                    'worker_cost_per_m2'   => $prod?->prodCost($effCoeff),
                    'master_cost_per_m2'   => StoneReceptionItem::computeMasterCost($isUndercut, $isSmallTile),
                ]);
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

        $keys = ['BLADE_WEAR', 'RECEPTION_COST', 'WASTE_REMOVAL',
                 'ELECTRICITY', 'PPE_COST', 'FORKLIFT_COST', 'MACHINE_COST', 'RENT_COST', 'OTHER_COSTS'];
        $processingSum = (float) array_sum(array_map(
            fn($key) => (float) Setting::get($key, 0), $keys
        ));

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
}
