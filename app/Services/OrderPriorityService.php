<?php

namespace App\Services;

use App\Casts\PreciseFloat;
use App\Models\Order;
use App\Models\OrderState;
use App\Support\OrderPriority;
use Illuminate\Http\Request;

/**
 * Ручное управление очередью заявок: перемещение ↑/↓, «срочно», возврат к авто-порядку.
 *
 * Очередь — `Order::prioritized()`. Перемещение не переписывает соседей: заявка
 * получает ключ посередине между соседями в том списке, который видит пользователь
 * (с его фильтрами), и флаг priority_manual — синхронизация такой ключ не трогает.
 */
class OrderPriorityService
{
    public const UP = 'up';
    public const DOWN = 'down';

    public function __construct(private OrderService $orders)
    {
    }

    /**
     * Сдвинуть заявку на одну позицию. Границы «срочные / обычные» и «в конце списка /
     * остальные» не пересекаются: срочность ставится отметкой, конец списка — статусом.
     *
     * @return bool  false — двигать некуда (заявка уже крайняя)
     */
    public function move(Order $order, string $direction, Request $request): bool
    {
        $up  = $direction === self::UP;
        $key = PreciseFloat::toSql($order->priority_key);

        // Заявки «в конце списка» (Order::listOrdered) — отдельная группа, как срочные:
        // иначе стрелка перепрыгнула бы через весь список к соседу по ключу.
        $bottom   = OrderState::listBottomIds();
        $isBottom = in_array($order->state_moysklad_id, $bottom, true);

        // Соседи в направлении движения, ближайший первым. Сравниваем парой (ключ, id) —
        // так же сортирует prioritized().
        $neighbours = $this->orders->indexQuery($request)
            ->where('is_urgent', $order->is_urgent)
            ->when($bottom !== [], fn ($q) => $isBottom
                ? $q->whereIn('state_moysklad_id', $bottom)
                : $q->where(fn ($w) => $w
                    ->whereNotIn('state_moysklad_id', $bottom)
                    ->orWhereNull('state_moysklad_id')))
            ->where('id', '!=', $order->id)
            ->where(fn ($q) => $q
                ->where('priority_key', $up ? '<' : '>', $key)
                ->orWhere(fn ($w) => $w
                    ->where('priority_key', $key)
                    ->where('id', $up ? '<' : '>', $order->id)))
            ->orderBy('priority_key', $up ? 'desc' : 'asc')
            ->orderBy('id', $up ? 'desc' : 'asc')
            ->limit(2)
            ->pluck('priority_key');

        if ($neighbours->isEmpty()) {
            return false;
        }

        $near = (float) $neighbours[0];
        $far  = isset($neighbours[1]) ? (float) $neighbours[1] : $near + ($up ? -1 : 1);

        $order->update([
            'priority_key'    => ($near + $far) / 2,
            'priority_manual' => true,
        ]);

        return true;
    }

    public function setUrgent(Order $order, bool $urgent): void
    {
        $order->update(['is_urgent' => $urgent]);
    }

    /** Вернуть заявку на место по сроку отгрузки и дате. */
    public function resetManual(Order $order): void
    {
        $order->update([
            'priority_key'    => OrderPriority::autoKey($order->delivery_planned_at, $order->moment),
            'priority_manual' => false,
        ]);
    }
}
