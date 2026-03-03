<?php

namespace App\Traits;

use App\Models\RawMaterialBatch;
use App\Models\StoneReception;

trait HandlesBatchStock
{
    /**
     * Получает активные партии сырья
     */
    protected function getActiveBatches(StoneReception $reception = null)
    {
        $batches = RawMaterialBatch::with(['product', 'currentWorker'])
            ->where('status', 'active')
            ->where('remaining_quantity', '>', 0)
            ->orderBy('created_at', 'desc')
            ->get();

        // Добавляем текущую партию, если она не в списке активных
        if ($reception && $reception->rawMaterialBatch &&
            !$batches->contains('id', $reception->raw_material_batch_id)) {
            $batches->prepend($reception->rawMaterialBatch);
        }

        return $batches;
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
     * Обрабатывает изменения в партии сырья при обновлении
     */
    protected function handleBatchChanges(StoneReception $reception, array $newData): void
    {
        $oldBatchId = $reception->raw_material_batch_id;
        $oldQuantity = $reception->raw_quantity_used;
        $newBatchId = $newData['raw_material_batch_id'];
        $newQuantity = $newData['raw_quantity_used'];

        // Если ничего не изменилось, выходим
        if ($oldBatchId == $newBatchId && $oldQuantity == $newQuantity) {
            return;
        }

        // Возвращаем сырье в старую партию
        if ($oldBatchId) {
            $oldBatch = RawMaterialBatch::find($oldBatchId);
            if ($oldBatch) {
                $oldBatch->remaining_quantity += $oldQuantity;
                $oldBatch->save();
            }
        }

        // Проверяем и списываем из новой партии
        if (!$this->checkBatchStock($newBatchId, $newQuantity)) {
            throw new \Exception('Недостаточно сырья в новой партии');
        }

        $newBatch = RawMaterialBatch::find($newBatchId);
        $newBatch->remaining_quantity -= $newQuantity;
        $newBatch->save();
    }
}
