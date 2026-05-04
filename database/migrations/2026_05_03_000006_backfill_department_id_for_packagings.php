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
                UPDATE packagings p
                SET department_id = w.department_id
                FROM workers w
                WHERE p.packer_id = w.id
                  AND w.department_id IS NOT NULL
                  AND p.department_id IS NULL
            ");

            return;
        }

        // SQLite (тестовая БД) и прочие — построчный backfill
        DB::table('workers')
            ->whereNotNull('department_id')
            ->orderBy('id')
            ->each(function ($worker) {
                DB::table('packagings')
                    ->where('packer_id', $worker->id)
                    ->whereNull('department_id')
                    ->update(['department_id' => $worker->department_id]);
            });
    }

    public function down(): void
    {
        // no-op: данные не возвращаем
    }
};
