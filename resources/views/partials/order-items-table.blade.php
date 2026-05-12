@php
    $productionStoreId = $productionStoreId ?? null;
    $fmt1 = fn($v) => number_format((float) $v, 1, '.', '');
    $fmtQty = fn($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
@endphp
<div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead>
            <tr class="text-muted" style="font-size:.7rem">
                <th class="fw-normal pe-1">Позиция</th>
                <th class="fw-normal text-end ps-1 pe-2" style="width:1%; white-space:nowrap">Заказ</th>
                <th class="fw-normal text-end px-2" style="width:1%; white-space:nowrap">Отгр.</th>
                <th class="fw-normal text-end px-2" style="width:1%; white-space:nowrap">Склад</th>
                <th class="fw-normal text-end ps-2" style="width:1%; white-space:nowrap">Всего</th>
            </tr>
        </thead>
        <tbody>
        @foreach($order->items as $item)
            @php
                $product   = $item->product;
                $skuColor  = \App\Models\Product::getColorBySku($product?->sku);
                $rowStyle  = $skuColor === '#FFFFFF' ? '' : '--bs-table-bg:' . $skuColor . '18;';

                $ordered = (float) $item->quantity;
                $shipped = (float) $item->shipped;
                $isFullyShipped = $ordered > 0 && $shipped >= $ordered;
                $isPartialShipped = $shipped > 0 && $shipped < $ordered;

                $hasProdStore = $productionStoreId && $product;
                $prodQty = null;
                if ($hasProdStore) {
                    $stock = $product->stocks->firstWhere('store_id', $productionStoreId);
                    $prodQty = $stock ? (float) $stock->quantity : 0.0;
                }
                $prodClass = '';
                if ($prodQty !== null && ! $isFullyShipped) {
                    $prodClass = $prodQty >= $ordered ? 'text-success' : 'text-danger';
                }

                $totalQty = $product
                    ? $product->stocks->filter(fn($s) => $s->store && ! $s->store->archived)->sum('quantity')
                    : null;
                $totalClass = '';
                if ($totalQty !== null && ! $isFullyShipped) {
                    $totalClass = $totalQty >= $ordered ? 'text-success' : 'text-danger';
                }

                $rowClass = $isFullyShipped ? 'text-decoration-line-through text-muted' : '';
            @endphp
            <tr class="{{ $rowClass }}" style="{{ $rowStyle }}">
                <td class="pe-1">
                    <div class="d-flex align-items-start gap-1">
                        <ion-icon name="{{ \App\Models\Product::getIconBySku($product?->sku) }}" class="text-muted flex-shrink-0 mt-1"></ion-icon>
                        <div class="min-w-0">
                            <div style="font-size:.78rem; line-height:1.25; word-break:break-word">
                                {{ $product?->name ?? $item->product_name ?? '—' }}
                            </div>
                        </div>
                    </div>
                </td>
                <td class="text-end ps-1 pe-2"
                    style="white-space:nowrap; font-size:.82rem; font-variant-numeric:tabular-nums">
                    {{ $fmtQty($item->quantity) }}{{ $item->uom_name ? ' ' . $item->uom_name : '' }}
                </td>
                <td class="text-end px-2"
                    style="white-space:nowrap; font-size:.82rem; font-variant-numeric:tabular-nums">
                    @if($isPartialShipped)
                        <span class="fw-semibold" style="color:#1d4ed8">{{ $fmt1($shipped) }}</span>
                    @elseif($isFullyShipped)
                        {{ $fmt1($shipped) }}
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
                <td class="text-end fw-semibold px-2 {{ $prodClass }}"
                    style="white-space:nowrap; font-size:.82rem; font-variant-numeric:tabular-nums">
                    {{ $prodQty !== null ? $fmt1($prodQty) : '—' }}
                </td>
                <td class="text-end fw-semibold ps-2 {{ $totalClass }}"
                    style="white-space:nowrap; font-size:.82rem; font-variant-numeric:tabular-nums">
                    {{ $totalQty !== null ? $fmt1($totalQty) : '—' }}
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
