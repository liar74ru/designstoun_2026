<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Документирует добавление статуса 'completed' (Завершена) для приёмок.
 * Поле status в stone_receptions — VARCHAR, поэтому изменений схемы не требует.
 * Миграция фиксирует намеренное расширение бизнес-логики статусов.
 *
 * Жизненный цикл статуса приёмки после изменения:
 *   active    — ведётся, партия в работе
 *   completed — партия вручную переведена в «Израсходована», приёмка завершена
 *   processed — финальный, после синхронизации с МойСклад
 */
return new class extends Migration
{
    public function up(): void
    {
        // Статус 'completed' добавляется как строковое значение в VARCHAR-поле.
        // DB-схема не изменяется.
    }

    public function down(): void
    {
        // При откате: убедиться что нет строк со status='completed', затем удалить их.
        \DB::table('stone_receptions')->where('status', 'completed')->update(['status' => 'active']);
    }
};
