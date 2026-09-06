<?php

namespace App\Support;

class RateFormula
{
    /**
     * Доля базовой ставки, на которую действует коэффициент продукта.
     * Публичная: то же значение передаётся в JS (partials/production-rates-js.blade.php),
     * чтобы превью в формах не хранило свою копию.
     */
    public const COEFF_SHARE = 0.17;

    /**
     * Ступенчатая ставка за единицу продукции:
     * ОКРУГЛВНИЗ((ставка + ставка×17%×коэф) / 10) × 10
     *
     * При коэффициенте 0 возвращает базовую ставку без надбавки.
     * Используется для зарплаты пильщика (PIECE_RATE + prod_cost_coeff)
     * и для ставки мастера (MASTER_BASE_RATE + master_cost_coeff).
     */
    public static function stepped(float $rate, float $coeff): float
    {
        return floor(($rate + ($rate * self::COEFF_SHARE) * $coeff) / 10) * 10;
    }
}
