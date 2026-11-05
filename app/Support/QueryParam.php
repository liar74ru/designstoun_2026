<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * Параметры фильтров из адресной строки. Значение может быть любым (ссылку правят руками):
 * неверное считается незаданным, иначе дата роняет страницу, а строка в числовой колонке —
 * запрос на PostgreSQL.
 */
class QueryParam
{
    /** Верхняя граница колонки integer в PostgreSQL. */
    private const MAX_ID = 2147483647;

    public static function date(Request $request, string $key): ?Carbon
    {
        $value = $request->input($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    public static function id(Request $request, string $key): ?int
    {
        return self::parseId($request->input($key));
    }

    public static function uuid(Request $request, string $key): ?string
    {
        return self::uuidValues($request->input($key))[0] ?? null;
    }

    /** @return array<int, int> */
    public static function ids(Request $request, string $key): array
    {
        return array_values(array_filter(array_map(self::parseId(...), (array) $request->input($key, []))));
    }

    /**
     * Значение фильтра spatie (строка, «1,2» или массив) → корректные id, неверные отброшены.
     *
     * @return array<int, int>
     */
    public static function idValues(mixed $value): array
    {
        return array_values(array_filter(array_map(self::parseId(...), (array) $value)));
    }

    /** @return array<int, string> */
    public static function uuidValues(mixed $value): array
    {
        return array_values(array_filter((array) $value, fn ($v) => is_string($v) && Str::isUuid($v)));
    }

    /** Фильтр «точно равно» по целочисленному id — вместо AllowedFilter::exact. */
    public static function idFilter(string $name, ?string $column = null): AllowedFilter
    {
        return AllowedFilter::callback($name, function (Builder $query, $value) use ($name, $column) {
            if ($ids = self::idValues($value)) {
                $query->whereIn($query->qualifyColumn($column ?? $name), $ids);
            }
        });
    }

    /** То же для uuid-ключей (склады, контрагенты). */
    public static function uuidFilter(string $name, ?string $column = null): AllowedFilter
    {
        return AllowedFilter::callback($name, function (Builder $query, $value) use ($name, $column) {
            if ($ids = self::uuidValues($value)) {
                $query->whereIn($query->qualifyColumn($column ?? $name), $ids);
            }
        });
    }

    private static function parseId(mixed $value): ?int
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || ! ctype_digit($value) || strlen($value) > 10) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 && $id <= self::MAX_ID ? $id : null;
    }
}
