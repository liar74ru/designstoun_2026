{{--
    Мобильная карточка позиции заявки. Три уровня: формула «склад + изгот. = всего» →
    полоса готовности и метка (✓ — хватает, «−N» — нехватка); справа колонка с заказом
    и кнопками «готово» / «скрыть для отдела». Отмеченная готовой (.is-ready) — в зелёной
    рамке с уголком «✓ Готово».

    Параметры: $order, $row, $fmt1, $storeNames, $hideDepartments, $isHidden.
    Стили — orders.partials.mobile-position-assets.
--}}
@php
    $canMark  = $row['product'] && ! $row['done'];
    $canHide  = $row['product'] && $hideDepartments->isNotEmpty();
    $ready    = $row['ready'] ?? 0;
    $barColor = $ready >= 1 ? '#16a34a' : ($ready > 0 ? '#f59e0b' : '#dc3545');
    $known    = $row['totalQty'] !== null;
    $enough   = $known && $row['short'] <= 0;
    $cell     = fn ($field) => ['row' => $row, 'fmt1' => $fmt1, 'field' => $field, 'storeNames' => $storeNames];

    // Единица: у позиции заявки uom_name из МойСклад не приходит — берём из товара.
    $uom = strtr((string) ($row['item']->uom_name ?: $row['product']?->uom), ['м2' => 'м²', 'м3' => 'м³']);

    // Тысячи — узким неразрывным пробелом: с запятой 1250.5 читается как «1,25».
    $num     = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', "\u{202F}"), '0'), '.');
    $shipped = $row['shipped'] > 0 ? $num($row['shipped']) : null;

    // Заказ: целая часть крупно, дробная мелко; размер — ступенькой по числу цифр,
    // чтобы колонка фиксированной ширины вмещала и 12 480.
    [$ordInt, $ordFrac] = array_pad(explode('.', $num($row['ordered']), 2), 2, null);
    $intDigits = strlen(preg_replace('/\D/', '', $ordInt));
    $qtySize   = match (true) {
        $intDigits <= 3  => '',
        $intDigits === 4 => 'is-md',
        default          => 'is-sm',
    };
@endphp
<div class="order-pos mpos {{ $row['isReady'] ? 'is-ready' : '' }} {{ $isHidden($row) ? 'is-hidden' : '' }}"
     style="--stone:{{ $row['color'] }};{{ $row['color'] === '#FFFFFF' ? '' : '--stone-bg:' . $row['color'] . '14;' }}"
     @if($canMark) data-position="{{ $order->id }}-{{ $row['product']->id }}" @endif
     @if($row['product']) data-hide-key="{{ $order->id }}-{{ $row['product']->id }}" @endif>

    <div class="mpos-main">
        <div class="mpos-title">
            <ion-icon name="{{ $row['icon'] }}"></ion-icon>
            <div class="mpos-name {{ $row['done'] ? 'text-decoration-line-through text-muted' : '' }}">
                @if($row['product'])
                    @can('see-products')
                        <a href="{{ route('products.show', $row['product']->moysklad_id) }}" class="text-reset">{{ $row['name'] }}</a>
                    @else
                        {{ $row['name'] }}
                    @endcan
                @else
                    {{ $row['name'] }}
                @endif
            </div>
        </div>
        <div class="d-flex flex-wrap gap-1">
            @include('orders.partials.change-mark', [
                'change' => $order->position_changes[$row['item']->product_moysklad_id] ?? null,
            ])
            @include('orders.partials.hidden-badge', ['order' => $order, 'row' => $row])
        </div>

        @if($row['done'])
            <div class="mpos-done">отгружено {{ $fmt1($row['shipped']) }} — полностью</div>
        @else
            <div class="mpos-formula">
                <span class="mpos-tile is-edit"><small>склад</small>@include('orders.partials.position-cell', $cell('warehouse'))</span>
                <span class="mpos-op">+</span>
                <span class="mpos-tile is-edit"><small>изгот. @include('orders.partials.receptions-link', ['order' => $order, 'row' => $row])</small>@include('orders.partials.position-cell', $cell('produced'))</span>
                <span class="mpos-op">=</span>
                <span class="mpos-tile mpos-total {{ ! $known ? '' : ($enough ? 'is-ok' : 'is-bad') }}">
                    <small>всего</small>
                    <b>{{ $known ? $fmt1($row['totalQty']) : '—' }}</b>
                </span>
            </div>

            @if($known)
                <div class="mpos-status">
                    <div class="mpos-bar"><span style="width:{{ (int) round($ready * 100) }}%;background:{{ $barColor }}"></span></div>
                    @if($enough)
                        <span class="mpos-ok" title="Хватает"><i class="bi bi-check-lg"></i></span>
                    @else
                        <span class="mpos-bad" title="Не хватает {{ $fmt1($row['short']) }}">−{{ $fmt1($row['short']) }}</span>
                    @endif
                </div>
            @endif
        @endif
    </div>

    <div class="mpos-side">
        <div class="mpos-qty">
            <small class="mpos-label">заказ</small>
            <b class="{{ $qtySize }}">{{ $ordInt }}@if($ordFrac)<span class="frac">.{{ $ordFrac }}</span>@endif</b>
            <small>{{ $uom }}</small>
            @if($shipped)<span>отгр. {{ $shipped }}</span>@endif
        </div>
        @if($canMark || $canHide)
            <div class="mpos-actions">
                @if($canMark)
                    @include('orders.partials.ready-toggle', ['order' => $order, 'product' => $row['product']])
                @endif
                @include('orders.partials.hide-toggle', ['order' => $order, 'row' => $row, 'hideDepartments' => $hideDepartments])
            </div>
        @endif
    </div>
</div>
