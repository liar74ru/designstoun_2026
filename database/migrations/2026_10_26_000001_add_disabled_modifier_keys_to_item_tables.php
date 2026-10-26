<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Позиции, у которых sku-правила можно снять в правке коэффициентов. */
    private const TABLES = ['stone_reception_items', 'workshop_items'];

    public function up(): void
    {
        // Ключи sku-правил, снятых пользователем у позиции. Хранятся отдельно от снапшота:
        // при обновлении из справочника движок иначе вернул бы правило по SKU.
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->json('disabled_modifier_keys')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('disabled_modifier_keys');
            });
        }
    }
};
