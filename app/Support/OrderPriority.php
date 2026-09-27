<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Ключ приоритета заявки: меньше — выше в очереди.
 *
 * Все критерии сведены в одно число, чтобы ручное перемещение ложилось между
 * соседями без переписывания остальных заявок (середина между ключами соседей).
 *
 * Ключ = день срока отгрузки × 10¹⁰ + unix-время даты заявки. Срок берётся днём, а не
 * моментом: у многих заявок он один, и при равных ключах заявку нельзя было бы
 * поставить между соседями. Внутри дня порядок решает дата заявки — ключи почти
 * не совпадают. Всё укладывается в точные целые double (< 2⁵³).
 */
class OrderPriority
{
    /** Множитель дня: больше любого unix-времени даты заявки. */
    public const DAY_FACTOR = 1e10;

    /** «День» заявок без срока — после любой заявки со сроком (≈ 2243 год). */
    public const NO_DEADLINE_DAY = 99999;

    public static function autoKey(?CarbonInterface $deliveryPlannedAt, ?CarbonInterface $moment): float
    {
        $day = $deliveryPlannedAt
            ? intdiv($deliveryPlannedAt->copy()->startOfDay()->getTimestamp(), 86400)
            : self::NO_DEADLINE_DAY;

        return $day * self::DAY_FACTOR + (float) ($moment?->getTimestamp() ?? 0);
    }
}
