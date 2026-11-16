<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Расходы на единицу продукта в шаблоне цеха. Пусто — накладные из карточки отдела.
 * cost_replaces_total: false — число заменяет только накладные отдела (зарплаты считаются),
 * true — число и есть вся сумма затрат.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshop_presets', function (Blueprint $table) {
            $table->decimal('cost_per_unit', 12, 2)->nullable()->after('name');
            $table->boolean('cost_replaces_total')->default(false)->after('cost_per_unit');
        });
    }

    public function down(): void
    {
        Schema::table('workshop_presets', function (Blueprint $table) {
            $table->dropColumn(['cost_per_unit', 'cost_replaces_total']);
        });
    }
};
