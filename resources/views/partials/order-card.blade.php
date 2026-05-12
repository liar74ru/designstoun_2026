@php
    $productionStoreId = $productionStoreId ?? null;
@endphp
<div class="info-block mb-2">
    <div class="info-block-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold small text-dark">
            {{ $order->name }}
            <span class="text-muted ms-1">{{ $order->moment?->format('d.m.Y') }}</span>
        </span>
        <span class="badge text-white"
              style="background-color: {{ \App\Models\Order::stateColor($order->state_name) }}">
            {{ $order->state_name ?? '—' }}
        </span>
    </div>
    <div class="info-block-body">
        <div class="fw-semibold mb-1">
            {{ $order->counterparty?->name ?? $order->agent_name ?? '—' }}
        </div>

        @include('partials.order-items-table', ['order' => $order, 'productionStoreId' => $productionStoreId])

        @if($order->departments->isNotEmpty())
            <div class="d-flex flex-wrap gap-1 mt-2">
                @foreach($order->departments as $dept)
                    <span class="badge bg-light text-dark border" style="font-size:.7rem">
                        {{ $dept->name }}
                    </span>
                @endforeach
            </div>
        @endif
    </div>
</div>
