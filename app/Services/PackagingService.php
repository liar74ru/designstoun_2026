<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Packaging;
use App\Models\PackagingItem;
use App\Models\PackagingLog;
use App\Models\PackagingLogItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoneReceptionItem;
use App\Models\Worker;
use App\Services\Moysklad\PackagingSyncService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class PackagingService
{
    public function __construct(
        private PackagingSyncService $syncService,
    ) {}

    public function getFormOptions(?Packaging $packaging = null): array
    {
        $packers = Worker::where(function ($q) {
            foreach (['Мастер', 'Администратор'] as $pos) {
                $q->orWhereJsonContains('positions', $pos);
            }
        })->orderBy('name')->get();

        $masterWorkers = Worker::where(function ($q) {
            foreach (['Мастер', 'Администратор'] as $pos) {
                $q->orWhereJsonContains('positions', $pos);
            }
        })->orderBy('name')->get();

        $data = [
            'packers'         => $packers,
            'masterWorkers'   => $masterWorkers,
            'workers'         => Worker::orderBy('name')->get(),
            'products'        => Product::orderBy('name')->get(),
            'packageProducts' => Product::where('sku', 'like', '07-03%')->orderBy('name')->get(),
            'stores'          => Store::orderBy('name')->get(),
            'defaultStore'    => Store::getDefault(),
        ];

        if ($packaging) {
            $packaging->load('items.product', 'packageProduct', 'packer.department');
            $data['packaging'] = $packaging;
        }

        return $data;
    }

    public function getFilterData(?Request $request = null): array
    {
        $filterProducts = Product::whereIn('id',
            PackagingItem::distinct()->pluck('product_id')
        )->orderBy('name')->get();

        $filterPackers = Worker::whereIn('id',
            Packaging::distinct()->pluck('packer_id')
        )->orderBy('name')->get();

        $filterPackageProducts = Product::whereIn('id',
            Packaging::distinct()->pluck('package_product_id')
        )->orderBy('name')->get();

        $filterDepartments  = Department::orderBy('name')->get();
        $departmentDefaults = $request?->user()?->accessibleDepartmentIds() ?? [];

        return compact(
            'filterProducts', 'filterPackers', 'filterPackageProducts',
            'filterDepartments', 'departmentDefaults'
        );
    }

    public function getFilteredPackagings(Request $request): LengthAwarePaginator
    {
        $accessible = $request->user()?->accessibleDepartmentIds();

        return QueryBuilder::for(Packaging::class)
            ->allowedFilters([
                AllowedFilter::callback('status', function ($query, $value) {
                    $query->whereIn('status', is_array($value) ? $value : [$value]);
                }),
                AllowedFilter::callback('sync_status', function ($query, $value) {
                    $query->whereIn('moysklad_sync_status', is_array($value) ? $value : [$value]);
                }),
                AllowedFilter::callback('product_id', function ($query, $value) {
                    $query->whereHas('items', fn($q) => $q->where('product_id', $value));
                }),
                AllowedFilter::callback('department_id', function ($query, $value) {
                    $query->whereIn('packagings.department_id', (array) $value);
                }),
                AllowedFilter::exact('packer_id'),
                AllowedFilter::exact('package_product_id'),
            ])
            ->with(['packer', 'receiver', 'store', 'items.product', 'packageProduct', 'department'])
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
                fn($q) => $q->whereIn('packagings.department_id', $accessible ?: [-1])
            )
            ->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();
    }

    public function getDefaultStoreForPacker(?Worker $packer): ?Store
    {
        if (!$packer) {
            return Store::getDefault();
        }
        $packer->loadMissing('department.defaultProductionStore');
        return $packer->department?->defaultProductionStore ?? Store::getDefault();
    }

    public function create(array $data, bool $isAdmin, ?string $processingName = null): Packaging
    {
        if ($isAdmin && !empty($data['manual_created_at'])) {
            $data['manual_created_at'] = Carbon::parse($data['manual_created_at']);
        }

        $packaging = null;

        DB::transaction(function () use ($data, &$packaging) {
            $packaging = Packaging::create($this->preparePackagingData($data));
            $this->createPackagingItems($packaging, $data['products']);

            $packaging->load('items');
            $itemDeltas = $packaging->items
                ->mapWithKeys(fn($i) => [$i->product_id => (float) $i->quantity])
                ->toArray();

            $this->writePackagingLog(
                $packaging,
                PackagingLog::TYPE_CREATED,
                (float) $packaging->package_quantity,
                (float) $packaging->package_quantity,
                $itemDeltas,
                $packaging->created_at,
                $data['receiver_id'] ?? null
            );
        });

        $this->syncService->syncPackaging($packaging, $processingName);

        return $packaging;
    }

    public function update(Packaging $packaging, array $data, bool $isAdmin): Packaging
    {
        $packageDelta = (float) ($data['package_quantity_delta'] ?? 0);

        if ($isAdmin && !empty($data['manual_created_at'])) {
            $data['manual_created_at'] = Carbon::parse($data['manual_created_at']);
        }
        $data['original_created_at'] = $packaging->created_at;

        DB::transaction(function () use ($packaging, $data, $packageDelta) {
            $preSaveItems = $packaging->items()
                ->get()
                ->pluck('quantity', 'product_id')
                ->map(fn($q) => (float) $q)
                ->toArray();

            $oldPackageQty = (float) $packaging->package_quantity;
            $newPackageQty = $oldPackageQty + $packageDelta;
            if ($newPackageQty < 0) {
                throw new \Exception('Количество тары не может быть отрицательным');
            }

            $packaging->update($this->preparePackagingData($data, false, $newPackageQty));

            // Корректируем складской остаток на дельту тары (booted-хук срабатывает только на created/deleted).
            if (abs($packageDelta) > 0.0001) {
                $packaging->adjustPackageStock($packageDelta);
            }

            $this->updatePackagingItems($packaging, $data['products']);

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

            if (empty($deltas) && abs($packageDelta) < 0.0001) {
                return;
            }

            $logDate = $data['manual_created_at'] ?? now();
            $this->writePackagingLog(
                $packaging,
                PackagingLog::TYPE_UPDATED,
                $packageDelta,
                $newPackageQty,
                $deltas,
                $logDate,
                $data['receiver_id'] ?? null
            );
        });

        $packaging->refresh();
        $this->syncService->syncPackaging($packaging);

        return $packaging;
    }

    public function delete(Packaging $packaging): void
    {
        DB::transaction(fn() => $packaging->delete());
    }

    public function markCompleted(Packaging $packaging): array
    {
        DB::transaction(function () use ($packaging) {
            $packaging->markAsCompleted();
        });

        $packaging->refresh();

        if ($packaging->hasMoySkladProcessing()) {
            $result = $this->syncService->completeProcessing($packaging->moysklad_processing_id);

            if ($result['success']) {
                $packaging->markSynced($packaging->moysklad_processing_id);
                return ['success' => true, 'message' => 'Упаковка закрыта.'];
            }

            $packaging->markSyncError($result['message']);
            return [
                'success' => false,
                'message' => 'Упаковка закрыта локально, но ошибка синхронизации с МойСклад: ' . $result['message'],
            ];
        }

        return ['success' => true, 'message' => 'Упаковка закрыта.'];
    }

    public function resetStatus(Packaging $packaging): bool|string
    {
        $processingId = $packaging->moysklad_processing_id;

        DB::transaction(function () use ($packaging) {
            $packaging->update([
                'status'                   => Packaging::STATUS_ACTIVE,
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

    public function syncToProcessing(Packaging $packaging): array
    {
        if (!$packaging->store_id) {
            return ['success' => false, 'message' => 'Склад не указан — синхронизация невозможна.'];
        }

        $this->syncService->syncPackaging($packaging);
        $packaging->refresh();

        return $packaging->isSynced()
            ? ['success' => true, 'message' => 'Техоперация синхронизирована с МойСклад.']
            : ['success' => false, 'message' => 'Ошибка синхронизации: ' . ($packaging->moysklad_sync_error ?? 'неизвестная ошибка')];
    }

    public function updateItemCoeff(Packaging $packaging, array $validated): void
    {
        $packaging->loadMissing('packageProduct');
        $packageCoeff = (float) ($packaging->packageProduct?->prod_cost_coeff ?? 0);

        DB::transaction(function () use ($packaging, $validated, $packageCoeff) {
            foreach ($validated['items'] as $row) {
                $item = $packaging->items()->with('product')->findOrFail($row['item_id']);

                $isUndercut   = !empty($row['is_undercut']);
                $productCoeff = (float) $row['base_coeff'];
                $isSmallTile  = StoneReceptionItem::skuIsSmallTile($item->product?->sku);
                $effCoeff     = StoneReceptionItem::computeEffectiveCoeff($productCoeff, $isUndercut);

                $item->update([
                    'effective_cost_coeff' => $effCoeff,
                    'is_undercut'          => $isUndercut,
                    'is_small_tile'        => $isSmallTile,
                    'worker_cost_per_m2'   => PackagingItem::computePackerCost($productCoeff, $packageCoeff),
                    'master_cost_per_m2'   => StoneReceptionItem::computeMasterCost($isUndercut, $isSmallTile),
                ]);
            }
        });
    }

    public function refreshItemCoeffs(Packaging $packaging): void
    {
        $packaging->loadMissing('items.product', 'packageProduct');
        $packageCoeff = (float) ($packaging->packageProduct?->prod_cost_coeff ?? 0);

        DB::transaction(function () use ($packaging, $packageCoeff) {
            foreach ($packaging->items as $item) {
                if (!$item->product || $item->product->prod_cost_coeff === null) {
                    continue;
                }

                $productCoeff = (float) $item->product->prod_cost_coeff;
                $isUndercut   = (bool) $item->is_undercut;
                $isSmallTile  = StoneReceptionItem::skuIsSmallTile($item->product->sku);
                $effCoeff     = StoneReceptionItem::computeEffectiveCoeff($productCoeff, $isUndercut);

                $item->update([
                    'effective_cost_coeff' => $effCoeff,
                    'is_small_tile'        => $isSmallTile,
                    'worker_cost_per_m2'   => PackagingItem::computePackerCost($productCoeff, $packageCoeff),
                    'master_cost_per_m2'   => StoneReceptionItem::computeMasterCost($isUndercut, $isSmallTile),
                ]);
            }
        });
    }

    private function preparePackagingData(array $data, bool $forCreate = true, ?float $packageQuantityOverride = null): array
    {
        $departmentId = $data['department_id']
            ?? Worker::find($data['packer_id'] ?? null)?->department_id;

        $prepared = [
            'packer_id'          => $data['packer_id'],
            'store_id'           => $data['store_id'],
            'department_id'      => $departmentId,
            'package_product_id' => $data['package_product_id'],
            'notes'              => $data['notes'] ?? null,
        ];

        if ($packageQuantityOverride !== null) {
            $prepared['package_quantity'] = $packageQuantityOverride;
        } elseif (isset($data['package_quantity'])) {
            $prepared['package_quantity'] = $data['package_quantity'];
        }

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

    private function createPackagingItems(Packaging $packaging, array $products): void
    {
        $packaging->loadMissing('packageProduct');
        $packageCoeff = (float) ($packaging->packageProduct?->prod_cost_coeff ?? 0);

        $productIds = array_column($products, 'product_id');
        $productMap = Product::whereIn('id', $productIds)->get()->keyBy('id');

        foreach ($products as $product) {
            $prod         = $productMap->get($product['product_id']);
            $productCoeff = (float) ($prod?->prod_cost_coeff ?? 0);
            $isUndercut   = !empty($product['is_undercut']);
            $isSmallTile  = StoneReceptionItem::skuIsSmallTile($prod?->sku);
            $effCoeff     = StoneReceptionItem::computeEffectiveCoeff($productCoeff, $isUndercut);

            $packaging->items()->create([
                'product_id'           => $product['product_id'],
                'quantity'             => $product['quantity'],
                'effective_cost_coeff' => $effCoeff,
                'is_undercut'          => $isUndercut,
                'is_small_tile'        => $isSmallTile,
                'worker_cost_per_m2'   => PackagingItem::computePackerCost($productCoeff, $packageCoeff),
                'master_cost_per_m2'   => StoneReceptionItem::computeMasterCost($isUndercut, $isSmallTile),
            ]);
        }
    }

    private function updatePackagingItems(Packaging $packaging, array $products): void
    {
        $products = array_values(array_filter($products, fn($p) => (float) ($p['quantity'] ?? 0) > 0));

        $existingItems = $packaging->items()->get()->keyBy('product_id');
        $newProductIds = array_column($products, 'product_id');

        $newIds     = array_diff($newProductIds, $existingItems->keys()->toArray());
        $productMap = $newIds
            ? Product::whereIn('id', $newIds)->get()->keyBy('id')
            : collect();

        $packaging->loadMissing('packageProduct');
        $packageCoeff = (float) ($packaging->packageProduct?->prod_cost_coeff ?? 0);

        foreach ($products as $product) {
            $productId = $product['product_id'];

            if ($existingItems->has($productId)) {
                $existingItems[$productId]->update(['quantity' => $product['quantity']]);
            } else {
                $prod         = $productMap->get($productId);
                $productCoeff = (float) ($prod?->prod_cost_coeff ?? 0);
                $isUndercut   = !empty($product['is_undercut']);
                $isSmallTile  = StoneReceptionItem::skuIsSmallTile($prod?->sku);
                $effCoeff     = StoneReceptionItem::computeEffectiveCoeff($productCoeff, $isUndercut);

                $packaging->items()->create([
                    'product_id'           => $productId,
                    'quantity'             => $product['quantity'],
                    'effective_cost_coeff' => $effCoeff,
                    'is_undercut'          => $isUndercut,
                    'is_small_tile'        => $isSmallTile,
                    'worker_cost_per_m2'   => PackagingItem::computePackerCost($productCoeff, $packageCoeff),
                    'master_cost_per_m2'   => StoneReceptionItem::computeMasterCost($isUndercut, $isSmallTile),
                ]);
            }
        }

        $packaging->items()->whereNotIn('product_id', $newProductIds)->delete();
    }

    private function writePackagingLog(
        Packaging $packaging,
        string $type,
        float $packageDelta,
        ?float $packageSnapshot,
        array $itemDeltas,
        ?\DateTimeInterface $createdAt = null,
        ?int $receiverId = null
    ): void {
        $log = PackagingLog::create([
            'packaging_id'              => $packaging->id,
            'packer_id'                 => $packaging->packer_id,
            'receiver_id'               => $receiverId ?? auth()->user()->worker_id,
            'type'                      => $type,
            'package_quantity_delta'    => $packageDelta,
            'package_quantity_snapshot' => $packageSnapshot,
            'created_at'                => $createdAt ?? now(),
        ]);

        if ($itemDeltas) {
            $now = now();
            PackagingLogItem::insert(collect($itemDeltas)->map(fn($delta, $productId) => [
                'packaging_log_id' => $log->id,
                'product_id'       => $productId,
                'quantity_delta'   => $delta,
                'created_at'       => $now,
                'updated_at'       => $now,
            ])->values()->toArray());
        }
    }
}
