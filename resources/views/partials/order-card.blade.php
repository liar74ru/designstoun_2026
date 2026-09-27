<div class="info-block mb-2 order-row {{ $order->is_urgent ? 'order-urgent' : '' }}" style="cursor:pointer"
     data-href="{{ route('orders.show', $order->moysklad_id) }}">
    <div class="info-block-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold small text-dark">
            <a href="{{ route('orders.show', $order->moysklad_id) }}" class="text-reset text-decoration-none">
                {{ $order->name }} <i class="bi bi-chevron-right" style="font-size:.7rem"></i>
            </a>
            @include('orders.partials.changed-badge', ['order' => $order, 'class' => 'ms-1'])
            <span class="text-muted ms-1">{{ $order->moment?->format('d.m.Y') }}</span>
            @if($order->delivery_planned_at)
                <span class="text-muted ms-1" title="Планируемая дата отгрузки">· отгр. {{ $order->delivery_planned_at->format('d.m.Y') }}</span>
            @endif
        </span>
        @include('orders.partials.state-picker', [
            'order'  => $order,
            'states' => $orderStates ?? collect(),
            'size'   => 'sm',
        ])
    </div>
    <div class="info-block-body">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
            <div class="fw-semibold">
                {{ $order->counterparty?->name ?? $order->agent_name ?? '—' }}
            </div>
            @include('orders.partials.priority-controls', ['order' => $order, 'layout' => 'row'])
        </div>

        @include('partials.order-items-table', ['rows' => $rows, 'order' => $order])

        <div class="mt-2">
            @include('orders.partials.departments-button', ['order' => $order, 'font' => '.7rem'])
        </div>
    </div>
</div>
