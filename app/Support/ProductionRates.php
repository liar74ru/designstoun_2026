<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Единая точка чтения глобальных коэффициентов-модификаторов себестоимости.
 *
 * Реестр ключей — config/production_rates.php, там же дефолты. Значения хранятся
 * в таблице `settings` (кэш живёт внутри Setting::get, префикс «setting.»),
 * поэтому собственного кэша здесь нет — иначе правка настройки не подхватывалась бы
 * сразу.
 *
 * Дефолты в коде дублировать нельзя: и PHP-расчёт, и JS-превью в формах берут их
 * отсюда (см. partials/production-rates-js.blade.php), иначе копии разъезжаются
 * и превью перестаёт совпадать с сохранённым значением.
 *
 * Ставки, задаваемые per-department, живут не здесь, а в
 * config/department_settings.php + App\Support\DepartmentSettings.
 */
class ProductionRates
{
    public const UNDERCUT_PENALTY      = 'UNDERCUT_PENALTY';
    public const EDGING_COEFF          = 'EDGING_COEFF';
    public const MASK_TILE_COEFF_BONUS = 'MASK_TILE_COEFF_BONUS';

    /** Значение ключа реестра с фолбэком на default из конфига. */
    public static function get(string $key): float
    {
        return (float) Setting::get($key, self::default($key));
    }

    /** Default из реестра; неизвестный ключ → 0. */
    public static function default(string $key): float
    {
        return (float) (config("production_rates.{$key}.default") ?? 0);
    }

    /** Штраф коэффициента за флаг «подкол > 80%». */
    public static function undercutPenalty(): float
    {
        return self::get(self::UNDERCUT_PENALTY);
    }

    /** Коэффициент «Торцовка» — полная замена коэффициента продукта. */
    public static function edgingCoeff(): float
    {
        return self::get(self::EDGING_COEFF);
    }

    /** Бонус коэффициента для SKU плитки-маски (04-07-XX). */
    public static function maskTileBonus(): float
    {
        return self::get(self::MASK_TILE_COEFF_BONUS);
    }

    /**
     * Карта [ключ => значение] всех коэффициентов реестра.
     * Используется для передачи в JS одним блоком.
     *
     * @return array<string, float>
     */
    public static function all(): array
    {
        $map = [];
        foreach (self::keys() as $key) {
            $map[$key] = self::get($key);
        }

        return $map;
    }

    /**
     * Плоский список ключей реестра (whitelist).
     *
     * @return string[]
     */
    public static function keys(): array
    {
        return array_keys(config('production_rates', []));
    }

    /** Разрешено ли ключу отрицательное значение (для валидации в админке). */
    public static function allowsNegative(string $key): bool
    {
        return (bool) config("production_rates.{$key}.allow_negative", false);
    }
}
