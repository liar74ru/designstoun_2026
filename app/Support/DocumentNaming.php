<?php

namespace App\Support;

class DocumentNaming
{
    /**
     * Генерирует имя документа по формату: «ГГ-НН-ПРЕФИКС-ПП»
     * Например: «26-15-ПРОГ-01», «26-15-ТО-03»
     *
     * @param string $prefix   Уникальный код типа документа (ПРОГ, ТО, ПРИЕМ и т.п.)
     * @param int    $sequence Порядковый номер в рамках недели
     */
    public static function weeklyName(string $prefix, int $sequence, ?\Carbon\Carbon $date = null): string
    {
        $d = $date ?? now();
        return $d->format('y') . '-' . $d->format('W')
            . '-' . $prefix
            . '-' . str_pad($sequence, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Генерирует следующее суффиксное имя при коллизии в МойСклад:
     * «26-15-ПРОГ-01»    → «26-15-ПРОГ-01_01»
     * «26-15-ПРОГ-01_01» → «26-15-ПРОГ-01_02»
     */
    public static function nextSuffix(string $name): string
    {
        if (preg_match('/^(.+)_(\d+)$/', $name, $m)) {
            return $m[1] . '_' . str_pad((int) $m[2] + 1, 2, '0', STR_PAD_LEFT);
        }
        return $name . '_01';
    }

    /**
     * Определяет, вернул ли МойСклад ошибку дублирующегося имени документа.
     * Реальный ответ API: code=3006, parameter='name'.
     *
     * @param array $errors Массив errors из ответа МойСклад
     */
    public static function isDuplicateName(array $errors): bool
    {
        $code      = (int) ($errors[0]['code'] ?? 0);
        $parameter = $errors[0]['parameter'] ?? '';
        $msg       = mb_strtolower($errors[0]['error'] ?? '');

        return $code === 3006
            || $parameter === 'name'
            || str_contains($msg, 'уникальн');
    }
}
