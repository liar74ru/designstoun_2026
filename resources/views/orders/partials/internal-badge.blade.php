{{--
    Связь заявки и внутренних заказов в одну строку.
    Внутренний заказ: «Внутренний» + заявка-основание (ссылкой, если она ещё среди активных,
    иначе имя из снимка с пометкой). Заявка покупателя: номера внутренних заказов под неё.

    Параметры: $order (с загруженными parent / internalChildren), $class — классы обёртки.
--}}
@if($order->isInternal())
    <span class="d-inline-flex flex-wrap align-items-center gap-1 {{ $class ?? '' }}" style="font-size:.72rem">
        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
            <i class="bi bi-arrow-left-right"></i> Внутренний
        </span>
        @if($order->parent)
            <a href="{{ route('orders.show', $order->parent->uuid) }}" class="text-muted text-decoration-none"
               onclick="event.stopPropagation()">
                под заявку {{ $order->parent->name }}
            </a>
        @elseif($order->parent_order_name)
            <span class="text-muted" title="Заявки-основания уже нет среди активных">
                под заявку {{ $order->parent_order_name }} (закрыта)
            </span>
        @endif
    </span>
@elseif($order->relationLoaded('internalChildren') && $order->internalChildren->isNotEmpty())
    <span class="d-inline-flex flex-wrap align-items-center gap-1 {{ $class ?? '' }}" style="font-size:.72rem"
          title="Внутренние заказы полуфабрикатов под эту заявку">
        <i class="bi bi-arrow-left-right text-info"></i>
        @foreach($order->internalChildren as $child)
            <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">{{ $child->name }}</span>
        @endforeach
    </span>
@endif
