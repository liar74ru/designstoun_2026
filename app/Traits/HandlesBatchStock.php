<?php

namespace App\Traits;

use App\Exceptions\InsufficientRawMaterialException;
use App\Models\RawMaterialBatch;
use App\Models\StoneReception;
use App\Support\BatchStock;

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
            ->whereIn('status', [
                RawMaterialBatch::STATUS_NEW,
                RawMaterialBatch::STATUS_IN_WORK,
                RawMaterialBatch::STATUS_CONFIRMED,
            ]);

        if ($workerId) {
            $query->where('current_worker_id', $workerId);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Обрабатывает изменения в партии сырья при обновлении приёмки.
     * Возвращает старое количество в старую партию, списывает новое из новой.
     * Обе партии — под блокировкой строки, в порядке id (без взаимоблокировок).
     * Статус партии пересчитывается по остатку (BatchStock::syncStatus).
     */
    protected function handleBatchChanges(StoneReception $reception, array $newData): void
    {
        $oldBatchId = $reception->raw_material_batch_id ? (int) $reception->raw_material_batch_id : null;
        $oldQty     = (float) $reception->raw_quantity_used;
        $newBatchId = (int) $newData['raw_material_batch_id'];
        $newQty     = (float) $newData['raw_quantity_used'];

        // Если партия та же и количество не изменилось — ничего не делаем
        if ($oldBatchId === $newBatchId && abs($oldQty - $newQty) < 0.0001) {
            return;
        }

        $batches = RawMaterialBatch::whereIn('id', array_filter([$oldBatchId, $newBatchId]))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        // Возвращаем старое количество обратно в старую партию
        if ($oldBatch = $batches->get($oldBatchId)) {
            $oldBatch->remaining_quantity = (float) $oldBatch->remaining_quantity + $oldQty;
            BatchStock::syncStatus($oldBatch);
            $oldBatch->save();
        }

        // Проверяем и списываем из новой партии (та же модель, если партия не менялась)
        $newBatch = $batches->get($newBatchId);
        if (!$newBatch) {
            throw new InsufficientRawMaterialException();
        }
        BatchStock::ensureAvailable($newBatch, $newQty);

        $newBatch->remaining_quantity = max(0, (float) $newBatch->remaining_quantity - $newQty);
        BatchStock::syncStatus($newBatch);
        $newBatch->save();
    }
}
