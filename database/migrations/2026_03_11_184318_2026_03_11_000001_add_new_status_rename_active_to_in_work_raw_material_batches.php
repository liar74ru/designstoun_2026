<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite не поддерживает ALTER COLUMN — только для PostgreSQL
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // 1. Сначала снимаем старый constraint — иначе UPDATE нарушит его
        DB::statement("ALTER TABLE raw_material_batches DROP CONSTRAINT IF EXISTS raw_material_batches_status_check");
        DB::statement("ALTER TABLE raw_material_batches ALTER COLUMN status TYPE VARCHAR(20)");

        // 2. Переименовываем 'active' → 'in_work'
        DB::statement("UPDATE raw_material_batches SET status = 'in_work' WHERE status = 'active'");

        // 3. Ставим новый default и новый constraint
        DB::statement("ALTER TABLE raw_material_batches ALTER COLUMN status SET DEFAULT 'new'");
        DB::statement("ALTER TABLE raw_material_batches ADD CONSTRAINT raw_material_batches_status_check CHECK (status IN ('new', 'in_work', 'used', 'returned', 'archived'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("UPDATE raw_material_batches SET status = 'active' WHERE status = 'in_work'");
        DB::statement("UPDATE raw_material_batches SET status = 'active' WHERE status = 'new'");
        DB::statement("ALTER TABLE raw_material_batches DROP CONSTRAINT IF EXISTS raw_material_batches_status_check");
        DB::statement("ALTER TABLE raw_material_batches ALTER COLUMN status SET DEFAULT 'active'");
        DB::statement("ALTER TABLE raw_material_batches ADD CONSTRAINT raw_material_batches_status_check CHECK (status IN ('active', 'used', 'returned', 'archived'))");
    }
};
