@php
    $rawItems     = $workshop->items->where('role', \App\Models\WorkshopItem::ROLE_RAW);
    $packageItems = $workshop->items->where('role', \App\Models\WorkshopItem::ROLE_PACKAGE);
    $productItems = $workshop->items->where('role', \App\Models\WorkshopItem::ROLE_PRODUCT);
    $skuColor     = \App\Models\Product::getColorBySku($rawItems->first()?->product?->sku);
    $skuBg        = $skuColor === '#FFFFFF' ? '#fff' : $skuColor . '18';
    $uomOf        = fn ($product) => \App\Support\Uom::of($product);
@endphp
<div style="border-radius:.35rem;border:1px solid #dee2e6;border-left:4px solid {{ $skuColor }};border-right:4px solid {{ $skuColor }};background:{{ $skuBg }};box-shadow:0 1px 2px rgba(0,0,0,.07)">
    <div style="padding:.1rem .35rem">

        {{-- Строка 1: дата + ID слева, кнопки справа --}}
        <div class="d-flex justify-content-between align-items-center" style="margin-bottom:.2rem">
            <a href="{{ route('workshops.show', $workshop) }}" class="text-muted text-decoration-none" style="font-size:.72rem">
                {{ $workshop->created_at->format('d.m.Y H:i') }}
                <span class="text-secondary ms-1">#{{ $workshop->id }}</span>
            </a>
            @include('workshops.partials.actions', ['workshop' => $workshop])
        </div>

        {{-- Строка 2: работник слева, статус справа --}}
        <div class="d-flex justify-content-between align-items-center" style="margin-bottom:.2rem">
            <span class="text-muted text-truncate me-2" style="font-size:.72rem">
                <i class="bi bi-person me-1"></i>{{ $workshop->packer->name ?? '—' }}
            </span>
            @include('workshops.partials.status-badges', ['workshop' => $workshop])
        </div>

        {{-- Блок: сырьё + упаковка = продукт --}}
        <div style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem;margin-bottom:.2rem">
            @foreach($rawItems as $item)
                <div class="d-flex align-items-start text-secondary" style="font-size:.78rem;margin-bottom:.05rem">
                    <span class="text-center flex-shrink-0" style="width:.9rem">{{ $loop->first ? '' : '+' }}</span>
                    <ion-icon name="{{ \App\Models\Product::getIconBySku($item->product?->sku) }}" class="text-secondary me-1 flex-shrink-0" style="font-size:.78rem;margin-top:.1rem"></ion-icon>
                    <span class="flex-grow-1 me-2" style="min-width:0;line-height:1.2;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word">{{ $item->product->name }}</span>
                    <span class="text-nowrap">{{ number_format($item->quantity, 3, ',', '.') }} {{ $uomOf($item->product) }}</span>
                </div>
            @endforeach
            @foreach($packageItems as $item)
                <div class="d-flex align-items-start text-secondary" style="font-size:.78rem;margin-bottom:.05rem">
                    <span class="text-center flex-shrink-0" style="width:.9rem">+</span>
                    <i class="bi bi-box-seam me-1 flex-shrink-0" style="font-size:.78rem"></i>
                    <span class="flex-grow-1 text-truncate me-2">{{ $item->product->name }}</span>
                    <span class="text-nowrap">× {{ number_format($item->quantity, 0) }} {{ $uomOf($item->product) }}</span>
                </div>
            @endforeach
            <div style="border-top:1px dashed rgba(108,117,125,.45);margin:.15rem 0 .1rem .9rem"></div>
            @foreach($productItems as $item)
                <div class="d-flex align-items-start">
                    <span class="text-center fw-bold text-primary flex-shrink-0" style="width:.9rem;font-size:.85rem">=</span>
                    <span class="flex-grow-1 fw-bold text-dark me-2" style="min-width:0;font-size:.85rem;line-height:1.2;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word">{{ $item->product->name }}</span>
                    <span class="fw-bold text-primary text-nowrap" style="font-size:.85rem">{{ number_format($item->quantity, 3, ',', '.') }} <span class="fw-normal" style="font-size:.7rem">{{ $uomOf($item->product) }}</span></span>
                </div>
            @endforeach
        </div>

        {{-- Последняя строка: отдел слева, склад справа --}}
        <div class="d-flex justify-content-between" style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem">
            <span class="text-muted text-truncate me-2" style="font-size:.65rem">
                <i class="bi bi-diagram-3 me-1"></i>{{ $workshop->department?->name ?? '—' }}
            </span>
            <span class="text-muted text-truncate" style="font-size:.65rem">
                <i class="bi bi-building me-1"></i>{{ $workshop->store->name ?? '—' }}
            </span>
        </div>

    </div>
</div>
