{{-- Мобильная карточка партии сырья без приёмок (раздел «По партиям») --}}
@php
    $skuColor = \App\Models\Product::getColorBySku($batch->product?->sku);
    $skuBg    = $skuColor === '#FFFFFF' ? '#fff' : $skuColor . '18';
    $bInit    = (float) ($batch->initial_quantity ?? 0);
    $bRem     = (float) ($batch->remaining_quantity ?? 0);
@endphp
<div style="margin-bottom:.35rem;border-radius:.35rem;border:1px solid #dee2e6;border-left:4px solid {{ $skuColor }};border-right:4px solid {{ $skuColor }};background:{{ $skuBg }};box-shadow:0 1px 2px rgba(0,0,0,.07)">
    <div style="padding:.1rem .35rem">

        {{-- Строка 1: дата + № слева, кнопки справа --}}
        <div class="d-flex justify-content-between align-items-center" style="margin-bottom:.2rem">
            <span class="text-muted" style="font-size:.72rem">
                {{ $batch->created_at->format('d.m.Y H:i') }}
                @if($batch->batch_number)
                    <span class="text-secondary ms-1">№{{ $batch->batch_number }}</span>
                @endif
            </span>
            <div class="d-flex gap-1 align-items-center">
                <a href="{{ route('stone-receptions.create', ['cutter_id' => $batch->current_worker_id, 'raw_material_batch_id' => $batch->id]) }}"
                   class="btn btn-success d-inline-flex align-items-center justify-content-center"
                   style="width:22px;height:22px;padding:0;font-size:.65rem" title="Оформить приёмку">
                    <i class="bi bi-plus-lg"></i>
                </a>
                <a href="{{ route('raw-batches.show', $batch) }}"
                   class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center"
                   style="width:22px;height:22px;padding:0;font-size:.65rem" title="Просмотр партии">
                    <i class="bi bi-eye"></i>
                </a>
            </div>
        </div>

        {{-- Строка 2: работник слева, статус справа --}}
        <div class="d-flex justify-content-between align-items-center" style="margin-bottom:.2rem">
            <span class="fw-semibold small">
                <i class="bi bi-hammer text-secondary me-1"></i>{{ $batch->currentWorker->name ?? '—' }}
            </span>
            <span class="badge {{ $batch->statusBadgeClass() }}" style="font-size:.65rem">{{ $batch->statusLabel() }}</span>
        </div>

        {{-- Блок: продукция — приёмок нет --}}
        <div style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem;margin-bottom:.2rem">
            <span class="text-muted fst-italic" style="font-size:.72rem">приёмок нет</span>
        </div>

        {{-- Блок: сырьё --}}
        <div class="d-flex justify-content-between align-items-center" style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem;margin-bottom:.2rem">
            <span class="text-muted text-truncate me-2" style="font-size:.72rem">
                <ion-icon name="{{ \App\Models\Product::getIconBySku($batch->product?->sku) }}" class="me-1" style="vertical-align:-2px"></ion-icon>
                <a href="{{ route('raw-batches.show', $batch) }}" class="text-muted">
                    {{ $batch->product->name ?? '?' }}
                </a>
            </span>
            <div class="d-flex gap-1 flex-shrink-0">
                <span title="Всего в партии" style="font-size:.65rem;padding:1px 4px;border-radius:3px;background:#e9ecef;color:#495057;white-space:nowrap">{{ number_format($bInit, 3, '.', '') }}</span>
                <span title="Доступно в партии" style="font-size:.65rem;padding:1px 4px;border-radius:3px;background:{{ $bRem > 0 ? '#cff4fc' : '#fff3cd' }};color:{{ $bRem > 0 ? '#055160' : '#664d03' }};white-space:nowrap">{{ number_format($bRem, 3, '.', '') }}</span>
            </div>
        </div>

        {{-- Последняя строка: склад --}}
        @if($batch->currentStore)
            <div class="d-flex justify-content-between" style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem">
                <span class="text-muted" style="font-size:.65rem">
                    <i class="bi bi-building me-1"></i>{{ $batch->currentStore->name }}
                </span>
            </div>
        @endif

    </div>
</div>
