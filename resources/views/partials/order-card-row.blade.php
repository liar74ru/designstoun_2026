{{--
    Строка позиции в мобильной карточке заявки (partials.order-card).
    Параметры: $row, $formula — строка «склад + изгот. = всего из заказа» под названием;
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
@endphp
<div class="order-pos ocard-row {{ $row['done'] ? 'is-done' : '' }} {{ $row['isReady'] ? 'is-ready' : '' }} {{ ($row['hidden'] ?? false) ? 'is-hidden' : '' }}"
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
        @if($formula && ! $row['done'])
            <div class="ocard-sub">
                склад {{ $row['warehouseQty'] !== null ? $fmt1($row['warehouseQty']) : '—' }}
                + изгот. {{ $row['producedQty'] !== null ? $fmt1($row['producedQty']) : '—' }}
                = <b>{{ $hasQty ? $fmt1($row['totalQty']) : '—' }}</b> из {{ $num($row['ordered']) }}{{ $uom ? ' ' . $uom : '' }}
            </div>
        @endif
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
                <span class="ocard-bad" title="Не хватает {{ $fmt1($row['short']) }}">−{{ $fmt1($row['short']) }}</span>
            @endif
        </span>
    </span>
</div>
