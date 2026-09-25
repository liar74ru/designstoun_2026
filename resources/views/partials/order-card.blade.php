<div class="info-block mb-2 order-row" style="cursor:pointer"
     data-href="{{ route('orders.show', $order->moysklad_id) }}">
    <div class="info-block-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold small text-dark">
            <a href="{{ route('orders.show', $order->moysklad_id) }}" class="text-reset text-decoration-none">
                {{ $order->name }} <i class="bi bi-chevron-right" style="font-size:.7rem"></i>
            </a>
            <span class="text-muted ms-1">{{ $order->moment?->format('d.m.Y') }}</span>
        </span>
        @include('orders.partials.state-picker', [
            'order'  => $order,
            'states' => $orderStates ?? collect(),
            'size'   => 'sm',
        ])
    </div>
    <div class="info-block-body">
        <div class="fw-semibold mb-1">
            {{ $order->counterparty?->name ?? $order->agent_name ?? '—' }}
        </div>

        @include('partials.order-items-table', ['rows' => $rows])

        <div class="mt-2">
            @include('orders.partials.departments-button', ['order' => $order, 'font' => '.7rem'])
        </div>
    </div>
</div>
