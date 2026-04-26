<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->uuid('default_raw_store_id')->nullable()->after('is_active');
            $table->uuid('default_product_store_id')->nullable()->after('default_raw_store_id');
            $table->uuid('default_production_store_id')->nullable()->after('default_product_store_id');

            $table->foreign('default_raw_store_id')->references('id')->on('stores')->nullOnDelete();
            $table->foreign('default_product_store_id')->references('id')->on('stores')->nullOnDelete();
            $table->foreign('default_production_store_id')->references('id')->on('stores')->nullOnDelete();
        });

        $rawStore        = DB::table('stores')->where('name', '1. Склад Уралия Сырье')->value('id');
        $productStore    = DB::table('stores')->where('name', '2. Склад Уралия Продукт')->value('id');
        $productionStore = DB::table('stores')->where('name', '6. Склад Уралия Цех')->value('id');

        DB::table('departments')->update([
            'default_raw_store_id'        => $rawStore,
            'default_product_store_id'    => $productStore,
            'default_production_store_id' => $productionStore,
        ]);
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['default_raw_store_id']);
            $table->dropForeign(['default_product_store_id']);
            $table->dropForeign(['default_production_store_id']);
            $table->dropColumn(['default_raw_store_id', 'default_product_store_id', 'default_production_store_id']);
        });
    }
};
