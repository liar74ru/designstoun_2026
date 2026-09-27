{{-- Плашка «Изменена»: состав заявки поменяли в МойСклад, мастер ещё не нажал «Принято»,
     или заявка в статусе «Изменено». Параметры: $order, $changedStateId. --}}
@if($order->positions_changed_at || (($changedStateId ?? null) && $order->state_moysklad_id === $changedStateId))
    <span class="badge bg-warning text-dark {{ $class ?? '' }}"
          title="{{ $order->positions_changed_at
              ? 'Позиции изменены ' . $order->positions_changed_at->format('d.m.Y H:i') . ' — откройте заявку'
              : 'Заявка в статусе «Изменено»' }}">
        <i class="bi bi-exclamation-triangle"></i> Изменена
    </span>
@endif
