{{-- Сводка по продуктам для мастера: $rows, $totalQuantity, $totalMasterPay, $showPay (без денег — только м²) --}}
@php $showPay = $showPay ?? true; @endphp
<table class="table mb-0" style="font-size:.75rem;table-layout:auto">
    <colgroup>
        <col>
        <col style="width:1%">
        @if($showPay)
            <col style="width:1%">
            <col style="width:1%">
        @endif
    </colgroup>
    <thead class="table-light">
        <tr>
            <th style="border-left:4px solid transparent;padding:.3rem .1rem .3rem .4rem">Плитка</th>
            @if($showPay)
                <th class="text-end text-nowrap" style="padding:.3rem .25rem .3rem .1rem">м²</th>
                <th class="text-end text-nowrap" style="padding:.3rem .25rem">Ставка</th>
                <th class="text-end text-nowrap" style="border-right:4px solid transparent;padding:.3rem .4rem .3rem .25rem">Сумма</th>
            @else
                <th class="text-end text-nowrap" style="border-right:4px solid transparent;padding:.3rem .4rem .3rem .1rem">м²</th>
            @endif
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
                @include('partials.modifier-badges', [
                    'modifiers' => $row['modifiers'] ?? [],
                    'class'     => 'ms-1',
                    'font'      => '.6rem',
                ])
            </td>
            @if($showPay)
                <td class="text-end text-nowrap" style="{{ $skuBg }};padding:.3rem .25rem .3rem .1rem">
                    {{ number_format($row['quantity'], 3, ',', ' ') }}
                </td>
                <td class="text-end text-nowrap text-muted" style="{{ $skuBg }};padding:.3rem .25rem">
                    {{ number_format($row['masterCost'], 0, ',', ' ') }} ₽
                </td>
                <td class="text-end text-nowrap fw-semibold text-success" style="border-right:4px solid {{ $skuColor }};{{ $skuBg }};padding:.3rem .4rem .3rem .25rem">
                    {{ number_format($row['masterPay'], 0, ',', ' ') }} ₽
                </td>
            @else
                <td class="text-end text-nowrap" style="border-right:4px solid {{ $skuColor }};{{ $skuBg }};padding:.3rem .4rem .3rem .1rem">
                    {{ number_format($row['quantity'], 3, ',', ' ') }}
                </td>
            @endif
        </tr>
    @endforeach
    </tbody>
    <tfoot class="table-light">
        <tr>
            <th class="fw-bold" style="padding:.3rem .1rem .3rem .4rem">ИТОГО:</th>
            <th class="text-end text-nowrap fw-semibold" style="font-size:.9rem;padding:.3rem .25rem">
                {{ number_format($totalQuantity, 3, ',', ' ') }}
            </th>
            @if($showPay)
                <th></th>
                <th class="text-end text-nowrap text-success" style="font-size:.9rem;padding:.3rem .4rem .3rem .25rem">
                    {{ number_format($totalMasterPay, 0, ',', ' ') }} ₽
                </th>
            @endif
        </tr>
    </tfoot>
</table>
