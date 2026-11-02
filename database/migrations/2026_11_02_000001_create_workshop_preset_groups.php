<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Группы пресетов цеха per-department. Пресет — максимум в одной группе, группа необязательна;
 * удаление группы оставляет пресеты «без группы».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_preset_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('icon', 50)->nullable();
            $table->timestamps();

            $table->unique(['department_id', 'name'], 'workshop_preset_group_dept_name_unique');
        });

        Schema::table('workshop_presets', function (Blueprint $table) {
            $table->foreignId('workshop_preset_group_id')->nullable()->after('department_id')
                ->constrained('workshop_preset_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workshop_presets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workshop_preset_group_id');
        });
        Schema::dropIfExists('workshop_preset_groups');
    }
};
