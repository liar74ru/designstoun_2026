<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packagings', function (Blueprint $table) {
            // Склад продукта (productsStore в МойСклад). store_id остаётся складом
            // сырья/материалов (materialsStore + локальное списание тары).
            $table->uuid('product_store_id')->nullable()->after('store_id');
            $table->foreign('product_store_id')->references('id')->on('stores')->onDelete('restrict');
            $table->index('product_store_id');
        });

        DB::table('packagings')
            ->whereNull('product_store_id')
            ->update(['product_store_id' => DB::raw('store_id')]);
    }

    public function down(): void
    {
        Schema::table('packagings', function (Blueprint $table) {
            $table->dropForeign(['product_store_id']);
            $table->dropIndex(['product_store_id']);
            $table->dropColumn('product_store_id');
        });
    }
};
