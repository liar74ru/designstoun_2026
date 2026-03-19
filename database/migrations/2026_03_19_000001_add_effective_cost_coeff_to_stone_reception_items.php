<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\StoneReceptionItem;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stone_reception_items', function (Blueprint $table) {
            // Зафиксированный на момент приёмки коэффициент продукта.
            // Берётся из product->prod_cost_coeff в момент создания позиции,
            // чтобы изменения в справочнике продуктов не пересчитывали старые зарплаты.
            $table->decimal('effective_cost_coeff', 8, 4)->nullable()->after('quantity');

            // Признак «80% подкол»: если true, к базовому коэффициенту применяется
            // скидка −1.5 (т.е. effective_cost_coeff = base_coeff − 1.5).
            $table->boolean('is_undercut')->default(false)->after('effective_cost_coeff');
        });

        // Заполняем effective_cost_coeff для существующих записей на основе
        // текущего prod_cost_coeff продукта (ретроспективная инициализация).
        StoneReceptionItem::with('product')->chunkById(200, function ($items) {
            foreach ($items as $item) {
                if ($item->product && $item->effective_cost_coeff === null) {
                    $item->effective_cost_coeff = $item->product->prod_cost_coeff ?? 0;
                    $item->save();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('stone_reception_items', function (Blueprint $table) {
            $table->dropColumn(['effective_cost_coeff', 'is_undercut']);
        });
    }
};
