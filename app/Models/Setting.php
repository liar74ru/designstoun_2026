<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Ключи настроек приложения:
 *
 * Расчёт зарплаты:
 *   PIECE_RATE             — базовая ставка пильщика (₽, используется в Product::prodCost())
 *   UNDERCUT_PENALTY       — штраф коэффициента при флаге «подкол > 80%» (StoneReceptionItem)
 *
 * Себестоимость производства (₽/м²):
 *   BLADE_WEAR, RECEPTION_COST, PACKAGING_COST, WASTE_REMOVAL,
 *   ELECTRICITY, PPE_COST, FORKLIFT_COST, MACHINE_COST, RENT_COST, OTHER_COSTS
 *
 * Ставки мастера (₽/м²):
 *   MASTER_BASE_RATE       — базовая ставка за м²
 *   MASTER_UNDERCUT_RATE   — надбавка за подкол > 80%
 *   MASTER_PACKAGING_RATE  — надбавка за фасовку в ящик
 *   MASTER_SMALL_TILE_RATE — надбавка за мелкую плитку < 50 мм
 *
 * МойСклад (строки):
 *   MOYSKLAD_IN_WORK_STATE — имя статуса «в работе» техоперации
 *   MOYSKLAD_DONE_STATE    — имя завершающего статуса техоперации
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value', 'label', 'description'];

    private const CACHE_TTL = 86400;
    private const CACHE_PREFIX = 'setting.';

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember(
            self::CACHE_PREFIX . $key,
            self::CACHE_TTL,
            fn () => static::where('key', $key)->value('value') ?? $default
        );
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_PREFIX . $key);
    }
}
