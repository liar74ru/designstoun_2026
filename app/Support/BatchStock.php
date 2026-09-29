<?php

namespace App\Support;

use App\Exceptions\InsufficientRawMaterialException;
use App\Models\RawMaterialBatch;

/**
 * Остаток партии сырья при одновременной работе.
 *
 * Любое изменение remaining_quantity — внутри транзакции и после lock(): партия
 * перечитывается под SELECT … FOR UPDATE, и расчёт идёт от свежего остатка, а не
 * от модели, загруженной в начале запроса. Иначе две приёмки на одну партию
 * теряют одно из списаний или обе проходят проверку «хватает сырья».
 */
final class BatchStock
{
    /** Допуск сравнения: остаток хранится с точностью 0.001. */
    private const EPSILON = 0.0005;

    /** Перечитать партию под блокировкой строки. Вне транзакции блокировка снимается сразу. */
    public static function lock(RawMaterialBatch $batch): RawMaterialBatch
    {
        $fresh = RawMaterialBatch::whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
        $batch->setRawAttributes($fresh->getAttributes(), true);

        return $batch;
    }

    public static function lockById(?int $batchId): ?RawMaterialBatch
    {
        return $batchId ? RawMaterialBatch::whereKey($batchId)->lockForUpdate()->first() : null;
    }

    /** @throws InsufficientRawMaterialException */
    public static function ensureAvailable(RawMaterialBatch $batch, float $qty, string $field = 'raw_quantity_used'): void
    {
        if ((float) $batch->remaining_quantity + self::EPSILON < $qty) {
            throw new InsufficientRawMaterialException($field);
        }
    }

    /**
     * Статус рабочей партии по остатку: > 0 → confirmed (уточнена), 0 → in_work
     * (ждёт ручного закрытия). used / returned / archived не трогаем.
     */
    public static function syncStatus(RawMaterialBatch $batch): void
    {
        if (in_array($batch->status, [
            RawMaterialBatch::STATUS_NEW,
            RawMaterialBatch::STATUS_IN_WORK,
            RawMaterialBatch::STATUS_CONFIRMED,
        ], true)) {
            $batch->status = (float) $batch->remaining_quantity > 0
                ? RawMaterialBatch::STATUS_CONFIRMED
                : RawMaterialBatch::STATUS_IN_WORK;
        }
    }
}
