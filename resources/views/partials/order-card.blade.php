{{--
    Мобильная карточка заявки в списке. Шапка: номер и статус, клиент, чипы «дата заказа» и
    «дата готовности» (просроченная — красная), кнопки очереди. Позиции — плашки с формулой
    «склад + изгот. = всего / заказ» и меткой ✓ / «−N»; отмеченная готовой (.is-ready) —
    в зелёной рамке с плашкой «✓ Готово» вместо метки.

    Параметры: $order, $rows, $hiddenCount, $orderStates.
    Стили — partials.order-card-assets; отметка «готово» — orders.partials.ready-toggle-assets.
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
@endphp
<div class="info-block mb-2 order-row ocard {{ $order->is_urgent ? 'order-urgent' : '' }}" style="cursor:pointer"
     data-href="{{ route('orders.show', $order->moysklad_id) }}">
    <div class="ocard-head">
        <div class="d-flex justify-content-between align-items-center gap-2">
            <a href="{{ route('orders.show', $order->moysklad_id) }}" class="ocard-num">
                № {{ $order->name }} <i class="bi bi-chevron-right"></i>
            </a>
            @include('orders.partials.state-picker', ['order' => $order, 'states' => $orderStates ?? collect(), 'size' => 'sm'])
        </div>
        <div class="d-flex justify-content-between align-items-start gap-2 mt-1">
            <div class="flex-grow-1" style="min-width:0">
                <div class="ocard-client">{{ $order->counterparty?->name ?? $order->agent_name ?? '—' }}</div>
                <div class="ocard-chips">
                    <span class="ocard-chip" title="Дата заказа">
                        <i class="bi bi-calendar3"></i> {{ $order->moment?->format('d.m.Y') ?? '—' }}
                    </span>
                    <button type="button" class="ocard-chip ocard-due {{ $overdue ? 'is-late' : '' }}"
                            data-bs-toggle="modal" data-bs-target="#order-delivery-date-modal"
                            data-action="{{ route('orders.delivery-date.update', $order->moysklad_id) }}"
                            data-name="{{ $order->name }}"
                            data-date="{{ $due?->format('Y-m-d') }}"
                            title="{{ $overdue ? 'Срок готовности просрочен' : 'Дата готовности' }} — нажмите, чтобы изменить">
                        <i class="bi bi-flag-fill"></i> {{ $due ? $due->format('d.m.Y') : 'не задана' }}
                    </button>
                </div>
                @include('orders.partials.changed-badge', ['order' => $order, 'class' => 'mt-1'])
            </div>
            @include('orders.partials.priority-controls', ['order' => $order, 'layout' => 'row'])
        </div>
    </div>

    <div class="ocard-body">
        @if($rows->isEmpty() && $hiddenLeft > 0)
            <div class="text-muted small"><i class="bi bi-eye-slash"></i> Все позиции скрыты для вашего отдела ({{ $hiddenLeft }})</div>
        @endif

        @foreach($rows as $row)
            @php
                $product = $row['product'];
                $canMark = $product && ! $row['done'];
                $hasQty  = $row['totalQty'] !== null;
                $enough  = $hasQty && $row['short'] <= 0;
                $uom     = $uomOf($row);
            @endphp
            <div class="order-pos ocard-pos {{ $row['done'] ? 'is-done' : '' }} {{ $row['isReady'] ? 'is-ready' : '' }} {{ ($row['hidden'] ?? false) ? 'is-hidden' : '' }}"
                 style="--stone:{{ $row['color'] }};{{ $row['color'] === '#FFFFFF' ? '' : '--stone-bg:' . $row['color'] . '14;' }}"
                 @if($canMark) data-position="{{ $order->id }}-{{ $product->id }}" @endif>

                @if($canMark)
                    @include('orders.partials.ready-toggle', ['order' => $order, 'product' => $product])
                @else
                    <span class="ocard-spacer"></span>
                @endif

                <div class="ocard-main">
                    <div class="ocard-name">
                        @if($product)
                            @can('see-products')
                                <a href="{{ route('products.show', $product->moysklad_id) }}" class="text-reset text-decoration-none">{{ $row['name'] }}</a>
                            @else
                                {{ $row['name'] }}
                            @endcan
                        @else
                            {{ $row['name'] }}
                        @endif
                    </div>

                    @unless($row['done'])
                        <div class="ocard-formula">
                            <span title="Склад"><i class="bi bi-box-seam"></i> {{ $row['warehouseQty'] !== null ? $fmt1($row['warehouseQty']) : '—' }}</span>
                            <span class="op">+</span>
                            <span title="Изготовлено"><i class="bi bi-hammer"></i> {{ $row['producedQty'] !== null ? $fmt1($row['producedQty']) : '—' }}</span>
                            <span class="op">=</span>
                            <span title="Всего / заказ"><b>{{ $hasQty ? $fmt1($row['totalQty']) : '—' }}</b> / {{ $num($row['ordered']) }}{{ $uom ? ' ' . $uom : '' }}</span>
                        </div>
                    @endunless

                    @if($row['hiddenFor'])
                        @include('orders.partials.hidden-badge', ['order' => $order, 'row' => $row])
                    @endif
                </div>

                <div class="flex-shrink-0">
                    <span class="ocard-ready-pill"><i class="bi bi-check-lg"></i> Готово</span>
                    <span class="ocard-status">
                        @if($row['done'])
                            <span class="ocard-shipped">отгружено</span>
                        @elseif(! $hasQty)
                            <span class="text-muted">—</span>
                        @elseif($enough)
                            <span class="ocard-ok" title="Хватает"><i class="bi bi-check-lg"></i></span>
                        @else
                            <span class="ocard-bad" title="Не хватает {{ $fmt1($row['short']) }}">−{{ $fmt1($row['short']) }}</span>
                        @endif
                    </span>
                </div>
            </div>
        @endforeach

        @if($hiddenLeft > 0 && $rows->isNotEmpty())
            <a href="{{ route('orders.show', $order->moysklad_id) }}" class="ocard-hidden">
                <i class="bi bi-eye-slash"></i> + {{ $hiddenLeft }} скрыто
            </a>
        @endif

        <div class="ocard-foot">
            <span class="ocard-label">Отделы</span>
            @include('orders.partials.departments-button', ['order' => $order, 'font' => '.7rem'])
        </div>
    </div>
</div>
