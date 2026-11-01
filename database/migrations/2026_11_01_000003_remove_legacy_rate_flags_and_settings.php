<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Надбавки задаются правилами отдела, что применилось к позиции — в снапшоте
 * production_item_modifiers. Старые флаги приложение не читает с 4.0.4, а глобальные
 * коэффициенты не участвуют в расчёте с 3.48.0. На проде перед удалением проверено:
 * у всех позиций с флагами снапшот есть. down() вернёт колонки, но не значения.
 */
return new class extends Migration
{
    private const KEYS = ['UNDERCUT_PENALTY', 'EDGING_COEFF', 'MASK_TILE_COEFF_BONUS', 'MASTER_UNDERCUT_RATE'];

    private const TABLES = ['stone_reception_items', 'workshop_items'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['is_undercut', 'is_edging']);
            });
        }

        DB::table('settings')->whereIn('key', self::KEYS)->delete();
        DB::table('department_settings')->whereIn('key', self::KEYS)->delete();
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->boolean('is_undercut')->default(false);
                $table->boolean('is_edging')->default(false);
            });
        }
    }
};
