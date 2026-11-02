<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Пресет цеха — в нескольких группах сразу: workshop_presets.workshop_preset_group_id
 * переезжает в pivot workshop_preset_group_preset. Удаление группы или пресета чистит связи,
 * сам пресет при удалении группы остаётся.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_preset_group_preset', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workshop_preset_group_id')->constrained('workshop_preset_groups')->cascadeOnDelete();
            $table->foreignId('workshop_preset_id')->constrained('workshop_presets')->cascadeOnDelete();

            $table->unique(['workshop_preset_group_id', 'workshop_preset_id'], 'workshop_preset_group_preset_unique');
        });

        DB::table('workshop_presets')
            ->whereNotNull('workshop_preset_group_id')
            ->orderBy('id')
            ->each(function ($preset) {
                DB::table('workshop_preset_group_preset')->insert([
                    'workshop_preset_group_id' => $preset->workshop_preset_group_id,
                    'workshop_preset_id'       => $preset->id,
                ]);
            });

        Schema::table('workshop_presets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workshop_preset_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('workshop_presets', function (Blueprint $table) {
            $table->foreignId('workshop_preset_group_id')->nullable()->after('department_id')
                ->constrained('workshop_preset_groups')->nullOnDelete();
        });

        // Обратно влезает одна группа на пресет — берём первую.
        DB::table('workshop_preset_group_preset')
            ->orderBy('id')
            ->each(function ($link) {
                DB::table('workshop_presets')
                    ->where('id', $link->workshop_preset_id)
                    ->whereNull('workshop_preset_group_id')
                    ->update(['workshop_preset_group_id' => $link->workshop_preset_group_id]);
            });

        Schema::dropIfExists('workshop_preset_group_preset');
    }
};
