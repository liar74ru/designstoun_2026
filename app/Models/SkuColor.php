<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Цвет карточек по SKU-группе: у цвета список префиксов — первые два сегмента SKU
 * («01-07», «04-07»). Читать через Product::getColorBySku(), список ведёт админ в «Настройках».
 */
class SkuColor extends Model
{
    public const CACHE_KEY = 'sku_colors';

    protected $fillable = ['name', 'color', 'prefixes'];

    protected $casts = [
        'prefixes' => 'array',
    ];

    /** @return array<string, string> префикс → цвет */
    public static function map(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $map = [];
            foreach (static::orderBy('id')->get() as $row) {
                foreach ($row->prefixes as $prefix) {
                    $map[$prefix] = $row->color;
                }
            }

            return $map;
        });
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
