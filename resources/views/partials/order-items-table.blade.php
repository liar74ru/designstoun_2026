{{--
    Компактная таблица позиций для списка заявок и мобильной карточки.
    Числа приходят готовыми из OrderPositionService — своей арифметики здесь нет.
    Параметры: $rows.
--}}
@php
    $fmt1   = fn ($v) => number_format((float) $v, 1, '.', '');
    $fmtQty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
@endphp
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
            <tr class="{{ $row['done'] ? 'text-decoration-line-through text-muted' : '' }}" style="{{ $rowStyle }}">
                <td class="pe-1">
                    <div class="d-flex align-items-start gap-1">
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
                    {{ $row['totalQty'] !== null ? $fmt1($row['totalQty']) : '—' }}
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
