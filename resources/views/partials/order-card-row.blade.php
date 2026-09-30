{{--
    Строка позиции в мобильной карточке заявки (partials.order-card).
    Параметры: $row, $formula — формула «склад + изгот. = всего из заказа» под названием
    (видна в развёрнутой карточке);
    из карточки — $order, $fmt1, $num, $uomOf.
    Отметка «готово» — orders.partials.ready-toggle (стили и скрипт — ready-toggle-assets).
--}}
@php
    $product = $row['product'];
    $canMark = $product && ! $row['done'];
    $hasQty  = $row['totalQty'] !== null;
    $enough  = $hasQty && $row['short'] <= 0;
    $uom     = $uomOf($row);

    // Белый «цвет» — камень без цвета: вместо заливки светло-серый фон.
    $plain = $row['color'] === '#FFFFFF';

    // Формула «склад + изгот. = всего» — только у незакрытых позиций.
    $showFormula = $formula && ! $row['done'];
    $whText      = $row['warehouseQty'] !== null ? $fmt1($row['warehouseQty']) : '—';
    $prText      = $row['producedQty'] !== null ? $fmt1($row['producedQty']) : '—';
    $totalText   = $hasQty ? $fmt1($row['totalQty']) : '—';
    $totalClass  = ! $hasQty ? '' : ($enough ? 'is-ok' : 'is-bad');
    $orderedText = $num($row['ordered']) . ($uom ? ' ' . $uom : '');
    $lacks       = $hasQty && ! $enough;

@endphp
<div class="order-pos ocard-row {{ $showFormula ? 'has-formula' : '' }} {{ $row['done'] ? 'is-done' : '' }} {{ $row['isReady'] ? 'is-ready' : '' }} {{ ($row['hidden'] ?? false) ? 'is-hidden' : '' }}"
     style="--stone-bg:{{ $plain ? '#f8f9fa' : $row['color'] . '1f' }};--stone-soft:{{ $plain ? '#f8f9fa' : $row['color'] . '14' }}"
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
        @if($row['hiddenFor'])
            @include('orders.partials.hidden-badge', ['order' => $order, 'row' => $row])
        @endif
    </div>

    <span class="ocard-status">
        <span class="ocard-ready"><i class="bi bi-check-circle-fill"></i> Готово</span>
        <span class="ocard-state">
            @if($row['done'])
                <span class="ocard-muted">отгружено</span>
            @elseif(! $hasQty)
                <span class="ocard-muted">—</span>
            @elseif($enough)
                <i class="bi bi-check-lg ocard-ok" title="Хватает"></i>
            @else
                {{-- «заказ / не хватает»: заказ зелёным, нехватка красным --}}
                <span class="ocard-lack" title="Заказ {{ $num($row['ordered']) }}{{ $uom ? ' ' . $uom : '' }}, не хватает {{ $fmt1($row['short']) }}">
                    {{ $num($row['ordered']) }} <span class="sep">/</span> <b>{{ $fmt1($row['short']) }}</b>
                </span>
            @endif
        </span>
    </span>

    @if($showFormula)
        {{-- Формула отдельной строкой под названием: коробка — склад, молоток — изготовлено,
             итог — цветная пилюля, «из заказа»; нехватка или ✓ прижаты к правому краю. --}}
        <div class="ocard-f">
            <span class="ocard-fb-term" title="На складе"><i class="bi bi-box-seam"></i><b>{{ $whText }}</b></span>
            <span class="ocard-f-op">+</span>
            <span class="ocard-fb-term" title="Изготовлено"><i class="bi bi-hammer"></i><b>{{ $prText }}</b></span>
            <span class="ocard-f-op">=</span>
            <b class="ocard-fb-total {{ $totalClass }}" title="Всего">{{ $totalText }}</b>
            <span class="ocard-fb-of">из <b>{{ $orderedText }}</b></span>
            @if($lacks)
                <span class="ocard-fb-lack" title="Не хватает {{ $fmt1($row['short']) }}"><i class="bi bi-exclamation-triangle-fill"></i>{{ $fmt1($row['short']) }}</span>
            @elseif($enough)
                <span class="ocard-fb-fine" title="Хватает"><i class="bi bi-check-circle-fill"></i></span>
            @endif
        </div>
    @endif
</div>
