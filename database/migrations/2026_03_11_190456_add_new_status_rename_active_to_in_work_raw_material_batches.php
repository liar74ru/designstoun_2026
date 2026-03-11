<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->upSqlite();
            return;
        }

        // PostgreSQL:
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
            $this->downSqlite();
            return;
        }

        DB::statement("UPDATE raw_material_batches SET status = 'active' WHERE status = 'in_work'");
        DB::statement("UPDATE raw_material_batches SET status = 'active' WHERE status = 'new'");
        DB::statement("ALTER TABLE raw_material_batches DROP CONSTRAINT IF EXISTS raw_material_batches_status_check");
        DB::statement("ALTER TABLE raw_material_batches ALTER COLUMN status SET DEFAULT 'active'");
        DB::statement("ALTER TABLE raw_material_batches ADD CONSTRAINT raw_material_batches_status_check CHECK (status IN ('active', 'used', 'returned', 'archived'))");
    }

    /**
     * SQLite не поддерживает ALTER COLUMN и DROP CONSTRAINT.
     * Единственный способ изменить CHECK constraint — пересоздать таблицу:
     * 1. Создаём новую таблицу с правильным CHECK
     * 2. Копируем данные (попутно переименовывая active → in_work)
     * 3. Удаляем старую, переименовываем новую
     */
    private function upSqlite(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');

        DB::statement("
            CREATE TABLE raw_material_batches_new (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                product_id          INTEGER NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
                initial_quantity    NUMERIC(10,3) NOT NULL,
                remaining_quantity  NUMERIC(10,3) NOT NULL,
                status              VARCHAR(20) NOT NULL DEFAULT 'new'
                                    CHECK(status IN ('new','in_work','used','returned','archived')),
                current_store_id    VARCHAR(36) NOT NULL REFERENCES stores(id) ON DELETE RESTRICT,
                current_worker_id   INTEGER REFERENCES workers(id) ON DELETE SET NULL,
                batch_number        VARCHAR(255),
                created_at          DATETIME,
                updated_at          DATETIME
            )
        ");

        DB::statement("
            INSERT INTO raw_material_batches_new
            SELECT
                id,
                product_id,
                initial_quantity,
                remaining_quantity,
                CASE WHEN status = 'active' THEN 'in_work' ELSE status END,
                current_store_id,
                current_worker_id,
                batch_number,
                created_at,
                updated_at
            FROM raw_material_batches
        ");

        DB::statement('DROP TABLE raw_material_batches');
        DB::statement('ALTER TABLE raw_material_batches_new RENAME TO raw_material_batches');

        DB::statement('CREATE INDEX raw_material_batches_status_index ON raw_material_batches (status)');
        DB::statement('CREATE INDEX raw_material_batches_current_worker_id_status_index ON raw_material_batches (current_worker_id, status)');

        DB::statement('PRAGMA foreign_keys = ON');
    }

    private function downSqlite(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');

        DB::statement("
            CREATE TABLE raw_material_batches_old (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                product_id          INTEGER NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
                initial_quantity    NUMERIC(10,3) NOT NULL,
                remaining_quantity  NUMERIC(10,3) NOT NULL,
                status              VARCHAR(20) NOT NULL DEFAULT 'active'
                                    CHECK(status IN ('active','used','returned','archived')),
                current_store_id    VARCHAR(36) NOT NULL REFERENCES stores(id) ON DELETE RESTRICT,
                current_worker_id   INTEGER REFERENCES workers(id) ON DELETE SET NULL,
                batch_number        VARCHAR(255),
                created_at          DATETIME,
                updated_at          DATETIME
            )
        ");

        DB::statement("
            INSERT INTO raw_material_batches_old
            SELECT
                id,
                product_id,
                initial_quantity,
                remaining_quantity,
                CASE WHEN status IN ('in_work','new') THEN 'active' ELSE status END,
                current_store_id,
                current_worker_id,
                batch_number,
                created_at,
                updated_at
            FROM raw_material_batches
        ");

        DB::statement('DROP TABLE raw_material_batches');
        DB::statement('ALTER TABLE raw_material_batches_old RENAME TO raw_material_batches');

        DB::statement('CREATE INDEX raw_material_batches_status_index ON raw_material_batches (status)');
        DB::statement('CREATE INDEX raw_material_batches_current_worker_id_status_index ON raw_material_batches (current_worker_id, status)');

        DB::statement('PRAGMA foreign_keys = ON');
    }
};
