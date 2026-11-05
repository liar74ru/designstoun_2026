<?php

namespace App\Support;

use App\Models\Product;

/**
 * Единицы измерения товара для показа.
 *
 * Единица берётся только из карточки товара (products.uom, приходит из МойСклад) —
 * вписывать «м²/м³» в шаблоны нельзя: сырьё бывает в тоннах, продукция — в штуках.
 * Зеркало в JS — resources/js/uom.js (window.Uom).
 */
class Uom
{
    /** Как МойСклад пишет единицу → как её показываем. */
    private const NORMALIZE = ['м2' => 'м²', 'м3' => 'м³'];

    /** Штучные единицы: нули после запятой не показываем, дробь — если есть. */
    private const INTEGER_UNITS = ['шт'];

    /** Единица для показа; нет единицы → пустая строка (без подстановки по умолчанию). */
    public static function label(?string $uom): string
    {
        return strtr(trim((string) $uom), self::NORMALIZE);
    }

    /** Единица товара для показа. */
    public static function of(?Product $product): string
    {
        return self::label($product?->uom);
    }

    /**
     * Суммы количества по единицам в порядке появления.
     *
     * @param  callable(mixed): (float|int|string|null)  $qty
     * @param  callable(mixed): (?string)  $uom  единица строки (сырая или нормализованная)
     * @return array<string, float>  единица → сумма; без единицы — ключ ''
     */
    public static function totals(iterable $rows, callable $qty, callable $uom): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $unit = self::label($uom($row));
            $totals[$unit] = ($totals[$unit] ?? 0.0) + (float) $qty($row);
        }

        return $totals;
    }

    /** Суммы по единицам для позиций с товаром: поле количества — quantity. */
    public static function itemTotals(iterable $items, string $field = 'quantity'): array
    {
        return self::totals($items, fn ($i) => data_get($i, $field), fn ($i) => data_get($i, 'product.uom'));
    }

    /**
     * Подпись ставки: «₽/м²» по единице товара; единицы нет (или ставка общая
     * для отдела, без товара) → «₽/ед.».
     */
    public static function rate(?string $unit = null): string
    {
        $unit = self::label($unit);

        return '₽/' . ($unit === '' ? 'ед.' : $unit);
    }

    /**
     * Число без единицы: «12,500» для м²; для штук — «3», а дробь — только
     * если она есть («2,5»).
     */
    public static function number(float|int|string|null $qty, ?string $unit, int $decimals = 3): string
    {
        $value = round((float) $qty, $decimals);
        $text  = number_format($value, $decimals, ',', ' ');

        if ($decimals > 0 && in_array(self::label($unit), self::INTEGER_UNITS, true)) {
            $text = rtrim(rtrim($text, '0'), ',');
        }

        return $text;
    }

    /** «12,500 м²», «3 шт» — число с единицей; без единицы — только число. */
    public static function format(float|int|string|null $qty, ?string $unit, int $decimals = 3): string
    {
        $number = self::number($qty, $unit, $decimals);
        $unit   = self::label($unit);

        return $unit === '' ? $number : $number . ' ' . $unit;
    }

    /** «12,500 м² · 3 шт»; пустой список → «0». */
    public static function formatTotals(array $totals, int $decimals = 3): string
    {
        if ($totals === []) {
            return number_format(0, $decimals, ',', ' ');
        }

        $parts = [];
        foreach ($totals as $unit => $sum) {
            $parts[] = self::format($sum, (string) $unit, $decimals);
        }

        return implode(' · ', $parts);
    }

    /**
     * Единица, общая для всех значений; разные или пусто → null.
     *
     * @param  iterable<?string>  $units
     */
    public static function common(iterable $units): ?string
    {
        $found = null;
        foreach ($units as $unit) {
            $unit = self::label($unit);
            if ($found !== null && $unit !== $found) {
                return null;
            }
            $found = $unit;
        }

        return $found === '' ? null : $found;
    }
}
