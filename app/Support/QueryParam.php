<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\Request;

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

    /** @return array<int, int> */
    public static function ids(Request $request, string $key): array
    {
        return array_values(array_filter(array_map(self::parseId(...), (array) $request->input($key, []))));
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
