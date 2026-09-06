<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Явное хранение базового коэффициента позиции.
 *
 * До этой миграции база не хранилась, а восстанавливалась обратным счётом в
 * StoneReceptionItem::getBaseCoeffAttribute(). Обратный счёт снимал подкол, но
 * НЕ снимал бонус плитки-маски — поэтому форма правки коэффициента на странице
 * приёмки отдавала обратно значение с уже учтённым бонусом, а сервер применял
 * его повторно: у SKU 04-07-* коэффициент рос на 2 при каждом сохранении.
 *
 * Бэкфил обязан воспроизводить текущий effective_cost_coeff, иначе правка
 * старой позиции сдвинет её коэффициент. Поэтому здесь обратный счёт полный,
 * с вычитанием бонуса маски.
 *
 * Формула заинлайнена намеренно (правило CLAUDE.md): миграция фиксирует расчёт,
 * действовавший на момент написания, и не должна ломаться при эволюции моделей.
 * Значения коэффициентов берутся из настроек с фолбэком на дефолты — те же, что
 * в config/production_rates.php на момент миграции.
 */
return new class extends Migration
{
    private const DEFAULT_UNDERCUT_PENALTY = 1.5;
    private const DEFAULT_MASK_TILE_BONUS  = 2.0;

    public function up(): void
    {
        Schema::table('stone_reception_items', function (Blueprint $table) {
            $table->decimal('base_cost_coeff', 8, 4)->nullable()->after('product_id');
        });

        Schema::table('workshop_items', function (Blueprint $table) {
            $table->decimal('base_cost_coeff', 8, 4)->nullable()->after('product_id');
        });

        $undercut = $this->setting('UNDERCUT_PENALTY', self::DEFAULT_UNDERCUT_PENALTY);
        $mask     = $this->setting('MASK_TILE_COEFF_BONUS', self::DEFAULT_MASK_TILE_BONUS);

        foreach (['stone_reception_items', 'workshop_items'] as $table) {
            $this->backfill($table, $undercut, $mask);
        }
    }

    public function down(): void
    {
        Schema::table('stone_reception_items', function (Blueprint $table) {
            $table->dropColumn('base_cost_coeff');
        });

        Schema::table('workshop_items', function (Blueprint $table) {
            $table->dropColumn('base_cost_coeff');
        });
    }

    private function setting(string $key, float $default): float
    {
        $value = DB::table('settings')->where('key', $key)->value('value');

        return ($value === null || $value === '') ? $default : (float) $value;
    }

    /**
     * base = effective + подкол − бонус маски.
     * У позиций с торцовкой обратить замену нельзя, поэтому берём коэффициент
     * продукта из справочника: на результат он всё равно не влияет — правило
     * торцовки подменяет базу целиком.
     */
    private function backfill(string $table, float $undercut, float $mask): void
    {
        DB::table($table)
            ->orderBy('id')
            ->chunkById(200, function ($items) use ($table, $undercut, $mask) {
                $skus = DB::table('products')
                    ->whereIn('id', $items->pluck('product_id')->filter()->unique())
                    ->pluck('sku', 'id');

                foreach ($items as $item) {
                    $sku = $skus[$item->product_id] ?? null;

                    if ($item->is_edging) {
                        $base = (float) (DB::table('products')
                            ->where('id', $item->product_id)
                            ->value('prod_cost_coeff') ?? 0);
                    } else {
                        $base = (float) $item->effective_cost_coeff
                            + ($item->is_undercut ? $undercut : 0.0)
                            - ($this->isMaskTile($sku) ? $mask : 0.0);
                    }

                    DB::table($table)->where('id', $item->id)->update(['base_cost_coeff' => $base]);
                }
            });
    }

    /** Плитка-маска: SKU вида 04-07-XX. */
    private function isMaskTile(?string $sku): bool
    {
        if (!$sku) {
            return false;
        }
        $parts = explode('-', $sku);

        return ($parts[0] ?? '') === '04' && ($parts[1] ?? '') === '07';
    }
};
