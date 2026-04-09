<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_material_batches', function (Blueprint $table) {
            $table->string('moysklad_processing_id')->nullable()->after('batch_number');
            $table->string('moysklad_processing_name')->nullable()->after('moysklad_processing_id');
            $table->text('moysklad_sync_error')->nullable()->after('moysklad_processing_name');
        });

        // PostgreSQL: добавляем 'confirmed' в CHECK-constraint статуса
        // SQLite не поддерживает ALTER TABLE ... CONSTRAINT
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE raw_material_batches DROP CONSTRAINT IF EXISTS raw_material_batches_status_check");
            DB::statement("ALTER TABLE raw_material_batches ADD CONSTRAINT raw_material_batches_status_check CHECK (status IN ('new', 'in_work', 'confirmed', 'used', 'returned', 'archived'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Убираем 'confirmed': сначала сбросить строки с этим статусом
            DB::statement("UPDATE raw_material_batches SET status = 'in_work' WHERE status = 'confirmed'");

            DB::statement("ALTER TABLE raw_material_batches DROP CONSTRAINT IF EXISTS raw_material_batches_status_check");
            DB::statement("ALTER TABLE raw_material_batches ADD CONSTRAINT raw_material_batches_status_check CHECK (status IN ('new', 'in_work', 'used', 'returned', 'archived'))");
        }

        Schema::table('raw_material_batches', function (Blueprint $table) {
            $table->dropColumn(['moysklad_processing_id', 'moysklad_processing_name', 'moysklad_sync_error']);
        });
    }
};
