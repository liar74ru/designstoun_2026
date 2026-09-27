{{--
    Мобильная карточка заявки в списке.
    Шапка: номер, отделы и статус; клиент; строка «дата заказа → дата готовности» (по нажатию на
    срок — модалка orders.partials.delivery-date-modal) и кнопки очереди группой.
    Позиции — «только проблемы»: сводка «готово N из M · не хватает X», видны позиции с нехваткой
    (с формулой «склад + изгот.»), остальные свёрнуты в «ещё N в порядке». Фон строки — градиент
    цвета камня; отмеченная готовой — зелёная, с меткой «✓ Готово».

    Параметры: $order, $rows, $hiddenCount, $orderStates.
    Стили — partials.order-card-assets; строка позиции — partials.order-card-row.
--}}
@php
    $fmt1  = fn ($v) => number_format((float) $v, 1, '.', '');
    $num   = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', "\u{202F}"), '0'), '.');
    $uomOf = fn ($r) => strtr((string) ($r['item']->uom_name ?: $r['product']?->uom), ['м2' => 'м²', 'м3' => 'м³']);

    // Скрытые, но не показанные — о них напоминает строка под позициями.
    $hiddenLeft = ($hiddenCount ?? 0) - $rows->where('hidden', true)->count();

    // Дата готовности — плановая отгрузка; просрочена, если день уже прошёл.
    $due     = $order->delivery_planned_at;
    $overdue = $due && $due->copy()->startOfDay()->lt(now()->startOfDay());

    // Позиция «в порядке»: отгружена, отмечена готовой или хватает склада и изготовленного.
    $isOk     = fn ($r) => $r['done'] || $r['isReady'] || ($r['short'] !== null && $r['short'] <= 0);
    $problems = $rows->reject($isOk);
    $okRows   = $rows->filter($isOk);
    $sumShort = $problems->sum('short');
@endphp
<div class="info-block mb-2 order-row ocard {{ $order->is_urgent ? 'order-urgent' : '' }}" style="cursor:pointer"
     data-href="{{ route('orders.show', $order->moysklad_id) }}">

    <div class="ocard-head">
        <div class="d-flex align-items-center gap-2">
            <a href="{{ route('orders.show', $order->moysklad_id) }}" class="ocard-num">№ {{ $order->name }}</a>
            @include('orders.partials.departments-button', ['order' => $order, 'font' => '.68rem'])
            @include('orders.partials.changed-badge', ['order' => $order])
            <span class="ms-auto">
                @include('orders.partials.state-picker', ['order' => $order, 'states' => $orderStates ?? collect(), 'size' => 'sm'])
            </span>
        </div>
        <div class="ocard-client">{{ $order->counterparty?->name ?? $order->agent_name ?? '—' }}</div>
        <div class="ocard-meta">
            <span title="Дата заказа">{{ $order->moment?->format('d.m.Y') ?? '—' }}</span>
            <i class="bi bi-arrow-right"></i>
            <button type="button" class="ocard-due {{ $overdue ? 'is-late' : '' }}"
                    data-bs-toggle="modal" data-bs-target="#order-delivery-date-modal"
                    data-action="{{ route('orders.delivery-date.update', $order->moysklad_id) }}"
                    data-name="{{ $order->name }}"
                    data-date="{{ $due?->format('Y-m-d') }}"
                    title="{{ $overdue ? 'Срок готовности просрочен' : 'Дата готовности' }} — нажмите, чтобы изменить">
                <i class="bi bi-flag-fill"></i> {{ $due ? $due->format('d.m.Y') : 'срок?' }}
            </button>
            <span class="ms-auto">
                @include('orders.partials.priority-controls', ['order' => $order, 'layout' => 'row'])
            </span>
        </div>
    </div>

    <div class="ocard-body">
        @if($rows->isEmpty() && $hiddenLeft > 0)
            <div class="ocard-note"><i class="bi bi-eye-slash"></i> Все позиции скрыты для вашего отдела ({{ $hiddenLeft }})</div>
        @endif

        @if($rows->isNotEmpty())
            <div class="ocard-summary {{ $problems->isEmpty() ? 'is-ok' : '' }}">
                @if($problems->isEmpty())
                    <span><i class="bi bi-check-circle-fill"></i> Всё готово · {{ $rows->count() }} поз.</span>
                @else
                    <span>готово <b>{{ $okRows->count() }}</b> из {{ $rows->count() }}</span>
                    @if($sumShort > 0)<span class="ocard-short">не хватает {{ $fmt1($sumShort) }}</span>@endif
                @endif
            </div>
        @endif

        @foreach($problems as $row)
            @include('partials.order-card-row', ['row' => $row, 'formula' => true])
        @endforeach

        @if($okRows->isNotEmpty())
            <details class="ocard-more">
                <summary>{{ $problems->isEmpty() ? 'показать позиции' : 'ещё ' . $okRows->count() . ' в порядке' }}</summary>
                @foreach($okRows as $row)
                    @include('partials.order-card-row', ['row' => $row, 'formula' => false])
                @endforeach
            </details>
        @endif

        @if($hiddenLeft > 0 && $rows->isNotEmpty())
            <a href="{{ route('orders.show', $order->moysklad_id) }}" class="ocard-note">
                <i class="bi bi-eye-slash"></i> + {{ $hiddenLeft }} скрыто
            </a>
        @endif
    </div>
</div>
