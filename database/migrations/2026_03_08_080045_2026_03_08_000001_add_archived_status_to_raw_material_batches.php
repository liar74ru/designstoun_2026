<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite не поддерживает DROP CONSTRAINT и ALTER COLUMN —
        // эти операции выполняются только для PostgreSQL
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // PostgreSQL: меняем тип через USING и пересоздаём ограничение
        DB::statement("ALTER TABLE raw_material_batches DROP CONSTRAINT IF EXISTS raw_material_batches_status_check");
        DB::statement("ALTER TABLE raw_material_batches ALTER COLUMN status TYPE VARCHAR(20)");
        DB::statement("ALTER TABLE raw_material_batches ADD CONSTRAINT raw_material_batches_status_check CHECK (status IN ('active', 'used', 'returned', 'archived'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("UPDATE raw_material_batches SET status = 'used' WHERE status = 'archived'");
        DB::statement("ALTER TABLE raw_material_batches DROP CONSTRAINT IF EXISTS raw_material_batches_status_check");
        DB::statement("ALTER TABLE raw_material_batches ADD CONSTRAINT raw_material_batches_status_check CHECK (status IN ('active', 'used', 'returned'))");
    }
};
