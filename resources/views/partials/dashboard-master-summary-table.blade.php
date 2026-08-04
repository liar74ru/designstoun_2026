{{-- Сводка по продуктам для мастера: $rows, $totalQuantity, $totalMasterPay --}}
<table class="table mb-0" style="font-size:.75rem;table-layout:auto">
    <colgroup>
        <col>
        <col style="width:1%">
        <col style="width:1%">
        <col style="width:1%">
    </colgroup>
    <thead class="table-light">
        <tr>
            <th style="border-left:4px solid transparent;padding:.3rem .1rem .3rem .4rem">Плитка</th>
            <th class="text-end text-nowrap" style="padding:.3rem .25rem .3rem .1rem">м²</th>
            <th class="text-end text-nowrap" style="padding:.3rem .25rem">Ставка</th>
            <th class="text-end text-nowrap" style="border-right:4px solid transparent;padding:.3rem .4rem .3rem .25rem">Сумма</th>
        </tr>
    </thead>
    <tbody>
    @foreach($rows as $row)
        @php
            $skuColor = \App\Models\Product::getColorBySku($row['product']?->sku);
            $skuBg    = $skuColor === '#FFFFFF' ? '' : 'background:' . $skuColor . '18;';
        @endphp
        <tr>
            <td style="border-left:4px solid {{ $skuColor }};{{ $skuBg }};word-break:break-word;padding:.3rem .1rem .3rem .4rem">
                {{ $row['product']?->name ?? '—' }}
                @if(!empty($row['is_undercut']))
                    <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem">подкол 80%</span>
                @endif
                @if(!empty($row['is_edging']))
                    <span class="badge bg-info text-dark ms-1" style="font-size:.6rem">торцовка</span>
                @endif
                @if(!empty($row['is_small_tile']))
                    <span class="badge bg-info text-dark ms-1" style="font-size:.6rem">< 50мм</span>
                @endif
            </td>
            <td class="text-end text-nowrap" style="{{ $skuBg }};padding:.3rem .25rem .3rem .1rem">
                {{ number_format($row['quantity'], 3, ',', ' ') }}
            </td>
            <td class="text-end text-nowrap text-muted" style="{{ $skuBg }};padding:.3rem .25rem">
                {{ number_format($row['masterCost'], 0, ',', ' ') }} ₽
            </td>
            <td class="text-end text-nowrap fw-semibold text-success" style="border-right:4px solid {{ $skuColor }};{{ $skuBg }};padding:.3rem .4rem .3rem .25rem">
                {{ number_format($row['masterPay'], 0, ',', ' ') }} ₽
            </td>
        </tr>
    @endforeach
    </tbody>
    <tfoot class="table-light">
        <tr>
            <th class="fw-bold" style="padding:.3rem .1rem .3rem .4rem">ИТОГО:</th>
            <th class="text-end text-nowrap fw-semibold" style="font-size:.9rem;padding:.3rem .25rem">
                {{ number_format($totalQuantity, 3, ',', ' ') }}
            </th>
            <th></th>
            <th class="text-end text-nowrap text-success" style="font-size:.9rem;padding:.3rem .4rem .3rem .25rem">
                {{ number_format($totalMasterPay, 0, ',', ' ') }} ₽
            </th>
        </tr>
    </tfoot>
</table>
