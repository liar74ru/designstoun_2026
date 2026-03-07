<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Удаляем устаревшее поле products.quantity.
     *
     * Суммарный остаток теперь вычисляется как SUM(product_stocks.quantity)
     * через аксессор $product->total_quantity или ->withSum('stocks','quantity').
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('quantity', 10, 3)->default(0)->after('old_price');
        });
    }
};
