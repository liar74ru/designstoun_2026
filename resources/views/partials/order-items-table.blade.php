{{--
    Компактная таблица позиций для списка заявок и мобильной карточки.
    Числа приходят готовыми из OrderPositionService — своей арифметики здесь нет.
    Параметры: $rows, $order (для ручной отметки «готово»),
    $hiddenCount — сколько позиций скрыто для отделов смотрящего (OrderService::getIndexData).

    Ручная отметка: строка получает .is-ready (стили и скрипт — orders.partials.ready-toggle-assets).
    Скрытая позиция приходит в $rows только при «Показывать скрытые позиции» и получает
    .is-hidden (стили — orders.partials.hide-toggle-assets).
--}}
@php
    $fmt1   = fn ($v) => number_format((float) $v, 1, '.', '');
    $fmtQty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');

    // Скрытые, но не показанные — о них напоминает строка под таблицей.
    $hiddenLeft = ($hiddenCount ?? 0) - $rows->where('hidden', true)->count();
@endphp
@if($rows->isEmpty() && $hiddenLeft > 0)
    <div class="text-muted small px-2 py-1">
        <i class="bi bi-eye-slash"></i> Все позиции скрыты для вашего отдела ({{ $hiddenLeft }})
    </div>
@else
<div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead>
            <tr class="text-muted" style="font-size:.7rem">
                <th class="fw-normal pe-1">Позиция</th>
                <th class="fw-normal text-end ps-1 pe-2" style="width:1%; white-space:nowrap">Заказ</th>
                <th class="fw-normal text-end px-2" style="width:1%; white-space:nowrap">Отгр.</th>
                <th class="fw-normal text-end px-2" style="width:1%; white-space:nowrap">Сделано</th>
                <th class="fw-normal text-end ps-2" style="width:1%; white-space:nowrap">Всего</th>
            </tr>
        </thead>
        <tbody>
        @foreach($rows as $row)
            @php
                $product  = $row['product'];
                $rowStyle = $row['color'] === '#FFFFFF' ? '' : '--bs-table-bg:' . $row['color'] . '18;';

                $totalClass = '';
                if ($row['totalQty'] !== null && ! $row['done']) {
                    $totalClass = $row['totalQty'] >= $row['left'] ? 'text-success' : 'text-danger';
                }
            @endphp
            @php
                $canMark = $product && ! $row['done'] && isset($order);
            @endphp
            <tr class="order-pos {{ $row['done'] ? 'text-decoration-line-through text-muted' : '' }} {{ $row['isReady'] ? 'is-ready' : '' }} {{ ($row['hidden'] ?? false) ? 'is-hidden' : '' }}"
                style="{{ $rowStyle }}"
                @if($canMark) data-position="{{ $order->id }}-{{ $product->id }}" @endif>
                <td class="pe-1">
                    <div class="d-flex align-items-start gap-1">
                        @if($canMark)
                            @include('orders.partials.ready-toggle', ['order' => $order, 'product' => $product])
                        @endif
                        <ion-icon name="{{ $row['icon'] }}" class="text-muted flex-shrink-0 mt-1"></ion-icon>
                        <div class="min-w-0">
                            <div style="font-size:.78rem; line-height:1.25; word-break:break-word">
                                @if($product)
                                    @can('see-products')
                                        <a href="{{ route('products.show', $product->moysklad_id) }}"
                                           class="text-reset text-decoration-none">{{ $row['name'] }}</a>
                                    @else
                                        {{ $row['name'] }}
                                    @endcan
                                @else
                                    {{ $row['name'] }}
                                @endif
                            </div>
                            @if(isset($order) && $row['hiddenFor'])
                                @include('orders.partials.hidden-badge', ['order' => $order, 'row' => $row])
                            @endif
                        </div>
                    </div>
                </td>
                <td class="text-end ps-1 pe-2"
                    style="white-space:nowrap; font-size:.82rem; font-variant-numeric:tabular-nums">
                    {{ $fmtQty($row['ordered']) }}{{ $row['item']->uom_name ? ' ' . $row['item']->uom_name : '' }}
                </td>
                <td class="text-end px-2"
                    style="white-space:nowrap; font-size:.82rem; font-variant-numeric:tabular-nums">
                    @if($row['partial'])
                        <span class="fw-semibold" style="color:#1d4ed8">{{ $fmt1($row['shipped']) }}</span>
                    @elseif($row['done'])
                        {{ $fmt1($row['shipped']) }}
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
                <td class="text-end px-2"
                    style="white-space:nowrap; font-size:.82rem; font-variant-numeric:tabular-nums">
                    {{ $row['producedQty'] !== null ? $fmt1($row['producedQty']) : '—' }}
                </td>
                <td class="text-end fw-semibold ps-2 {{ $totalClass }}"
                    style="white-space:nowrap; font-size:.82rem; font-variant-numeric:tabular-nums">
                    <span class="total-value">{{ $row['totalQty'] !== null ? $fmt1($row['totalQty']) : '—' }}</span>
                    <span class="badge bg-success ready-badge">✓ готово</span>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@if($hiddenLeft > 0 && isset($order))
    <div class="small px-2 py-1">
        <a href="{{ route('orders.show', $order->uuid) }}" class="text-muted text-decoration-none">
            <i class="bi bi-eye-slash"></i> + {{ $hiddenLeft }} скрыто
        </a>
    </div>
@endif
@endif
