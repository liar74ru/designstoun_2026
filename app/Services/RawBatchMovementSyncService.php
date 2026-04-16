<?php

namespace App\Services;

use App\Models\RawMaterialBatch;
use App\Models\RawMaterialMovement;
use App\Support\DocumentNaming;
use Illuminate\Support\Facades\Log;

class RawBatchMovementSyncService
{
    public function __construct(
        private MoySkladMoveService $moveService,
    ) {}

    /**
     * Синхронизирует создание партии с МойСклад.
     * Вызывается после store().
     */
    public function syncCreated(RawMaterialBatch $batch, RawMaterialMovement $movement): void
    {
        $product = $batch->product;
        if (!$product->moysklad_id) {
            Log::warning('Товар не синхронизирован с МойСклад', ['product_id' => $product->id]);
            return;
        }

        try {
            $batchNumber = $batch->batch_number ?? $batch->id;
            $moveData = [
                'from_store_id' => $movement->from_store_id,
                'to_store_id'   => $movement->to_store_id,
                'products'      => [['product_id' => $product->moysklad_id, 'quantity' => (float) $batch->initial_quantity]],
                'name'          => 'Партия: ' . $batchNumber,
                'description'   => 'Автоматическое перемещение из системы',
                'external_id'   => 'movement_' . $movement->id,
                'created_at'    => $batch->created_at,
            ];

            $result = $this->moveService->createMove($moveData);

            if (!$result['success'] && $result['code'] === 'duplicate_name') {
                $batchNumber      = DocumentNaming::nextSuffix($batchNumber);
                $moveData['name'] = 'Партия: ' . $batchNumber;
                $result           = $this->moveService->createMove($moveData);

                if ($result['success']) {
                    $batch->update(['batch_number' => $batchNumber]);
                }
            }

            if ($result['success']) {
                $movement->update(['moysklad_move_id' => $result['move_id'], 'moysklad_synced' => true]);
                $batch->markSynced($result['move_id'], $moveData['name']);
                Log::info('Перемещение синхронизировано с МойСклад', ['move_id' => $result['move_id']]);
            } else {
                $batch->markSyncError($result['message']);
                Log::warning('Ошибка синхронизации перемещения с МойСклад', ['error' => $result['message']]);
            }
        } catch (\Exception $e) {
            $batch->markSyncError($e->getMessage());
            Log::error('Исключение при создании перемещения в МойСклад', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Синхронизирует возврат части партии на склад.
     * Вызывается после return().
     */
    public function syncReturned(
        RawMaterialBatch $newBatch,
        RawMaterialMovement $movement,
        string $fromStoreId,
        string $toStoreId,
        float $qty
    ): void {
        $product = $newBatch->product;
        if (!$product->moysklad_id) {
            Log::warning('Товар не синхронизирован с МойСклад при возврате', [
                'product_id' => $product->id,
                'batch_id'   => $newBatch->id,
            ]);
            return;
        }

        try {
            $moveData = [
                'from_store_id' => $fromStoreId,
                'to_store_id'   => $toStoreId,
                'products'      => [['product_id' => $product->moysklad_id, 'quantity' => $qty]],
                'name'          => 'Возврат партии: ' . ($newBatch->batch_number ?? '№' . $newBatch->id),
                'description'   => 'Автоматический возврат партии на склад из системы',
                'external_id'   => 'movement_' . $movement->id . '_return',
            ];

            $result = $this->moveService->createMove($moveData);

            if ($result['success']) {
                $movement->update(['moysklad_move_id' => $result['move_id'], 'moysklad_synced' => true]);
                Log::info('Возврат партии синхронизирован с МойСклад', [
                    'move_id'  => $result['move_id'],
                    'batch_id' => $newBatch->id,
                ]);
            } else {
                Log::warning('Ошибка синхронизации возврата партии с МойСклад', [
                    'error'    => $result['message'],
                    'batch_id' => $newBatch->id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Исключение при создании перемещения в МойСклад для возврата партии', [
                'error'    => $e->getMessage(),
                'batch_id' => $newBatch->id,
            ]);
        }
    }

    /**
     * Синхронизирует редактирование партии (товар/количество/дата).
     * Вызывается после update().
     */
    public function syncEdited(
        RawMaterialBatch $batch,
        float $newQuantity,
        ?\Carbon\Carbon $newCreatedAt = null
    ): void {
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
            // Ищем первичное движение — у обычной партии это 'create',
            // у партии из передачи — 'transfer_to_worker'.
            $originalMovement = $batch->movements()
                ->whereIn('movement_type', ['create', 'transfer_to_worker'])
                ->whereNotNull('moysklad_move_id')
                ->orderBy('created_at')
                ->first();

            if (!$originalMovement) {
                Log::info('Нет синхронизированного перемещения для обновления', ['batch_id' => $batch->id]);
                return;
            }

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

            $result = $this->moveService->updateMove($originalMovement->moysklad_move_id, $updateData);

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
        } catch (\Exception $e) {
            Log::error('Исключение при синхронизации изменений партии с МойСклад', [
                'error'    => $e->getMessage(),
                'batch_id' => $batch->id,
            ]);
        }
    }

    /**
     * Синхронизирует корректировку количества (+/-) партии.
     * Вызывается после adjust().
     */
    public function syncAdjusted(
        RawMaterialBatch $batch,
        RawMaterialMovement $movement,
        float $delta,
        float $newRemaining
    ): void {
        $product = $batch->product;
        if (!$product?->moysklad_id) {
            return;
        }

        try {
            $originalMovement = $batch->movements()
                ->whereIn('movement_type', ['create', 'transfer_to_worker'])
                ->whereNotNull('moysklad_move_id')
                ->orderBy('created_at')
                ->first();

            if (!$originalMovement?->moysklad_move_id) {
                Log::warning('Корректировка без синхронизации МойСклад — исходное перемещение не найдено', [
                    'batch_id' => $batch->id,
                ]);
                return;
            }

            $originalQty = (float) $originalMovement->quantity;
            $newTotalQty = $originalQty + $delta;

            if ($newTotalQty <= 0) {
                $newTotalQty = 0.001;
                Log::warning('Корректировка обнулила перемещение — выставлено минимальное значение', [
                    'batch_id'     => $batch->id,
                    'original_qty' => $originalQty,
                    'delta'        => $delta,
                ]);
            }

            $updateData = [
                'from_store_id' => $originalMovement->from_store_id,
                'to_store_id'   => $originalMovement->to_store_id,
                'products'      => [['product_id' => $product->moysklad_id, 'quantity' => $newTotalQty]],
                'name'          => 'Партия: ' . ($batch->batch_number ?? '№' . $batch->id),
                'description'   => 'Скорректировано. Новый остаток: ' . number_format($newRemaining, 3) . ' м³',
            ];

            $result = $this->moveService->updateMove($originalMovement->moysklad_move_id, $updateData);

            if ($result['success']) {
                $originalMovement->update(['quantity' => $newTotalQty]);
                $movement->update([
                    'moysklad_move_id' => $originalMovement->moysklad_move_id,
                    'moysklad_synced'  => true,
                ]);
                Log::info('Перемещение обновлено в МойСклад', [
                    'move_id'  => $originalMovement->moysklad_move_id,
                    'batch_id' => $batch->id,
                    'old_qty'  => $originalQty,
                    'delta'    => $delta,
                    'new_qty'  => $newTotalQty,
                ]);
            } else {
                Log::warning('Ошибка обновления перемещения в МойСклад', [
                    'error'    => $result['message'],
                    'batch_id' => $batch->id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Исключение при синхронизации корректировки с МойСклад', [
                'error'    => $e->getMessage(),
                'batch_id' => $batch->id,
            ]);
        }
    }

    /**
     * Синхронизирует/пересинхронизирует основное перемещение партии с МойСклад.
     * Используется для ретрая из контроллера.
     * Возвращает ['success' => bool, 'message' => string].
     */
    public function syncBatchMove(RawMaterialBatch $batch): array
    {
        $product = $batch->product;
        if (!$product?->moysklad_id) {
            $err = 'Товар не синхронизирован с МойСклад';
            $batch->markSyncError($err);
            return ['success' => false, 'message' => $err];
        }

        $movement = $batch->movements()
            ->whereIn('movement_type', ['create', 'transfer_to_worker'])
            ->orderBy('created_at')
            ->first();

        if (!$movement) {
            $err = 'Нет перемещения для синхронизации';
            $batch->markSyncError($err);
            return ['success' => false, 'message' => $err];
        }

        try {
            $batchNumber = $batch->batch_number ?? $batch->id;
            $moveName    = 'Партия: ' . $batchNumber;

            if ($movement->moysklad_move_id) {
                $result = $this->moveService->updateMove($movement->moysklad_move_id, [
                    'from_store_id' => $movement->from_store_id,
                    'to_store_id'   => $movement->to_store_id,
                    'products'      => [['product_id' => $product->moysklad_id, 'quantity' => (float) $batch->initial_quantity]],
                    'name'          => $moveName,
                ]);
                $moveId = $movement->moysklad_move_id;
            } else {
                $moveData = [
                    'from_store_id' => $movement->from_store_id,
                    'to_store_id'   => $movement->to_store_id,
                    'products'      => [['product_id' => $product->moysklad_id, 'quantity' => (float) $batch->initial_quantity]],
                    'name'          => $moveName,
                    'description'   => 'Автоматическое перемещение из системы',
                    'external_id'   => 'movement_' . $movement->id,
                    'created_at'    => $batch->created_at,
                ];
                $result = $this->moveService->createMove($moveData);

                if (!$result['success'] && $result['code'] === 'duplicate_name') {
                    $moveName         = DocumentNaming::nextSuffix($moveName);
                    $moveData['name'] = $moveName;
                    $result           = $this->moveService->createMove($moveData);
                }

                $moveId = $result['move_id'] ?? null;

                if ($result['success'] && $moveId) {
                    $movement->update(['moysklad_move_id' => $moveId, 'moysklad_synced' => true]);
                }
            }

            if ($result['success']) {
                $batch->markSynced($moveId, $moveName);
                Log::info('Партия синхронизирована с МойСклад (retry)', [
                    'batch_id' => $batch->id,
                    'move_id'  => $moveId,
                ]);
                return ['success' => true, 'message' => 'Синхронизировано'];
            }

            $batch->markSyncError($result['message']);
            return ['success' => false, 'message' => $result['message']];

        } catch (\Exception $e) {
            $batch->markSyncError($e->getMessage());
            Log::error('Исключение при синхронизации партии с МойСклад', [
                'error'    => $e->getMessage(),
                'batch_id' => $batch->id,
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Обновляет количество в оригинальном перемещении родительской партии.
     * Вызывается после transfer() и return() — когда у родительской партии
     * изменился remaining_quantity или initial_quantity.
     *
     * @param float|null $quantity Если null — используется remaining_quantity партии.
     */
    public function updateParentMove(RawMaterialBatch $batch, ?float $quantity = null): void
    {
        $product = $batch->product;
        if (!$product?->moysklad_id) {
            return;
        }

        $batch->refresh();
        $qty = $quantity ?? (float) $batch->remaining_quantity;

        try {
            $originalMovement = $batch->movements()
                ->where('movement_type', 'create')
                ->orderBy('created_at')
                ->first();

            // Берём move_id из движения; если там null — fallback на batch.moysklad_processing_id
            $moveId = $originalMovement?->moysklad_move_id ?? $batch->moysklad_processing_id;

            if (!$moveId) {
                Log::info('Нет MoySklad перемещения для обновления родительской партии', [
                    'batch_id' => $batch->id,
                ]);
                return;
            }

            $result = $this->moveService->updateMove($moveId, [
                'from_store_id' => $originalMovement?->from_store_id,
                'to_store_id'   => $originalMovement?->to_store_id,
                'products'      => [['product_id' => $product->moysklad_id, 'quantity' => $qty]],
            ]);

            if (!$result['success']) {
                Log::warning('Ошибка обновления перемещения родительской партии', [
                    'error'    => $result['message'],
                    'batch_id' => $batch->id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Исключение при обновлении перемещения родительской партии', [
                'error'    => $e->getMessage(),
                'batch_id' => $batch->id,
            ]);
        }
    }
}
