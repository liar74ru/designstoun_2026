<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\RawMaterialBatch;
use App\Models\RawMaterialMovement;
use App\Models\Store;
use App\Models\StoneReception;
use App\Models\Worker;
use App\Traits\ManagesStock;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class RawMaterialBatchService
{
    use ManagesStock;

    public function getIndexData(Request $request): array
    {
        $accessible = $request->user()?->accessibleDepartmentIds();

        $baseQuery = RawMaterialBatch::with([
            'product', 'currentStore', 'currentWorker', 'department',
            'latestMovement.fromStore', 'latestMovement.toStore',
        ]);

        $query = QueryBuilder::for($baseQuery)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('current_worker_id'),
                AllowedFilter::exact('product_id'),
                AllowedFilter::partial('batch_number'),
                AllowedFilter::callback('department_id', fn($q, $v) =>
                    $q->whereIn('raw_material_batches.department_id', (array) $v)),
            ])
            ->defaultSort('-created_at')
            ->allowedSorts(['batch_number', 'created_at', 'quantity']);

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($accessible !== null && !array_key_exists('department_id', $request->input('filter', []))) {
            $query->whereIn('raw_material_batches.department_id', $accessible ?: [-1]);
        }

        $batches = $query->paginate(15)->withQueryString();

        $filterCutters     = Worker::orderBy('name')->get();
        $filterRawProducts = Product::whereIn('id',
            RawMaterialBatch::distinct()->pluck('product_id')
        )->orderBy('name')->get();
        $filterDepartments  = Department::orderBy('name')->get();
        $departmentDefaults = $accessible ?? [];

        $statuses = [
            'new'      => 'Новые',
            'in_work'  => 'В работе',
            'used'     => 'Израсходованы',
            'returned' => 'Возвращены',
            'archived' => 'Архив',
        ];

        return compact(
            'batches', 'filterCutters', 'filterRawProducts',
            'filterDepartments', 'departmentDefaults', 'statuses'
        );
    }

    public function getCreateFormOptions(Request $request): array
    {
        $accessible = $request->user()?->accessibleDepartmentIds();

        $products = Product::orderBy('name')->get();
        $stores   = Store::orderBy('name')->get();
        $workers  = Worker::orderBy('name')->get();

        $recentBatches = RawMaterialBatch::with([
            'product',
            'currentWorker',
            'movements' => fn($q) => $q->where('movement_type', 'create')->oldest(),
        ])
            ->when($accessible !== null,
                fn($q) => $q->whereIn('department_id', $accessible ?: [-1]))
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        return compact('products', 'stores', 'workers', 'recentBatches');
    }

    /**
     * Создать партию + движение + скорректировать остатки.
     *
     * @return array{batch: RawMaterialBatch, movement: RawMaterialMovement}
     */
    public function create(array $data, bool $isAdmin): array
    {
        $createdAt = ($isAdmin && !empty($data['manual_created_at']))
            ? Carbon::parse($data['manual_created_at'])
            : now();

        $movedBy = auth()->user()?->worker_id ?? $data['worker_id'];

        $batch    = null;
        $movement = null;

        $departmentId = $data['department_id']
            ?? Worker::find($data['worker_id'] ?? null)?->department_id;

        DB::transaction(function () use ($data, $createdAt, $movedBy, $departmentId, &$batch, &$movement) {
            $batch = RawMaterialBatch::create([
                'product_id'         => $data['product_id'],
                'initial_quantity'   => $data['quantity'],
                'remaining_quantity' => $data['quantity'],
                'current_store_id'   => $data['to_store_id'],
                'current_worker_id'  => $data['worker_id'],
                'department_id'      => $departmentId,
                'batch_number'       => $data['batch_number'] ?? null,
                'status'             => RawMaterialBatch::STATUS_NEW,
                'created_at'         => $createdAt,
                'updated_at'         => $createdAt,
            ]);

            $movement = RawMaterialMovement::create([
                'batch_id'       => $batch->id,
                'from_store_id'  => $data['from_store_id'],
                'to_store_id'    => $data['to_store_id'],
                'from_worker_id' => null,
                'to_worker_id'   => $data['worker_id'],
                'moved_by'       => $movedBy,
                'movement_type'  => 'create',
                'quantity'       => $data['quantity'],
                'created_at'     => $createdAt,
                'updated_at'     => $createdAt,
            ]);

            $this->adjustStock($data['product_id'], $data['from_store_id'], -$data['quantity']);
            $this->adjustStock($data['product_id'], $data['to_store_id'],   +$data['quantity']);
        });

        return ['batch' => $batch, 'movement' => $movement];
    }

    /**
     * Обновить партию (товар / количество / дата).
     * Возвращает null если изменений не обнаружено.
     *
     * @return array{batch: RawMaterialBatch, newQuantity: float, newCreatedAt: Carbon|null}|null
     */
    public function update(RawMaterialBatch $batch, array $data, bool $isAdmin): ?array
    {
        $oldProductId = $batch->product_id;
        $oldQuantity  = (float) $batch->initial_quantity;
        $oldRemaining = (float) $batch->remaining_quantity;
        $newProductId = (int) $data['product_id'];
        $newQuantity  = (float) $data['quantity'];

        $usedQuantity = $oldQuantity - $oldRemaining;
        $newRemaining = $newQuantity - $usedQuantity;

        $manualDate   = $data['manual_created_at'] ?? null;
        $newCreatedAt = ($isAdmin && $manualDate)
            ? Carbon::parse($manualDate)
            : null;

        $productChanged  = $oldProductId !== $newProductId;
        $quantityChanged = abs($oldQuantity - $newQuantity) > 0.0001;
        $dateChanged     = $newCreatedAt && $newCreatedAt->ne($batch->created_at);

        if (!$productChanged && !$quantityChanged && !$dateChanged) {
            return null;
        }

        DB::transaction(function () use (
            $batch, $oldProductId, $oldRemaining,
            $newProductId, $newQuantity, $newRemaining,
            $productChanged, $quantityChanged, $newCreatedAt
        ) {
            $storeId = $batch->current_store_id;

            if ($productChanged) {
                $this->adjustStock($oldProductId, $storeId, -$oldRemaining);
                $this->adjustStock($newProductId, $storeId, +$newRemaining);
            } elseif ($quantityChanged) {
                $this->adjustStock($oldProductId, $storeId, $newRemaining - $oldRemaining);
            }

            $updateData = [
                'product_id'         => $newProductId,
                'initial_quantity'   => $newQuantity,
                'remaining_quantity' => $newRemaining,
            ];

            if ($newCreatedAt) {
                $updateData['created_at'] = $newCreatedAt;
                $batch->movements()
                    ->where('movement_type', 'create')
                    ->orderBy('created_at')
                    ->first()
                    ?->update(['created_at' => $newCreatedAt, 'updated_at' => $newCreatedAt]);
            }

            $batch->update($updateData);
        });

        return ['batch' => $batch, 'newQuantity' => $newQuantity, 'newCreatedAt' => $newCreatedAt];
    }

    /**
     * Удалить новую партию, восстановив остатки.
     * Возвращает moysklad_move_id до удаления — чтобы контроллер мог удалить Move в МойСклад.
     */
    public function deleteNew(RawMaterialBatch $batch): ?string
    {
        $originalMovement = $batch->movements()
            ->where('movement_type', 'create')
            ->whereNotNull('moysklad_move_id')
            ->orderBy('created_at')
            ->first();

        $moyskladMoveId = $originalMovement?->moysklad_move_id;

        DB::transaction(function () use ($batch) {
            if ($batch->remaining_quantity > 0) {
                $this->adjustStock($batch->product_id, $batch->current_store_id, -$batch->remaining_quantity);
            }
            $batch->movements()->delete();
            $batch->delete();
        });

        return $moyskladMoveId;
    }

    /**
     * Корректировка количества партии (+/-) с записью движения и изменением product_stocks.
     *
     * @return array{batch: RawMaterialBatch, movement: RawMaterialMovement, newRemaining: float}
     */
    public function adjust(RawMaterialBatch $batch, float $delta, ?string $notes, bool $isAdmin, ?string $manualDate): array
    {
        $newRemaining  = (float) $batch->remaining_quantity + $delta;
        $batchStoreId  = $batch->current_store_id;

        $createdAt = ($isAdmin && $manualDate)
            ? Carbon::parse($manualDate)
            : now();

        $movement = null;

        DB::transaction(function () use ($batch, $delta, $newRemaining, $notes, $batchStoreId, $createdAt, &$movement) {
            $batch->update([
                'remaining_quantity' => $newRemaining,
                'initial_quantity'   => (float) $batch->initial_quantity + $delta,
            ]);

            $this->adjustStock($batch->product_id, $batchStoreId, $delta);

            $envStoreId     = env('DEFAULT_STORE_ID') ?: null;
            $defaultStoreId = ($envStoreId && Store::where('id', $envStoreId)->exists())
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

        return ['batch' => $batch, 'movement' => $movement, 'newRemaining' => $newRemaining];
    }

    /**
     * Перевести партию в статус «Израсходована», каскадом завершить активные приёмки.
     */
    public function markAsUsed(RawMaterialBatch $batch): void
    {
        DB::transaction(function () use ($batch) {
            $batch->update(['status' => RawMaterialBatch::STATUS_USED]);

            $batch->receptions()
                ->where('status', StoneReception::STATUS_ACTIVE)
                ->each(fn($r) => $r->update(['status' => StoneReception::STATUS_COMPLETED]));
        });
    }

    /**
     * Вернуть партию из «Израсходована» в «В работе», каскадом активировать завершённые приёмки.
     */
    public function markAsInWork(RawMaterialBatch $batch): void
    {
        DB::transaction(function () use ($batch) {
            $batch->update(['status' => RawMaterialBatch::STATUS_IN_WORK]);

            $batch->receptions()
                ->where('status', StoneReception::STATUS_COMPLETED)
                ->each(fn($r) => $r->update(['status' => StoneReception::STATUS_ACTIVE]));
        });
    }

    /**
     * Отправить партию в архив.
     */
    public function archive(RawMaterialBatch $batch): void
    {
        $batch->update(['status' => 'archived']);
    }

    /**
     * Удалить партию (гибкое удаление), восстановив остатки если нужно.
     */
    public function delete(RawMaterialBatch $batch): void
    {
        DB::transaction(function () use ($batch) {
            if ($batch->isWorkable() && $batch->remaining_quantity > 0) {
                $this->adjustStock($batch->product_id, $batch->current_store_id, -$batch->remaining_quantity);
            }
            $batch->movements()->delete();
            $batch->delete();
        });
    }

    /**
     * Разделить партию на родительскую (с фактически использованным объёмом) и дочернюю
     * (с остатком). Используется при создании новой приёмки на партии, где уже есть
     * завершённые приёмки.
     *
     * Реализован как тонкая обёртка над transfer() с тем же работником и пост-обработкой
     * статуса родителя и батч-номера дочерней.
     *
     * @return array{newBatch: RawMaterialBatch, newMovement: RawMaterialMovement}
     */
    public function split(RawMaterialBatch $batch): array
    {
        $qty = (float) $batch->remaining_quantity;

        if ($qty <= 0) {
            throw new \RuntimeException('Партия не имеет остатка для разделения');
        }

        $workerId = $batch->current_worker_id;
        if (!$workerId) {
            throw new \RuntimeException('У партии не назначен работник для split');
        }

        return DB::transaction(function () use ($batch, $qty, $workerId) {
            $result = $this->transfer($batch, [
                'quantity'     => $qty,
                'to_worker_id' => $workerId,
            ]);

            $batch->update(['status' => RawMaterialBatch::STATUS_USED]);

            $result['newBatch']->update([
                'batch_number' => ($batch->batch_number ?? $batch->id) . '/' . $batch->id,
                'notes'        => 'Выделена из партии №' . ($batch->batch_number ?? $batch->id),
            ]);

            return $result;
        });
    }

    /**
     * Передать часть партии другому пильщику: создать дочернюю партию, уменьшить родительскую.
     *
     * @return array{newBatch: RawMaterialBatch, newMovement: RawMaterialMovement}
     */
    public function transfer(RawMaterialBatch $batch, array $data): array
    {
        $qty = (float) $data['quantity'];

        $originalMovement = $batch->movements()
            ->where('movement_type', 'create')
            ->orderBy('created_at')
            ->first();

        $toStoreId   = $originalMovement?->to_store_id   ?? $batch->current_store_id;
        $fromStoreId = $originalMovement?->from_store_id ?? $batch->current_store_id;

        $targetWorker = Worker::findOrFail($data['to_worker_id']);
        $newBatch     = null;
        $newMovement  = null;

        DB::transaction(function () use ($batch, $data, $qty, $toStoreId, $fromStoreId, $targetWorker, &$newBatch, &$newMovement) {
            $batch->update([
                'initial_quantity'   => (float) $batch->initial_quantity - $qty,
                'remaining_quantity' => (float) $batch->remaining_quantity - $qty,
            ]);

            $newBatch = RawMaterialBatch::create([
                'product_id'         => $batch->product_id,
                'initial_quantity'   => $qty,
                'remaining_quantity' => $qty,
                'current_store_id'   => $toStoreId,
                'current_worker_id'  => $data['to_worker_id'],
                'department_id'      => $targetWorker->department_id,
                'batch_number'       => ($batch->batch_number ?? $batch->id) . '-' . $targetWorker->name,
                'status'             => RawMaterialBatch::STATUS_NEW,
                'notes'              => 'Передана от партии №' . ($batch->batch_number ?? $batch->id),
            ]);

            $newMovement = RawMaterialMovement::create([
                'batch_id'       => $newBatch->id,
                'from_store_id'  => $fromStoreId,
                'to_store_id'    => $toStoreId,
                'from_worker_id' => $batch->current_worker_id,
                'to_worker_id'   => $data['to_worker_id'],
                'moved_by'       => auth()->user()?->worker_id ?? null,
                'movement_type'  => 'create',
                'quantity'       => $qty,
            ]);
        });

        return ['newBatch' => $newBatch, 'newMovement' => $newMovement];
    }

    /**
     * Вернуть часть партии на склад: создать дочернюю с типом returned, уменьшить родительскую.
     *
     * @return array{newBatch: RawMaterialBatch, movement: RawMaterialMovement, oldStore: string, toStoreId: string, qty: float}
     */
    public function returnToStore(RawMaterialBatch $batch, array $data): array
    {
        $qty       = (float) $data['quantity'];
        $oldStore  = $batch->current_store_id;
        $oldWorker = $batch->current_worker_id;
        $newBatch  = null;
        $movement  = null;

        DB::transaction(function () use ($batch, $data, $qty, $oldStore, $oldWorker, &$newBatch, &$movement) {
            $newRemaining = (float) $batch->remaining_quantity - $qty;
            $batch->remaining_quantity = $newRemaining;
            $batch->status = $newRemaining > 0
                ? RawMaterialBatch::STATUS_CONFIRMED
                : RawMaterialBatch::STATUS_IN_WORK;
            $batch->save();

            $newBatch = RawMaterialBatch::create([
                'product_id'         => $batch->product_id,
                'initial_quantity'   => $qty,
                'remaining_quantity' => $qty,
                'current_store_id'   => $data['to_store_id'],
                'current_worker_id'  => null,
                'department_id'      => $batch->department_id,
                'status'             => RawMaterialBatch::STATUS_RETURNED,
                'notes'              => 'Создана от партии №' . ($batch->batch_number ?? $batch->id),
            ]);

            $movement = RawMaterialMovement::create([
                'batch_id'       => $newBatch->id,
                'from_store_id'  => $oldStore,
                'to_store_id'    => $data['to_store_id'],
                'from_worker_id' => $oldWorker,
                'to_worker_id'   => null,
                'moved_by'       => auth()->user()?->worker_id ?? null,
                'movement_type'  => 'return_to_store',
                'quantity'       => $qty,
            ]);

            $this->adjustStock($batch->product_id, $oldStore, -$qty);
            $this->adjustStock($batch->product_id, $data['to_store_id'], +$qty);
        });

        return [
            'newBatch'  => $newBatch,
            'movement'  => $movement,
            'oldStore'  => $oldStore,
            'toStoreId' => $data['to_store_id'],
            'qty'       => $qty,
        ];
    }

    /**
     * Сгенерировать номер партии для пильщика на текущей ISO-неделе.
     * Формат: ГГ-НН-Фамилия-ПП
     */
    public function generateBatchNumber(Worker $worker): string
    {
        $year  = now()->format('y');
        $week  = now()->format('W');
        $name  = explode(' ', trim($worker->name))[0];

        $count = RawMaterialBatch::where('current_worker_id', $worker->id)
            ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->count();

        return "{$year}-{$week}-{$name}-" . str_pad($count + 1, 2, '0', STR_PAD_LEFT);
    }
}
