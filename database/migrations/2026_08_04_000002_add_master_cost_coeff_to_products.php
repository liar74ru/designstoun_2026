<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Коэффициент ставки мастера — атрибут masterCostCoeff из МойСклад.
 * По умолчанию 0: ставка мастера равна базовой (MASTER_BASE_RATE),
 * то есть поведение до перехода на коэффициент сохраняется.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('master_cost_coeff', 8, 4)
                ->nullable()
                ->default(0)
                ->after('prod_cost_coeff');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('master_cost_coeff');
        });
    }
};
