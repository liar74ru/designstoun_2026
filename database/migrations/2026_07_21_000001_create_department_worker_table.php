<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Работник может быть задействован в нескольких отделах.
 * `workers.department_id` остаётся основным отделом (дефолты форм, наследование
 * department_id в документах), эта таблица хранит все отделы работника.
 *
 * Инвариант: pivot всегда содержит основной отдел работника.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_worker', function (Blueprint $table) {
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['department_id', 'worker_id']);
        });

        // Backfill: текущий отдел каждого работника становится членством в pivot.
        DB::table('workers')
            ->whereNotNull('department_id')
            ->orderBy('id')
            ->each(function ($worker) {
                DB::table('department_worker')->insert([
                    'department_id' => $worker->department_id,
                    'worker_id'     => $worker->id,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_worker');
    }
};
