<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Workshop;
use App\Models\WorkshopItem;
use App\Models\WorkshopLog;
use App\Models\WorkshopLogItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoneReceptionItem;
use App\Models\Worker;
use App\Services\Moysklad\WorkshopSyncService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class WorkshopService
{
    public function __construct(
        private WorkshopSyncService $syncService,
    ) {}

    public function getFormOptions(?Workshop $workshop = null): array
    {
        // При редактировании сохраняем уже назначенных работников в списках,
        // даже если они переведены в архив (иначе <select> потеряет значение).
        $keep = $workshop ? array_filter([$workshop->packer_id, $workshop->receiver_id]) : [];

        $packers = Worker::whereIn('position', ['Мастер', 'Администратор'])
            ->where(fn($q) => $q->whereNull('archived_at')->orWhereIn('id', $keep))
            ->orderBy('name')->get();

        $masterWorkers = Worker::whereIn('position', ['Мастер', 'Администратор'])
            ->where(fn($q) => $q->whereNull('archived_at')->orWhereIn('id', $keep))
            ->orderBy('name')->get();

        $userDepartment = auth()->user()?->worker?->department;
        $userDepartment?->loadMissing('defaultProductionStore', 'defaultProductStore');

        $data = [
            'packers'             => $packers,
            'masterWorkers'       => $masterWorkers,
            'workers'             => Worker::where(fn($q) => $q->whereNull('archived_at')->orWhereIn('id', $keep))
                ->orderBy('name')->get(),
            'products'            => Product::orderBy('name')->get(),
            'packageProducts'     => Product::where('sku', 'like', '07-03%')->orderBy('name')->get(),
            'stores'              => Store::orderBy('name')->get(),
            'defaultStore'        => $userDepartment?->defaultProductionStore ?? Store::getDefault(),
            'defaultProductStore' => $userDepartment?->defaultProductStore ?? Store::getDefault(),
            'departments'         => Department::where('is_active', true)
                ->with('defaultProductionStore', 'defaultProductStore')
                ->orderBy('name')->get(),
        ];

        if ($workshop) {
            $workshop->load('items.product', 'packer.department');
            $data['workshop'] = $workshop;
        }

        return $data;
    }

    public function getFilterData(?Request $request = null): array
    {
        $filterProducts = Product::whereIn('id',
            WorkshopItem::distinct()->pluck('product_id')
        )->orderBy('name')->get();

        $filterPackers = Worker::whereIn('id',
            Workshop::distinct()->pluck('packer_id')
        )->orderBy('name')->get();

        $filterPackageProducts = Product::whereIn('id',
            WorkshopItem::where('role', WorkshopItem::ROLE_PACKAGE)->distinct()->pluck('product_id')
        )->orderBy('name')->get();

        $filterDepartments  = Department::orderBy('name')->get();
        $departmentDefaults = $request?->user()?->accessibleDepartmentIds() ?? [];

        return compact(
            'filterProducts', 'filterPackers', 'filterPackageProducts',
            'filterDepartments', 'departmentDefaults'
        );
    }

    public function getFilteredWorkshops(Request $request): LengthAwarePaginator
    {
        $accessible = $request->user()?->accessibleDepartmentIds();

        return QueryBuilder::for(Workshop::class)
            ->allowedFilters([
                AllowedFilter::callback('status', function ($query, $value) {
                    $query->whereIn('status', is_array($value) ? $value : [$value]);
                }),
                AllowedFilter::callback('sync_status', function ($query, $value) {
                    $query->whereIn('moysklad_sync_status', is_array($value) ? $value : [$value]);
                }),
                AllowedFilter::callback('product_id', function ($query, $value) {
                    $query->whereHas('items', fn($q) => $q
                        ->where('role', WorkshopItem::ROLE_RAW)
                        ->where('product_id', $value));
                }),
                AllowedFilter::callback('package_product_id', function ($query, $value) {
                    $query->whereHas('items', fn($q) => $q
                        ->where('role', WorkshopItem::ROLE_PACKAGE)
                        ->where('product_id', $value));
                }),
                AllowedFilter::callback('department_id', function ($query, $value) {
                    $query->whereIn('workshops.department_id', (array) $value);
                }),
                AllowedFilter::exact('packer_id'),
            ])
            ->with(['packer', 'receiver', 'store', 'items.product', 'department'])
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
                fn($q) => $q->whereIn('workshops.department_id', $accessible ?: [-1])
            )
            ->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();
    }

    /**
     * Последние операции цеха для панели копирования в create-форме.
     * Скоуп — по доступным пользователю отделам (как в getFilteredWorkshops).
     */
    public function getLastWorkshops(?Request $request = null, int $limit = 15): \Illuminate\Support\Collection
    {
        $accessible = $request?->user()?->accessibleDepartmentIds();

        return Workshop::query()
            ->with(['packer', 'items.product'])
            ->when($accessible !== null, fn($q) => $q->whereIn('department_id', $accessible ?: [-1]))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function getDefaultStoreForPacker(?Worker $packer): ?Store
    {
        if (!$packer) {
            return Store::getDefault();
        }
        $packer->loadMissing('department.defaultProductionStore');
        return $packer->department?->defaultProductionStore ?? Store::getDefault();
    }

    public function create(array $data, bool $isAdmin, ?string $processingName = null): Workshop
    {
        if ($isAdmin && !empty($data['manual_created_at'])) {
            $data['manual_created_at'] = Carbon::parse($data['manual_created_at']);
        }

        $workshop = null;

        DB::transaction(function () use ($data, &$workshop) {
            $workshop = Workshop::create($this->prepareWorkshopData($data));

            // Порядок: сначала упаковка (её коэффициент нужен для расчёта зарплаты продукта).
            $this->createWorkshopItems($workshop, $data['packages'] ?? [], WorkshopItem::ROLE_PACKAGE);
            $this->createWorkshopItems($workshop, $data['raw_materials'], WorkshopItem::ROLE_RAW);
            $this->createWorkshopItems($workshop, $data['products'], WorkshopItem::ROLE_PRODUCT);

            // Списать тару со склада сырья (booted::created здесь не годится — строки уже есть).
            $workshop->adjustAllPackageStock(1);

            $workshop->load('items');
            $productSnapshot = (float) $workshop->items
                ->where('role', WorkshopItem::ROLE_PRODUCT)->sum('quantity');

            $this->writeWorkshopLog(
                $workshop,
                WorkshopLog::TYPE_CREATED,
                $productSnapshot,
                $productSnapshot,
                $this->createItemDeltas($workshop->items),
                $workshop->created_at,
                $data['receiver_id'] ?? null
            );
        });

        $this->syncService->syncWorkshop($workshop, $processingName);

        return $workshop;
    }

    /** Дельты для лога при создании: все строки как +quantity с ролью. */
    private function createItemDeltas($items): array
    {
        return $items->map(fn($i) => [
            'product_id' => $i->product_id,
            'role'       => $i->role,
            'delta'      => (float) $i->quantity,
        ])->values()->toArray();
    }

    public function update(Workshop $workshop, array $data, bool $isAdmin): Workshop
    {
        if ($isAdmin && !empty($data['manual_created_at'])) {
            $data['manual_created_at'] = Carbon::parse($data['manual_created_at']);
        }
        $data['original_created_at'] = $workshop->created_at;

        DB::transaction(function () use ($workshop, $data) {
            $before          = $workshop->items()->get();
            $beforePackages  = $before->where('role', WorkshopItem::ROLE_PACKAGE);
            $oldProductTotal = (float) $before->where('role', WorkshopItem::ROLE_PRODUCT)->sum('quantity');
            $oldStoreId      = $workshop->store_id;

            $workshop->update($this->prepareWorkshopData($data, false));

            // Порядок: упаковка (коэффициент тары) → сырьё → продукт.
            $this->syncRoleItems($workshop, $data['packages'] ?? [], WorkshopItem::ROLE_PACKAGE);
            $this->syncRoleItems($workshop, $data['raw_materials'], WorkshopItem::ROLE_RAW);
            $this->syncRoleItems($workshop, $data['products'], WorkshopItem::ROLE_PRODUCT);

            // Движение остатка тары.
            $afterPackages = $workshop->packageItems()->get();
            if ((string) $oldStoreId !== (string) $workshop->store_id) {
                foreach ($beforePackages as $it) {
                    $workshop->adjustPackageStock($it->product_id, -1 * (float) $it->quantity, $oldStoreId);
                }
                foreach ($afterPackages as $it) {
                    $workshop->adjustPackageStock($it->product_id, (float) $it->quantity);
                }
            } else {
                $this->applyPackageDeltas($workshop, $beforePackages, $afterPackages);
            }

            $after           = $workshop->items()->get();
            $itemDeltas      = $this->diffItemDeltas($before, $after);
            $productSnapshot  = (float) $after->where('role', WorkshopItem::ROLE_PRODUCT)->sum('quantity');
            $productDelta     = $productSnapshot - $oldProductTotal;

            if (empty($itemDeltas)) {
                return;
            }

            $logDate = $data['manual_created_at'] ?? now();
            $this->writeWorkshopLog(
                $workshop,
                WorkshopLog::TYPE_UPDATED,
                $productDelta,
                $productSnapshot,
                $itemDeltas,
                $logDate,
                $data['receiver_id'] ?? null
            );
        });

        $workshop->refresh();
        $this->syncService->syncWorkshop($workshop);

        return $workshop;
    }

    /** Списать/вернуть разницу остатка тары по товарам при неизменном складе. */
    private function applyPackageDeltas(Workshop $workshop, $before, $after): void
    {
        $oldByPid = $before->groupBy('product_id')->map(fn($g) => (float) $g->sum('quantity'));
        $newByPid = $after->groupBy('product_id')->map(fn($g) => (float) $g->sum('quantity'));

        $pids = $oldByPid->keys()->merge($newByPid->keys())->unique();
        foreach ($pids as $pid) {
            $delta = ($newByPid[$pid] ?? 0) - ($oldByPid[$pid] ?? 0);
            if (abs($delta) > 0.0001) {
                $workshop->adjustPackageStock((int) $pid, $delta);
            }
        }
    }

    /** Дельты для лога: сравнение состояния до/после по (role, product_id). */
    private function diffItemDeltas($before, $after): array
    {
        $key    = fn($i) => $i->role . '|' . $i->product_id;
        $oldMap = $before->keyBy($key);
        $newMap = $after->keyBy($key);

        $deltas = [];
        foreach ($oldMap->keys()->merge($newMap->keys())->unique() as $k) {
            $old = (float) ($oldMap[$k]->quantity ?? 0);
            $new = (float) ($newMap[$k]->quantity ?? 0);
            $delta = $new - $old;
            if (abs($delta) > 0.0001) {
                $ref = $newMap[$k] ?? $oldMap[$k];
                $deltas[] = [
                    'product_id' => $ref->product_id,
                    'role'       => $ref->role,
                    'delta'      => $delta,
                ];
            }
        }

        return $deltas;
    }

    public function delete(Workshop $workshop): void
    {
        DB::transaction(fn() => $workshop->delete());
    }

    public function markCompleted(Workshop $workshop): array
    {
        DB::transaction(function () use ($workshop) {
            $workshop->markAsCompleted();
        });

        $workshop->refresh();

        if ($workshop->hasMoySkladProcessing()) {
            $result = $this->syncService->completeProcessing($workshop->moysklad_processing_id);

            if ($result['success']) {
                $workshop->markSynced($workshop->moysklad_processing_id);
                return ['success' => true, 'message' => 'Операция закрыта.'];
            }

            $workshop->markSyncError($result['message']);
            return [
                'success' => false,
                'message' => 'Операция закрыта локально, но ошибка синхронизации с МойСклад: ' . $result['message'],
            ];
        }

        return ['success' => true, 'message' => 'Операция закрыта.'];
    }

    public function resetStatus(Workshop $workshop): bool|string
    {
        $processingId = $workshop->moysklad_processing_id;

        DB::transaction(function () use ($workshop) {
            $workshop->update([
                'status'                   => Workshop::STATUS_ACTIVE,
                'moysklad_processing_id'   => null,
                'moysklad_processing_name' => null,
                'moysklad_sync_status'     => null,
                'moysklad_sync_error'      => null,
                'synced_at'                => null,
            ]);
        });

        if ($processingId) {
            $result = $this->syncService->reactivateProcessing($processingId);
            if (!$result['success']) {
                return 'Статус сброшен локально, но ошибка синхронизации с МойСклад: ' . $result['message'];
            }
        }

        return true;
    }

    public function syncToProcessing(Workshop $workshop): array
    {
        if (!$workshop->store_id) {
            return ['success' => false, 'message' => 'Склад не указан — синхронизация невозможна.'];
        }

        $this->syncService->syncWorkshop($workshop);
        $workshop->refresh();

        return $workshop->isSynced()
            ? ['success' => true, 'message' => 'Техоперация синхронизирована с МойСклад.']
            : ['success' => false, 'message' => 'Ошибка синхронизации: ' . ($workshop->moysklad_sync_error ?? 'неизвестная ошибка')];
    }

    public function updateItemCoeff(Workshop $workshop, array $validated): void
    {
        DB::transaction(function () use ($workshop, $validated) {
            foreach ($validated['items'] as $row) {
                $item = $workshop->productItems()->with('product')->findOrFail($row['item_id']);

                $isUndercut   = !empty($row['is_undercut']);
                $isEdging     = !empty($row['is_edging']);
                $productCoeff = (float) $row['base_coeff'];
                $isSmallTile  = StoneReceptionItem::skuIsSmallTile($item->product?->sku);
                $effCoeff     = StoneReceptionItem::computeEffectiveCoeff($productCoeff, $isUndercut, $isEdging);

                $item->update([
                    'effective_cost_coeff' => $effCoeff,
                    'is_undercut'          => $isUndercut,
                    'is_edging'            => $isEdging,
                    'is_small_tile'        => $isSmallTile,
                    'worker_cost_per_m2'   => $item->product?->prodCost($effCoeff),
                    'master_cost_per_m2'   => StoneReceptionItem::computeMasterCost($isUndercut, $isSmallTile),
                ]);
            }
        });
    }

    public function refreshItemCoeffs(Workshop $workshop): void
    {
        $workshop->loadMissing('items.product');

        DB::transaction(function () use ($workshop) {
            foreach ($workshop->productItems()->with('product')->get() as $item) {
                if (!$item->product || $item->product->prod_cost_coeff === null) {
                    continue;
                }

                $productCoeff = (float) $item->product->prod_cost_coeff;
                $isUndercut   = (bool) $item->is_undercut;
                $isEdging     = (bool) $item->is_edging;
                $isSmallTile  = StoneReceptionItem::skuIsSmallTile($item->product->sku);
                $effCoeff     = StoneReceptionItem::computeEffectiveCoeff($productCoeff, $isUndercut, $isEdging);

                $item->update([
                    'effective_cost_coeff' => $effCoeff,
                    'is_small_tile'        => $isSmallTile,
                    'worker_cost_per_m2'   => $item->product->prodCost($effCoeff),
                    'master_cost_per_m2'   => StoneReceptionItem::computeMasterCost($isUndercut, $isSmallTile),
                ]);
            }
        });
    }

    private function prepareWorkshopData(array $data, bool $forCreate = true): array
    {
        $departmentId = $data['department_id']
            ?? Worker::find($data['packer_id'] ?? null)?->department_id;

        $prepared = [
            'packer_id'             => $data['packer_id'],
            'store_id'              => $data['store_id'],
            'product_store_id'      => $data['product_store_id'] ?? null,
            'department_id'         => $departmentId,
            'manual_processing_sum' => $data['manual_processing_sum'] ?? null,
            'notes'                 => $data['notes'] ?? null,
        ];

        if ($forCreate) {
            $prepared['receiver_id'] = $data['receiver_id'];
            $prepared['created_at']  = $data['manual_created_at'] ?? now();
            $prepared['updated_at']  = $data['manual_created_at'] ?? now();
        } else {
            if (isset($data['receiver_id'])) {
                $prepared['receiver_id'] = $data['receiver_id'];
            }
            $prepared['created_at'] = $data['manual_created_at'] ?? $data['original_created_at'];
            $prepared['updated_at'] = now();
        }

        return $prepared;
    }

    /**
     * Создать строки одной роли. Себестоимостные поля (зарплата/коэффициенты)
     * заполняются только для продукта (role=product) — они нужны лишь для авто-fallback
     * processingSum. Для сырья и упаковки пишем только product_id/quantity.
     */
    private function createWorkshopItems(Workshop $workshop, array $rows, string $role): void
    {
        $rows = array_values(array_filter($rows, fn($r) => (float) ($r['quantity'] ?? 0) > 0));
        if (empty($rows)) {
            return;
        }

        if ($role !== WorkshopItem::ROLE_PRODUCT) {
            foreach ($rows as $row) {
                $workshop->items()->create([
                    'product_id' => $row['product_id'],
                    'role'       => $role,
                    'quantity'   => $row['quantity'],
                ]);
            }
            return;
        }

        $productMap = Product::whereIn('id', array_column($rows, 'product_id'))->get()->keyBy('id');

        foreach ($rows as $row) {
            $workshop->items()->create(
                $this->productItemAttributes($row['product_id'], $row['quantity'], $productMap->get($row['product_id']))
            );
        }
    }

    /** Атрибуты строки продукта с зафиксированной зарплатой. */
    private function productItemAttributes(int $productId, $quantity, ?Product $prod): array
    {
        $productCoeff = (float) ($prod?->prod_cost_coeff ?? 0);
        $isSmallTile  = StoneReceptionItem::skuIsSmallTile($prod?->sku);
        $effCoeff     = StoneReceptionItem::computeEffectiveCoeff($productCoeff, false, false);

        return [
            'product_id'           => $productId,
            'role'                 => WorkshopItem::ROLE_PRODUCT,
            'quantity'             => $quantity,
            'effective_cost_coeff' => $effCoeff,
            'is_undercut'          => false,
            'is_edging'            => false,
            'is_small_tile'        => $isSmallTile,
            'worker_cost_per_m2'   => $prod?->prodCost($effCoeff),
            'master_cost_per_m2'   => StoneReceptionItem::computeMasterCost(false, $isSmallTile),
        ];
    }

    /**
     * Синхронизировать строки одной роли: обновить количество существующих,
     * создать новые, удалить отсутствующие (в рамках этой роли).
     */
    private function syncRoleItems(Workshop $workshop, array $rows, string $role): void
    {
        $rows = array_values(array_filter($rows, fn($r) => (float) ($r['quantity'] ?? 0) > 0));

        $existing      = $workshop->items()->where('role', $role)->get()->keyBy('product_id');
        $newProductIds = array_column($rows, 'product_id');

        $newIds     = array_diff($newProductIds, $existing->keys()->toArray());
        $productMap = ($role === WorkshopItem::ROLE_PRODUCT && $newIds)
            ? Product::whereIn('id', $newIds)->get()->keyBy('id')
            : collect();

        foreach ($rows as $row) {
            $productId = $row['product_id'];

            if ($existing->has($productId)) {
                $existing[$productId]->update(['quantity' => $row['quantity']]);
                continue;
            }

            if ($role === WorkshopItem::ROLE_PRODUCT) {
                $workshop->items()->create(
                    $this->productItemAttributes($productId, $row['quantity'], $productMap->get($productId))
                );
            } else {
                $workshop->items()->create([
                    'product_id' => $productId,
                    'role'       => $role,
                    'quantity'   => $row['quantity'],
                ]);
            }
        }

        $workshop->items()
            ->where('role', $role)
            ->whereNotIn('product_id', $newProductIds ?: [0])
            ->delete();
    }

    /**
     * @param  array  $itemDeltas  список ['product_id' => int, 'role' => string, 'delta' => float]
     */
    private function writeWorkshopLog(
        Workshop $workshop,
        string $type,
        float $productDelta,
        ?float $productSnapshot,
        array $itemDeltas,
        ?\DateTimeInterface $createdAt = null,
        ?int $receiverId = null
    ): void {
        $log = WorkshopLog::create([
            'workshop_id'               => $workshop->id,
            'packer_id'                 => $workshop->packer_id,
            'receiver_id'               => $receiverId ?? auth()->user()->worker_id,
            'type'                      => $type,
            'package_quantity_delta'    => $productDelta,
            'package_quantity_snapshot' => $productSnapshot,
            'created_at'                => $createdAt ?? now(),
        ]);

        if ($itemDeltas) {
            $now = now();
            WorkshopLogItem::insert(collect($itemDeltas)->map(fn($d) => [
                'workshop_log_id' => $log->id,
                'product_id'      => $d['product_id'],
                'role'            => $d['role'],
                'quantity_delta'  => $d['delta'],
                'created_at'      => $now,
                'updated_at'      => $now,
            ])->values()->toArray());
        }
    }
}
