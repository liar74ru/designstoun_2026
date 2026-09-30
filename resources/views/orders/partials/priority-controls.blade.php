{{--
    Управление очередью заявки: ↑/↓, «срочно», сброс ручного места.
    Параметры: $order, $layout — 'column' (колонка таблицы) или 'row' (шапка карточки).

    Фильтры текущего списка уходят в query string: сосед при перемещении ищется
    в том же списке, который видит пользователь (OrderPriorityService::move).
--}}
@php
    $layout = $layout ?? 'column';
    $query  = request()->query();
@endphp
<div class="order-priority d-flex {{ $layout === 'row' ? 'flex-row' : 'flex-column' }} align-items-center gap-1">
    <form method="POST" action="{{ route('orders.priority.move', [$order->uuid] + $query) }}" data-submit-guard>
        @csrf
        <input type="hidden" name="direction" value="up">
        <button type="submit" class="priority-btn" title="Поднять в очереди">
            <i class="bi bi-chevron-up"></i>
        </button>
    </form>

    <form method="POST" action="{{ route('orders.priority.urgent', $order->uuid) }}" data-submit-guard>
        @csrf
        <input type="hidden" name="urgent" value="{{ $order->is_urgent ? 0 : 1 }}">
        <button type="submit" class="priority-btn {{ $order->is_urgent ? 'is-urgent' : '' }}"
                title="{{ $order->is_urgent ? 'Снять отметку «срочно»' : 'Срочно — закрепить сверху' }}">
            <i class="bi {{ $order->is_urgent ? 'bi-fire' : 'bi-lightning' }}"></i>
        </button>
    </form>

    <form method="POST" action="{{ route('orders.priority.move', [$order->uuid] + $query) }}" data-submit-guard>
        @csrf
        <input type="hidden" name="direction" value="down">
        <button type="submit" class="priority-btn" title="Опустить в очереди">
            <i class="bi bi-chevron-down"></i>
        </button>
    </form>

    @if($order->priority_manual)
        <form method="POST" action="{{ route('orders.priority.reset', $order->uuid) }}" data-submit-guard
              onsubmit="return confirm('Вернуть заявку на место по сроку отгрузки?')">
            @csrf
            <button type="submit" class="priority-btn is-manual" title="Место задано вручную — вернуть по сроку отгрузки">
                <i class="bi bi-pin-angle-fill"></i>
            </button>
        </form>
    @endif
</div>

@once
    @push('styles')
        <style>
            .priority-btn {
                width: 26px;
                height: 24px;
                padding: 0;
                border: 1px solid #dee2e6;
                border-radius: .4rem;
                background: #fff;
                color: #6c757d;
                line-height: 1;
                /* Хелпер data-submit-guard ставит спиннер с текстом — в узкой кнопке виден только спиннер */
                overflow: hidden;
                white-space: nowrap;
            }
            .priority-btn:hover { background: #f1f3f5; color: #212529; }
            .priority-btn.is-urgent { color: #dc3545; border-color: #dc3545; }
            .priority-btn.is-manual { color: #0d6efd; border-color: #0d6efd; }
            .order-urgent > td:first-child { box-shadow: inset 3px 0 0 #dc3545; }
            .info-block.order-urgent { border-left: 3px solid #dc3545; }
        </style>
    @endpush
@endonce
