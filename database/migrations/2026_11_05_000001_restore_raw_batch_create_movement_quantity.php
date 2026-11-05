<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * С 4.0.2 до 4.0.28 корректировка партии переписывала движение create итоговым количеством
 * партии, и строка «Создание партии» показывала сумму всех добавок. Возвращаем количество
 * при создании:
 *
 *   количество партии + передано другим пильщикам − корректировки «+» + корректировки «−»
 *
 * Передача уменьшает количество партии, но строку создания не трогает, поэтому прибавляется.
 * Только партии с корректировками: у остальных строка создания не переписывалась.
 * Формула инлайнится, модели не используются (см. CLAUDE.md про миграции).
 */
return new class extends Migration
{
    public function up(): void
    {
        $batchIds = DB::table('raw_material_movements')
            ->whereIn('movement_type', ['adjust_increase', 'adjust_decrease'])
            ->distinct()
            ->pluck('batch_id');

        foreach ($batchIds as $batchId) {
            $initial = DB::table('raw_material_batches')->where('id', $batchId)->value('initial_quantity');
            if ($initial === null) {
                continue;
            }

            $create = DB::table('raw_material_movements')
                ->where('batch_id', $batchId)
                ->where('movement_type', 'create')
                ->orderBy('created_at')
                ->orderBy('id')
                ->first(['id', 'quantity']);
            if (!$create) {
                continue;
            }

            $sums = DB::table('raw_material_movements')
                ->where('batch_id', $batchId)
                ->selectRaw("
                    COALESCE(SUM(CASE WHEN movement_type = 'transfer_to_worker' THEN quantity END), 0) AS transferred,
                    COALESCE(SUM(CASE WHEN movement_type = 'adjust_increase' THEN quantity END), 0) AS increased,
                    COALESCE(SUM(CASE WHEN movement_type = 'adjust_decrease' THEN quantity END), 0) AS decreased
                ")
                ->first();

            $original = round(
                (float) $initial + (float) $sums->transferred - (float) $sums->increased + (float) $sums->decreased,
                3
            );

            if ($original < 0 || abs($original - (float) $create->quantity) < 0.0005) {
                continue;
            }

            DB::table('raw_material_movements')->where('id', $create->id)->update(['quantity' => $original]);
        }
    }

    public function down(): void
    {
        // Перезаписанные значения не восстанавливаются: это была ошибка
    }
};
