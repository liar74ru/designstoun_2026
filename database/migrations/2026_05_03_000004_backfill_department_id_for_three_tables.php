<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("
                UPDATE supplier_orders so
                SET department_id = w.department_id
                FROM workers w
                WHERE so.receiver_id = w.id
                  AND w.department_id IS NOT NULL
                  AND so.department_id IS NULL
            ");

            DB::statement("
                UPDATE stone_receptions sr
                SET department_id = w.department_id
                FROM workers w
                WHERE sr.cutter_id = w.id
                  AND w.department_id IS NOT NULL
                  AND sr.department_id IS NULL
            ");

            DB::statement("
                UPDATE stone_receptions sr
                SET department_id = w.department_id
                FROM workers w
                WHERE sr.receiver_id = w.id
                  AND w.department_id IS NOT NULL
                  AND sr.department_id IS NULL
            ");

            DB::statement("
                UPDATE raw_material_batches rmb
                SET department_id = w.department_id
                FROM workers w
                WHERE rmb.current_worker_id = w.id
                  AND w.department_id IS NOT NULL
                  AND rmb.department_id IS NULL
            ");

            return;
        }

        // SQLite (тестовая БД) и прочие — построчный backfill
        DB::table('workers')
            ->whereNotNull('department_id')
            ->orderBy('id')
            ->each(function ($worker) {
                DB::table('supplier_orders')
                    ->where('receiver_id', $worker->id)
                    ->whereNull('department_id')
                    ->update(['department_id' => $worker->department_id]);

                DB::table('stone_receptions')
                    ->where('cutter_id', $worker->id)
                    ->whereNull('department_id')
                    ->update(['department_id' => $worker->department_id]);

                DB::table('stone_receptions')
                    ->where('receiver_id', $worker->id)
                    ->whereNull('department_id')
                    ->update(['department_id' => $worker->department_id]);

                DB::table('raw_material_batches')
                    ->where('current_worker_id', $worker->id)
                    ->whereNull('department_id')
                    ->update(['department_id' => $worker->department_id]);
            });
    }

    public function down(): void
    {
        // no-op: данные не возвращаем
    }
};
