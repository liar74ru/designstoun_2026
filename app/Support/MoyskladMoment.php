<?php

namespace App\Support;

class MoyskladMoment
{
    /** Разница между таймзоной приложения (UTC+5) и таймзоной аккаунта МойСклад (UTC+3). */
    private const OFFSET_HOURS = 2;

    /**
     * Форматирует дату для поля «moment» документа МойСклад.
     * МойСклад трактует строку без смещения в таймзоне своего аккаунта,
     * поэтому локальное время приложения сдвигается назад на OFFSET_HOURS.
     *
     * @param \Carbon\Carbon|null $date       Дата документа; null → текущее время
     * @param bool                $withMillis Формат с миллисекундами («.000») — как у supply/purchaseorder
     */
    public static function format(?\Carbon\Carbon $date = null, bool $withMillis = false): string
    {
        $d = ($date ?? now())->copy()->subHours(self::OFFSET_HOURS);

        return $d->format($withMillis ? 'Y-m-d H:i:s.000' : 'Y-m-d H:i:s');
    }
}
