<?php

namespace App\Traits;

use App\Models\RawMaterialBatch;
use App\Models\StoneReception;

trait HandlesBatchStock
{
    /**
     * Получает партии сырья доступные для производства (статусы: new, in_work)
     */
    protected function getActiveBatches($workerId = null)
    {
        // Партии со статусом 'in_work' показываются независимо от remaining_quantity:
        // нулевые партии остаются в списке для ручного перевода в «Израсходована».
        $query = RawMaterialBatch::with(['product', 'currentWorker'])
            ->whereIn('status', [RawMaterialBatch::STATUS_NEW, RawMaterialBatch::STATUS_IN_WORK]);

        if ($workerId) {
            $query->where('current_worker_id', $workerId);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Проверяет достаточно ли сырья в партии
     */
    protected function checkBatchStock(int $batchId, float $requiredQuantity): bool
    {
        $batch = RawMaterialBatch::find($batchId);
        return $batch && $batch->remaining_quantity >= $requiredQuantity;
    }

    /**
     * Обрабатывает изменения в партии сырья при обновлении приёмки.
     * Возвращает старое количество в старую партию, списывает новое из новой.
     * Статус партии не меняется — только вручную через markAsUsed/markAsInWork.
     */
    protected function handleBatchChanges(StoneReception $reception, array $newData): void
    {
        $oldBatchId = $reception->raw_material_batch_id;
        $oldQty     = (float) $reception->raw_quantity_used;
        $newBatchId = $newData['raw_material_batch_id'];
        $newQty     = (float) $newData['raw_quantity_used'];

        // Если партия та же и количество не изменилось — ничего не делаем
        if ($oldBatchId == $newBatchId && abs($oldQty - $newQty) < 0.0001) {
            return;
        }

        // Возвращаем старое количество обратно в старую партию
        if ($oldBatchId && $oldBatch = RawMaterialBatch::find($oldBatchId)) {
            $oldBatch->remaining_quantity = (float) $oldBatch->remaining_quantity + $oldQty;
            $oldBatch->save();
        }

        // Проверяем и списываем из новой партии
        $newBatch = RawMaterialBatch::find($newBatchId);
        if (!$newBatch || (float) $newBatch->remaining_quantity < $newQty) {
            throw new \Exception('Недостаточно сырья');
        }

        $newBatch->remaining_quantity = max(0, (float) $newBatch->remaining_quantity - $newQty);
        $newBatch->save();
    }
}
