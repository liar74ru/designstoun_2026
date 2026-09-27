<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Отпечаток данных заявки, записанных последней синхронизацией: совпал — заявку
     * не переписываем (позиции не пересоздаются, отделы не пересинхронизируются).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->string('sync_hash', 32)->nullable()->after('attributes')
                ->comment('md5 данных последней синхронизации из МойСклад');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn('sync_hash');
        });
    }
};
