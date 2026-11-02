<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Пресет цеха — в нескольких отделах сразу: pivot department_workshop_preset хранит все отделы,
 * workshop_presets.department_id остаётся основным (в нём пресет создан и удаляется целиком).
 * Удаление основного отдела больше не уносит пресет из остальных: FK — nullOnDelete,
 * основной переназначает Department::booted().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_workshop_preset', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('workshop_preset_id')->constrained('workshop_presets')->cascadeOnDelete();

            $table->unique(['department_id', 'workshop_preset_id'], 'department_workshop_preset_unique');
        });

        DB::table('workshop_presets')->whereNotNull('department_id')->orderBy('id')->each(function ($preset) {
            DB::table('department_workshop_preset')->insert([
                'department_id'      => $preset->department_id,
                'workshop_preset_id' => $preset->id,
            ]);
        });

        Schema::table('workshop_presets', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
        });
        Schema::table('workshop_presets', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->change();
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Без основного отдела пресет вернуть некуда.
        DB::table('workshop_presets')->whereNull('department_id')->delete();

        Schema::table('workshop_presets', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
        });
        Schema::table('workshop_presets', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable(false)->change();
            $table->foreign('department_id')->references('id')->on('departments')->cascadeOnDelete();
        });

        Schema::dropIfExists('department_workshop_preset');
    }
};
