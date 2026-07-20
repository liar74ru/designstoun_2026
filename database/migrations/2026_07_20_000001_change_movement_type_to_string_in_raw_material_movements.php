<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE raw_material_movements DROP CONSTRAINT IF EXISTS raw_material_movements_movement_type_check');
        }

        Schema::table('raw_material_movements', function (Blueprint $table) {
            $table->string('movement_type', 30)->change();
        });
    }

    public function down(): void
    {
        // Обратная конвертация в enum не выполняется: в таблице могут быть
        // значения adjust_increase/adjust_decrease, не входящие в старый enum.
    }
};
