<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Добавляем поле prod_cost_coeff в таблицу products.
     *
     * Это коэффициент для расчёта зарплаты пильщика:
     * зарплата = prod_cost_coeff * ставка (390 руб) * количество
     *
     * nullable() — потому что у старых товаров его нет,
     * default(1.0) — нейтральный коэффициент по умолчанию
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('prod_cost_coeff', 8, 4)
                ->nullable()
                ->default(1.0)
                ->after('old_price')
                ->comment('Коэффициент стоимости производства (из МойСклад)');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('prod_cost_coeff');
        });
    }
};
