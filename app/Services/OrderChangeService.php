<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderPositionSetting;
use App\Models\OrderState;

/**
 * Изменение состава заявки в МойСклад: мастер видит «было → стало», пока не нажмёт «Принято».
 *
 * Статус «Изменено» ставят вручную и иногда забывают — поэтому изменение ловит сама
 * синхронизация, сравнивая количество по товарам со старым составом.
 */
class OrderChangeService
{
    /**
     * Сравнить состав и накопить изменения с последнего «Принято».
     *
     * При повторном изменении «было» остаётся первоначальным: мастеру важно, что
     * поменялось с тех пор, как он смотрел. Вернули как было — запись снимается.
     *
     * Зовётся после пересоздания позиций: готовность снимается и с только что добавленных.
     *
     * @param  array<string, array{name: ?string, quantity: float}>  $before  [product_moysklad_id => …]
     * @param  array<string, array{name: ?string, quantity: float}>  $after
     */
    public function record(Order $order, array $before, array $after): void
    {
        $changes = $order->position_changes ?? [];
        $grown   = [];
        $touched = false;

        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $from = (float) ($before[$key]['quantity'] ?? 0);
            $to   = (float) ($after[$key]['quantity'] ?? 0);

            if ($from === $to) {
                continue;
            }

            $touched = true;

            if ($to > $from) {
                $grown[] = $key;
            }

            $original = isset($changes[$key]) ? (float) $changes[$key]['from'] : $from;

            if ($original === $to) {
                unset($changes[$key]);
                continue;
            }

            $changes[$key] = [
                'name' => $after[$key]['name'] ?? $before[$key]['name'] ?? $changes[$key]['name'] ?? null,
                'from' => $original,
                'to'   => $to,
            ];
        }

        // Количество не менялось (отгрузка, переименование) — плашку не трогаем.
        if (! $touched) {
            return;
        }

        $order->update([
            'position_changes'     => $changes ?: null,
            'positions_changed_at' => $changes ? now() : null,
        ]);

        // Готовность отмечали под прежнее количество — выросло, значит позицию снова делают.
        if ($grown !== []) {
            OrderPositionSetting::where('order_id', $order->id)
                ->whereIn('product_id', $order->items()->whereIn('product_moysklad_id', $grown)->pluck('product_id'))
                ->update(['ready_at' => null, 'ready_user_id' => null]);
        }
    }

    /** Заявку перевели в «Изменено» — запомнить, откуда, чтобы «Принято» вернуло её туда. */
    public function rememberStateBeforeChange(Order $order, ?string $previousStateId, ?string $newStateId): void
    {
        $changedId = OrderState::changedId();

        if ($changedId === null || $newStateId !== $changedId || $previousStateId === $changedId) {
            return;
        }

        $order->update(['state_before_change' => $previousStateId]);
    }

    /** Статус «Изменено» задан, и заявка сейчас в нём. */
    public function isInChangedState(Order $order): bool
    {
        $changedId = OrderState::changedId();

        return $changedId !== null && $order->state_moysklad_id === $changedId;
    }

    /** Куда вернёт «Принято»: статус до «Изменено», иначе первый производственный. */
    public function returnState(Order $order): ?OrderState
    {
        $state = $order->state_before_change
            ? OrderState::enabled()->find($order->state_before_change)
            : null;

        return $state ?? OrderState::enabled()->production()->orderBy('position')->first();
    }

    /**
     * Количество по товару: позиции одного товара складываются.
     *
     * @return array<string, array{name: ?string, quantity: float}>
     */
    public function quantitiesByProduct(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $key = $item['product_moysklad_id'] ?? null;
            if ($key === null) {
                continue;
            }
            $result[$key] = [
                'name'     => $item['product_name'] ?? null,
                'quantity' => ($result[$key]['quantity'] ?? 0.0) + (float) $item['quantity'],
            ];
        }

        return $result;
    }

    public function acknowledge(Order $order): void
    {
        $order->update([
            'positions_changed_at' => null,
            'position_changes'     => null,
            'state_before_change'  => null,
        ]);
    }
}
