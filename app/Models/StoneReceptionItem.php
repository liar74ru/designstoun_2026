<?php

namespace App\Models;

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
    ];

    protected $casts = [
        'quantity'             => 'decimal:3',
        'effective_cost_coeff' => 'decimal:4',
        'is_undercut'          => 'boolean',
    ];

    /**
     * Скидка коэффициента при «80% подкол».
     */
    const UNDERCUT_PENALTY = 1.5;

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
        return $this->is_undercut ? $eff + self::UNDERCUT_PENALTY : $eff;
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
            $coeff -= self::UNDERCUT_PENALTY;
        }
        return $coeff;
    }

    /**
     * Стоимость единицы продукции для этой позиции,
     * рассчитанная по зафиксированному effective_cost_coeff.
     */
    public function effectiveProdCost(): float
    {
        $coeff   = (float) $this->effective_cost_coeff;
        $perUnit = Product::PIECE_RATE + (Product::PIECE_RATE * 0.17) * $coeff;
        return floor($perUnit / 10) * 10;
    }

    /**
     * Зарплата пильщика за данную позицию.
     */
    public function calculateWorkerPay(): float
    {
        return (float) $this->quantity * $this->effectiveProdCost();
    }
}
