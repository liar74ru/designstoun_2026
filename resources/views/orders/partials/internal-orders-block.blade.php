{{--
    Блок «Внутренние заказы» в карточке заявки: полуфабрикаты, заказанные под неё другим отделам.
    Связь — с заявкой целиком: по каждому заказу номер, исполнитель, статус и готовность строк.

    Параметры: $order, $internalOrders (OrderService::internalOrders), $canCreateInternal, $fmt1.
--}}
@if($internalOrders !== [] || $canCreateInternal)
    <div class="info-block">
        <div class="info-block-header small fw-semibold d-flex justify-content-between align-items-center gap-2">
            <span>
                <i class="bi bi-arrow-left-right"></i> Внутренние заказы
                @if($internalOrders !== [])
                    <span class="badge bg-secondary ms-1">{{ count($internalOrders) }}</span>
                @endif
            </span>
            @if($canCreateInternal)
                <a href="{{ route('orders.internal.create', $order->uuid) }}" class="btn btn-sm btn-outline-primary py-0">
                    <i class="bi bi-plus-circle"></i> Заказать
                </a>
            @endif
        </div>
        <div class="info-block-body small">
            @forelse($internalOrders as $internal)
                @php $child = $internal['order']; @endphp
                <div class="py-1 {{ ! $loop->last ? 'border-bottom' : '' }}">
                    <div class="d-flex justify-content-between align-items-center gap-2">
                        <span style="min-width:0">
                            @if($internal['canOpen'])
                                <a href="{{ route('orders.show', $child->uuid) }}" class="fw-semibold">{{ $child->name }}</a>
                            @else
                                <span class="fw-semibold">{{ $child->name }}</span>
                            @endif
                            <span class="text-muted">→ {{ $internal['executor'] ?: '—' }}</span>
                        </span>
                        <span class="badge flex-shrink-0"
                              style="background:{{ $child->state_color }};color:{{ $child->state_text_color }}">
                            {{ $child->state_name ?? '—' }}
                        </span>
                    </div>
                    @foreach($internal['rows'] as $row)
                        @php
                            $done = $row['isReady'] || ($row['total'] !== null && $row['total'] >= $row['ordered']);
                        @endphp
                        <div class="d-flex justify-content-between gap-2 ps-2 mt-1"
                             style="border-left:3px solid {{ $row['color'] }}">
                            <span style="min-width:0; word-break:break-word">{{ $row['name'] }}</span>
                            <span class="flex-shrink-0 {{ $done ? 'text-success fw-semibold' : 'text-muted' }}"
                                  style="white-space:nowrap; font-variant-numeric: tabular-nums">
                                {{ $row['total'] !== null ? $fmt1($row['total']) : '—' }} из {{ $fmt1($row['ordered']) }}{{ $row['uom'] ? ' ' . $row['uom'] : '' }}
                                @if($done) ✓ @endif
                            </span>
                        </div>
                    @endforeach
                </div>
            @empty
                <div class="text-muted">
                    Полуфабрикаты под эту заявку другим отделам не заказаны.
                </div>
            @endforelse
        </div>
    </div>
@endif
