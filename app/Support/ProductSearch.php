<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Http\Request;

/**
 * Поиск товаров по части названия для фильтров списков («Все, где есть …» в пикере).
 *
 * Зеркало tokenize()/fuzzyMatch() из resources/js/product-picker.js: фильтр находит ровно те
 * товары, что пользователь видел в выпадашке. Меняешь правило — меняй в обоих местах.
 *
 * Сопоставление — в PHP, а не ILIKE: в SQLite (тесты) нет ILIKE, а LOWER()/LIKE не складывают
 * регистр кириллицы. Каталог — сотни строк, выборка id+name дешёвая.
 */
class ProductSearch
{
    /**
     * Латинские буквы, похожие на кириллические: в названиях из МойСклад встречаются обе
     * («50xL» и «50хL»). Применяется после нижнего регистра; ё приравнивается к е.
     */
    private const LOOKALIKES = [
        'a' => 'а', 'b' => 'в', 'c' => 'с', 'e' => 'е', 'h' => 'н', 'k' => 'к', 'm' => 'м',
        'o' => 'о', 'p' => 'р', 't' => 'т', 'x' => 'х', 'y' => 'у', 'ё' => 'е',
    ];

    /** @return array<int, string> слова строки: нижний регистр, двойники → кириллица, прочее → разделитель */
    public static function tokenize(string $str): array
    {
        $str = strtr(mb_strtolower($str), self::LOOKALIKES);

        return preg_split('/[^a-zа-я\d]+/u', $str, -1, PREG_SPLIT_NO_EMPTY);
    }

    /** Каждое слово запроса — подстрока какого-то слова названия. */
    public static function matches(string $name, string $query): bool
    {
        $nameTokens = self::tokenize($name);

        foreach (self::tokenize($query) as $queryToken) {
            $found = false;
            foreach ($nameTokens as $nameToken) {
                if (str_contains($nameToken, $queryToken)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /**
     * id товаров, подходящих под запрос. Запрос без слов («—», пробелы) → null: фильтра нет.
     * Ничего не подошло → [-1], чтобы whereIn вернул пустоту, а не снял фильтр.
     *
     * @return array<int, int>|null
     */
    public static function matchIds(mixed $query): ?array
    {
        // Spatie режет значение фильтра по запятым — склеиваем обратно
        $query = implode(' ', array_filter((array) $query, 'is_scalar'));

        if (self::tokenize($query) === []) {
            return null;
        }

        $ids = Product::query()->pluck('name', 'id')
            ->filter(fn ($name) => self::matches((string) $name, $query))
            ->keys()
            ->all();

        return $ids ?: [-1];
    }

    /**
     * Фильтр по товару вне Spatie (дашборды): выбранный товар и/или текст поиска.
     * Оба заданы (ссылку правили руками) — пересечение. Ничего не задано → null.
     *
     * @return array<int, int>|null
     */
    public static function idsFromRequest(Request $request, string $idKey, string $searchKey): ?array
    {
        $id     = QueryParam::id($request, $idKey);
        $search = $request->input($searchKey);
        $found  = is_string($search) ? self::matchIds($search) : null;

        if ($id === null) {
            return $found;
        }

        if ($found === null) {
            return [$id];
        }

        return in_array($id, $found, true) ? [$id] : [-1];
    }
}
