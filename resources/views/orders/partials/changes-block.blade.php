{{-- Состав заявки изменили в МойСклад (у внутреннего заказа — заказчик в программе): что поменялось
     с последнего «Принято» и кнопка «Принято». Параметры: $order, $changedStateId, $returnState,
     $fmtQty, $canAcknowledge (по умолчанию true). --}}
@php
    $inChangedState = $changedStateId && $order->state_moysklad_id === $changedStateId;
@endphp
@if($order->positions_changed_at || $inChangedState)
    <div class="info-block border-warning">
        <div class="info-block-header small fw-semibold d-flex justify-content-between align-items-center"
             style="background:#fff3cd">
            <span><i class="bi bi-exclamation-triangle text-warning"></i> {{ $order->isInternal() ? 'Заказ изменён' : 'Заявка изменена' }}</span>
            @if($order->positions_changed_at)
                <span class="text-muted fw-normal" style="font-variant-numeric: tabular-nums">
                    {{ $order->positions_changed_at->format('d.m.Y H:i') }}
                </span>
            @endif
        </div>
        <div class="info-block-body small">
            @forelse($order->position_changes ?? [] as $change)
                <div class="d-flex justify-content-between gap-2 py-1" style="border-bottom:1px solid #f1f3f5">
                    <span style="min-width:0; word-break:break-word">{{ $change['name'] ?? '—' }}</span>
                    <span class="flex-shrink-0" style="white-space:nowrap; font-variant-numeric: tabular-nums">
                        @if((float) $change['from'] == 0)
                            <span class="badge bg-success-subtle text-success-emphasis">новая</span> {{ $fmtQty($change['to']) }}
                        @elseif((float) $change['to'] == 0)
                            <span class="badge bg-danger-subtle text-danger-emphasis">удалена</span>
                            <span class="text-muted text-decoration-line-through">{{ $fmtQty($change['from']) }}</span>
                        @else
                            <span class="text-muted">{{ $fmtQty($change['from']) }}</span> → <b>{{ $fmtQty($change['to']) }}</b>
                        @endif
                    </span>
                </div>
            @empty
                <div class="text-muted">Заявка в статусе «Изменено». Изменений количества программа не заметила.</div>
            @endforelse

            @if($canAcknowledge ?? true)
                <form method="POST" action="{{ route('orders.changes.acknowledge', $order->uuid) }}"
                      class="mt-2" data-submit-guard>
                    @csrf
                    <button type="submit" class="btn btn-warning btn-sm w-100">
                        <i class="bi bi-check2"></i> Принято{{ $inChangedState && $returnState ? ', вернуть в «' . $returnState->name . '»' : '' }}
                    </button>
                </form>
            @else
                {{-- Внутренний заказ: правку заказчика принимает исполнитель --}}
                <div class="text-muted mt-2">Ждёт, пока исполнитель нажмёт «Принято».</div>
            @endif
        </div>
    </div>
@endif
