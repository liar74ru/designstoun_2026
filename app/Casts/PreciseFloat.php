<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Float без потери точности при записи в БД.
 *
 * Laravel биндит float строкой, а PHP переводит его в строку с `precision = 14`:
 * 199411790244479.5 уходит в запрос как 1.9941179024448E+14. Для ключа очереди заявок
 * (≈2·10¹⁴) это значит, что середина между соседями пишется ключом соседа.
 * Здесь число передаётся кратчайшей точной записью (как json_encode).
 */
class PreciseFloat implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?float
    {
        return $value === null ? null : (float) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : self::toSql((float) $value);
    }

    /** Значение для биндинга в запрос — и при записи, и в сравнениях WHERE. */
    public static function toSql(float $value): string
    {
        return json_encode($value);
    }
}
