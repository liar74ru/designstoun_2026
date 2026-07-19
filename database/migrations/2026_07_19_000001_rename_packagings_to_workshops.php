<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Переименование модуля «Упаковка» → «Цех»: таблицы, FK-колонки и ключ операции.
 * Имена индексов/констрейнтов PostgreSQL остаются старыми (packagings_pkey и т.п.) —
 * код на них не ссылается, переименование чисто косметическое и не выполняется.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('packagings', 'workshops');
        Schema::rename('packaging_items', 'workshop_items');
        Schema::rename('packaging_logs', 'workshop_logs');
        Schema::rename('packaging_log_items', 'workshop_log_items');

        Schema::table('workshop_items', fn (Blueprint $table) => $table->renameColumn('packaging_id', 'workshop_id'));
        Schema::table('workshop_logs', fn (Blueprint $table) => $table->renameColumn('packaging_id', 'workshop_id'));
        Schema::table('workshop_log_items', fn (Blueprint $table) => $table->renameColumn('packaging_log_id', 'workshop_log_id'));

        DB::table('department_operation_settings')
            ->where('operation_key', 'packagings')
            ->update(['operation_key' => 'workshops']);
    }

    public function down(): void
    {
        DB::table('department_operation_settings')
            ->where('operation_key', 'workshops')
            ->update(['operation_key' => 'packagings']);

        Schema::table('workshop_items', fn (Blueprint $table) => $table->renameColumn('workshop_id', 'packaging_id'));
        Schema::table('workshop_logs', fn (Blueprint $table) => $table->renameColumn('workshop_id', 'packaging_id'));
        Schema::table('workshop_log_items', fn (Blueprint $table) => $table->renameColumn('workshop_log_id', 'packaging_log_id'));

        Schema::rename('workshops', 'packagings');
        Schema::rename('workshop_items', 'packaging_items');
        Schema::rename('workshop_logs', 'packaging_logs');
        Schema::rename('workshop_log_items', 'packaging_log_items');
    }
};
