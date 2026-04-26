<?php

namespace App\Services;

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
        $baseQuery = RawMaterialBatch::with([
            'product', 'currentStore', 'currentWorker',
            'latestMovement.fromStore', 'latestMovement.toStore',
        ]);

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

        return compact('batches', 'filterCutters', 'filterRawProducts', 'statuses');
    }

    public function getCreateFormOptions(): array
    {
        $products = Product::orderBy('name')->get();
        $stores   = Store::orderBy('name')->get();
        $workers  = Worker::orderBy('name')->get();

        $recentBatches = RawMaterialBatch::with([
            'product',
            'currentWorker',
            'movements' => fn($q) => $q->where('movement_type', 'create')->oldest(),
        ])
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

        DB::transaction(function () use ($data, $createdAt, $movedBy, &$batch, &$movement) {
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
     * Скорректировать только remaining_quantity (без изменения stocks и МойСклад).
     */
    public function adjustRemaining(RawMaterialBatch $batch, float $newRemaining): void
    {
        $batch->update(['remaining_quantity' => $newRemaining]);
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
