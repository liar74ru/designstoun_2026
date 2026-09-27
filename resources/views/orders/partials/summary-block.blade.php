{{--
    Блок «Заявка» в карточке заявки: клиент и статус; плитки «дата заказа / готовность / срочно»;
    полоса готовности; итоги «заказано / осталось / дефицит»; отделы; период производства и пересчёт.

    Параметры: $order, $orderStates, $rows, $fmt1, $sumOrdered, $sumLeft, $sumShort, $hasNumbers.
    Стили — orders.partials.summary-block-assets.
--}}
@php
    // Готовность: какая доля неотгруженного остатка закрыта складом и изготовленным —
    // та же мера, что у полосы в карточке позиции.
    $readyPct   = $hasNumbers && $sumLeft > 0 ? (int) round(($sumLeft - $sumShort) / $sumLeft * 100) : null;
    $readyColor = $readyPct === null ? '#adb5bd' : ($readyPct >= 100 ? '#16a34a' : ($readyPct > 0 ? '#f59e0b' : '#dc3545'));

    // Срок: сколько дней до плановой готовности (минус — просрочено).
    $due     = $order->delivery_planned_at;
    $daysDue = $due ? (int) now()->startOfDay()->diffInDays($due->copy()->startOfDay(), false) : null;
    $dueText = match (true) {
        $daysDue === null => null,
        $daysDue > 0      => 'через ' . $daysDue . ' дн.',
        $daysDue === 0    => 'сегодня',
        default           => 'просрочено ' . abs($daysDue) . ' дн.',
    };
    $dueClass = match (true) {
        $daysDue === null => '',
        $daysDue < 0      => 'text-danger',
        $daysDue <= 2     => 'text-warning-emphasis',
        default           => 'text-muted',
    };
@endphp
<div class="info-block osum">
    <div class="info-block-body">
        <div class="d-flex justify-content-between align-items-start gap-2">
            <div style="min-width:0">
                <div class="osum-label">Контрагент</div>
                <div class="osum-client">{{ $order->counterparty?->name ?? $order->agent_name ?? '—' }}</div>
            </div>
            <div class="flex-shrink-0">
                @include('orders.partials.state-picker', ['order' => $order, 'states' => $orderStates, 'size' => 'sm'])
            </div>
        </div>

        <div class="osum-tiles osum-dates">
            <div>
                <small>дата заказа</small>
                <b>{{ $order->moment ? $order->moment->format('d.m.Y') : '—' }}</b>
            </div>
            <button type="button" class="osum-due-btn"
                    data-bs-toggle="modal" data-bs-target="#order-delivery-date-modal"
                    data-action="{{ route('orders.delivery-date.update', $order->moysklad_id) }}"
                    data-name="{{ $order->name }}"
                    data-date="{{ $due?->format('Y-m-d') }}"
                    title="Дата готовности — нажмите, чтобы изменить">
                <small>готовность <i class="bi bi-pencil-square"></i></small>
                <b>{{ $due ? $due->format('d.m.Y') : '—' }}</b>
                @if($dueText)<span class="osum-due {{ $dueClass }}">{{ $dueText }}</span>@endif
            </button>
            <form method="POST" action="{{ route('orders.priority.urgent', $order->moysklad_id) }}" data-submit-guard class="d-flex">
                @csrf
                <input type="hidden" name="urgent" value="{{ $order->is_urgent ? 0 : 1 }}">
                <button type="submit" class="osum-urgent {{ $order->is_urgent ? 'is-on' : '' }}"
                        title="{{ $order->is_urgent ? 'Снять срочность' : 'Поднять заявку в начало очереди' }}">
                    <small>срочно</small>
                    <span class="osum-switch"><span></span></span>
                </button>
            </form>
        </div>

        <div class="osum-ready">
            <div class="d-flex justify-content-between align-items-baseline">
                <span class="osum-label mb-0">готовность</span>
                <b style="color: {{ $readyColor }}">{{ $readyPct !== null ? $readyPct . '%' : '—' }}</b>
            </div>
            <div class="osum-bar"><span style="width: {{ $readyPct ?? 0 }}%; background: {{ $readyColor }}"></span></div>
        </div>

        <div class="osum-tiles">
            <div>
                <small>заказано</small>
                <b>{{ $fmt1($sumOrdered) }}</b>
            </div>
            <div>
                <small>осталось</small>
                <b>{{ $fmt1($sumLeft) }}</b>
            </div>
            @if($hasNumbers)
                <div class="{{ $sumShort > 0 ? 'is-bad' : 'is-ok' }}">
                    <small>дефицит</small>
                    <b>@if($sumShort > 0){{ $fmt1($sumShort) }}@else<i class="bi bi-check-lg"></i> нет@endif</b>
                </div>
            @endif
        </div>

        <div class="osum-departments">
            <div style="min-width:0">
                <div class="osum-label">Отделы</div>
                @include('orders.partials.departments-button', ['order' => $order])
            </div>
            @if($order->priority_manual)
                <form method="POST" action="{{ route('orders.priority.reset', $order->moysklad_id) }}" data-submit-guard
                      class="flex-shrink-0" onsubmit="return confirm('Вернуть заявку на место по сроку отгрузки?')">
                    @csrf
                    <button type="submit" class="btn btn-sm osum-chip" title="Место в очереди задано вручную">
                        <i class="bi bi-pin-angle-fill"></i> Вернуть по сроку
                    </button>
                </form>
            @endif
        </div>

        <div class="osum-footer">
            @if($order->production_started_at)
                <span>
                    <i class="bi bi-gear"></i>
                    производство с {{ $order->production_started_at->format('d.m H:i') }}
                    @if($order->production_ended_at)
                        по {{ $order->production_ended_at->format('d.m H:i') }}
                    @else
                        <span class="badge bg-success-subtle text-success-emphasis">идёт</span>
                    @endif
                </span>
            @else
                <span></span>
            @endif
            {{-- Отдельная форма: вкладывать её в форму переключателя статуса нельзя --}}
            <form method="POST" action="{{ route('orders.recalculate', $order->moysklad_id) }}" data-submit-guard
                  onsubmit="return confirm('Взять текущие остатки по складам и начать отсчёт изготовленного заново?')">
                @csrf
                <button type="submit" class="btn btn-link btn-sm text-muted p-0" title="Пересчитать по складам">
                    <i class="bi bi-arrow-repeat"></i> Пересчитать
                </button>
            </form>
        </div>
    </div>
</div>
