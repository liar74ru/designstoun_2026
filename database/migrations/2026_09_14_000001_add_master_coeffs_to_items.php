<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Явное хранение коэффициента мастера в позиции — так же, как у пильщика:
 * база (без правил) и итог (база + сумма master_coeff_delta сработавших правил).
 *
 * До этой миграции у позиции хранилась только ставка master_cost_per_m2,
 * а коэффициент, по которому она посчитана, нигде не фиксировался.
 *
 * Бэкфил: база — текущий products.master_cost_coeff, итог — база плюс дельты
 * мастера из снапшота правил позиции. master_cost_per_m2 не переписывается —
 * выплаченные зарплаты не трогаем.
 *
 * Формула заинлайнена намеренно (правило CLAUDE.md): миграция не должна
 * ломаться при эволюции моделей.
 */
return new class extends Migration
{
    private const TABLES = [
        'stone_reception_items' => 'stone_reception_item_id',
        'workshop_items'        => 'workshop_item_id',
    ];

    public function up(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->decimal('master_base_cost_coeff', 8, 4)->nullable()->after('effective_cost_coeff');
                $t->decimal('master_effective_cost_coeff', 8, 4)->nullable()->after('master_base_cost_coeff');
            });
        }

        foreach (self::TABLES as $table => $foreignKey) {
            $this->backfill($table, $foreignKey);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['master_base_cost_coeff', 'master_effective_cost_coeff']);
            });
        }
    }

    private function backfill(string $table, string $foreignKey): void
    {
        DB::table($table)
            ->orderBy('id')
            ->chunkById(200, function ($items) use ($table, $foreignKey) {
                $coeffs = DB::table('products')
                    ->whereIn('id', $items->pluck('product_id')->filter()->unique())
                    ->pluck('master_cost_coeff', 'id');

                $deltas = DB::table('production_item_modifiers')
                    ->whereIn($foreignKey, $items->pluck('id'))
                    ->groupBy($foreignKey)
                    ->selectRaw("{$foreignKey} as item_id, SUM(COALESCE(master_coeff_delta, 0)) as delta")
                    ->pluck('delta', 'item_id');

                foreach ($items as $item) {
                    $base = (float) ($coeffs[$item->product_id] ?? 0);

                    DB::table($table)->where('id', $item->id)->update([
                        'master_base_cost_coeff'      => $base,
                        'master_effective_cost_coeff' => $base + (float) ($deltas[$item->id] ?? 0),
                    ]);
                }
            });
    }
};
