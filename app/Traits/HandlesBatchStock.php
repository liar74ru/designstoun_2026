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

        $batches = $query->orderBy('created_at', 'desc')->get();

        // Временное логирование
        \Log::info('getActiveBatches возвращает:', [
            'worker_id' => $workerId,
            'count' => $batches->count(),
            'batches' => $batches->map(fn($b) => [
                'id' => $b->id,
                'product' => $b->product->name,
                'remaining' => $b->remaining_quantity
            ])->toArray()
        ]);

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
