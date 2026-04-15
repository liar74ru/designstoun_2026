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

        // Добавляем 'confirmed' в CHECK-constraint статуса
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE raw_material_batches DROP CONSTRAINT IF EXISTS raw_material_batches_status_check");
            DB::statement("ALTER TABLE raw_material_batches ADD CONSTRAINT raw_material_batches_status_check CHECK (status IN ('new', 'in_work', 'confirmed', 'used', 'returned', 'archived'))");
        } elseif (DB::getDriverName() === 'sqlite') {
            // SQLite не поддерживает ALTER CONSTRAINT — пересоздаём таблицу
            DB::statement('PRAGMA foreign_keys = OFF');
            DB::statement("
                CREATE TABLE raw_material_batches_confirmed (
                    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
                    product_id               INTEGER NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
                    initial_quantity         NUMERIC(10,3) NOT NULL,
                    remaining_quantity       NUMERIC(10,3) NOT NULL,
                    status                   VARCHAR(20) NOT NULL DEFAULT 'new'
                                             CHECK(status IN ('new','in_work','confirmed','used','returned','archived')),
                    current_store_id         VARCHAR(36) NOT NULL REFERENCES stores(id) ON DELETE RESTRICT,
                    current_worker_id        INTEGER REFERENCES workers(id) ON DELETE SET NULL,
                    batch_number             VARCHAR(255),
                    moysklad_processing_id   VARCHAR(255),
                    moysklad_processing_name VARCHAR(255),
                    moysklad_sync_error      TEXT,
                    created_at               DATETIME,
                    updated_at               DATETIME
                )
            ");
            DB::statement("
                INSERT INTO raw_material_batches_confirmed
                SELECT id, product_id, initial_quantity, remaining_quantity, status,
                       current_store_id, current_worker_id, batch_number,
                       moysklad_processing_id, moysklad_processing_name, moysklad_sync_error,
                       created_at, updated_at
                FROM raw_material_batches
            ");
            DB::statement('DROP TABLE raw_material_batches');
            DB::statement('ALTER TABLE raw_material_batches_confirmed RENAME TO raw_material_batches');
            DB::statement('CREATE INDEX raw_material_batches_status_index ON raw_material_batches (status)');
            DB::statement('CREATE INDEX raw_material_batches_current_worker_id_status_index ON raw_material_batches (current_worker_id, status)');
            DB::statement('PRAGMA foreign_keys = ON');
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
