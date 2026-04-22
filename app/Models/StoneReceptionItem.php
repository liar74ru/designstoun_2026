<?php

namespace App\Models;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;

class StoneReceptionItem extends Model
{
    protected $table = 'stone_reception_items';

    protected $fillable = [
        'stone_reception_id',
        'product_id',
        'quantity',
        'effective_cost_coeff',
        'is_undercut',
        'is_small_tile',
        'worker_cost_per_m2',
        'master_cost_per_m2',
    ];

    protected $casts = [
        'quantity'             => 'decimal:3',
        'effective_cost_coeff' => 'decimal:4',
        'is_undercut'          => 'boolean',
        'is_small_tile'        => 'boolean',
        'worker_cost_per_m2'   => 'decimal:2',
        'master_cost_per_m2'   => 'decimal:2',
    ];

    // ─── Связи ───────────────────────────────────────────────────────────────

    /**
     * Приёмка
     */
    public function reception()
    {
        return $this->belongsTo(StoneReception::class, 'stone_reception_id');
    }

    /**
     * Продукт
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // ─── Бизнес-логика ───────────────────────────────────────────────────────

    /**
     * Базовый коэффициент приёмки (без поправок).
     * Если is_undercut=true, то base = effective + UNDERCUT_PENALTY.
     */
    public function getBaseCoeffAttribute(): float
    {
        $eff = (float) $this->effective_cost_coeff;
        return $this->is_undercut ? $eff + (float) Setting::get('UNDERCUT_PENALTY', 1.5) : $eff;
    }

    /**
     * Рассчитать итоговый коэффициент из базового и набора флагов-модификаторов.
     * Сейчас есть только один: is_undercut (−UNDERCUT_PENALTY).
     * В будущем сюда добавятся другие условия.
     */
    public static function computeEffectiveCoeff(float $baseCoeff, bool $isUndercut): float
    {
        $coeff = $baseCoeff;
        if ($isUndercut) {
            $coeff -= (float) Setting::get('UNDERCUT_PENALTY', 1.5);
        }
        return $coeff;
    }

    /**
     * Стоимость единицы продукции для этой позиции,
     * рассчитанная по зафиксированному effective_cost_coeff.
     */
    public function effectiveProdCost(): float
    {
        return $this->worker_cost_per_m2 !== null
            ? (float) $this->worker_cost_per_m2
            : $this->product->prodCost((float) $this->effective_cost_coeff);
    }

    /**
     * Зарплата пильщика за данную позицию.
     */
    public function calculateWorkerPay(): float
    {
        return (float) $this->quantity * $this->effectiveProdCost();
    }

    /**
     * Проверяет, является ли SKU плиткой < 50мм (маска 04-хх-30).
     */
    public static function skuIsSmallTile(?string $sku): bool
    {
        if (!$sku) return false;
        $parts = explode('-', $sku);
        return count($parts) >= 3 && $parts[2] === '30';
    }

    /**
     * Ставка мастера за м² по набору флагов.
     */
    public static function computeMasterCost(bool $isUndercut, bool $isSmallTile): float
    {
        return (float) Setting::get('MASTER_BASE_RATE', 100)
            + ($isUndercut  ? (float) Setting::get('MASTER_UNDERCUT_RATE',   50) : 0)
            + ($isSmallTile ? (float) Setting::get('MASTER_SMALL_TILE_RATE', 50) : 0);
    }

    /**
     * Зарплата мастера за данную позицию.
     */
    public function calculateMasterPay(): float
    {
        return (float) $this->quantity * (float) ($this->master_cost_per_m2 ?? 0);
    }
}
