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

    /**
     * Коэффициент для показа: до 4 знаков, хвостовые нули отброшены.
     *
     * Значение правила задаёт админ, колонка — decimal(8,4). Округление до
     * одного знака показывало 1.75 как «1,8», а четыре знака давали
     * нечитаемое «2,5000». Зеркало formatCoeff() из resources/js/rate-formula.js;
     * разделитель — запятая, значения печатаются в русских таблицах.
     */
    public static function formatCoeff(float|int|string|null $value): string
    {
        $formatted = number_format((float) $value, 4, ',', ' ');

        return str_contains($formatted, ',')
            ? rtrim(rtrim($formatted, '0'), ',')
            : $formatted;
    }
}
