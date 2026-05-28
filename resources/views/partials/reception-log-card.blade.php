@php
    $showActions     = $showActions     ?? false;
    $showRawDetails  = $showRawDetails  ?? false;
    $showStoreBottom = $showStoreBottom ?? false;
    $isMaster        = $isMaster        ?? false;

    $skuColor = \App\Models\Product::getColorBySku($log->rawMaterialBatch?->product?->sku);
    $skuBg    = $skuColor === '#FFFFFF' ? '#fff' : $skuColor . '18';
@endphp
<div style="position:relative;margin-bottom:.35rem;border-radius:.35rem;border:1px solid #dee2e6;border-left:14px solid {{ $skuColor }};border-right:4px solid {{ $skuColor }};background:{{ $skuBg }};box-shadow:0 1px 2px rgba(0,0,0,.07)">
    <span style="position:absolute;left:-14px;top:50%;transform:translateY(-50%) rotate(180deg);writing-mode:vertical-rl;width:14px;font-size:.5rem;font-weight:700;letter-spacing:.1em;color:#fff;text-transform:uppercase;pointer-events:none;text-align:center;text-shadow:0 1px 2px rgba(0,0,0,.6)">Приёмка</span>
    <div style="padding:.1rem .35rem">

        {{-- Строка 1: дата слева, кнопки или бейдж справа --}}
        <div class="d-flex justify-content-between align-items-center" style="margin-bottom:.2rem">
            <span class="text-muted" style="font-size:.72rem">
                {{ $log->created_at->format('d.m.Y H:i') }}
                @if($showActions)
                    <span class="text-secondary ms-1">#{{ $log->id }}</span>
                @endif
            </span>
            @if($showActions)
                <div class="d-flex gap-1 align-items-center">
                    <a href="{{ route('stone-receptions.show', $log->stoneReception) }}"
                       class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center"
                       style="width:22px;height:22px;padding:0;font-size:.65rem" title="Просмотр">
                        <i class="bi bi-eye"></i>
                    </a>
                    @if($log->stoneReception && $log->stoneReception->status === 'active')
                        <a href="{{ route('stone-receptions.edit', $log->stone_reception_id) }}"
                           class="btn btn-success d-inline-flex align-items-center justify-content-center"
                           style="width:22px;height:22px;padding:0;font-size:.65rem"
                           title="Редактировать">
                            <i class="bi bi-plus-lg"></i>
                        </a>
                    @endif
                    @if($log->stoneReception)
                        <form action="{{ route('stone-receptions.copy', $log->stone_reception_id) }}"
                              method="POST" class="d-inline-flex">
                            @csrf
                            <button type="submit"
                                    class="btn btn-outline-info d-inline-flex align-items-center justify-content-center"
                                    style="width:22px;height:22px;padding:0;font-size:.65rem"
                                    title="Копировать">
                                <i class="bi bi-copy"></i>
                            </button>
                        </form>
                    @endif
                </div>
            @else
                @if($log->type === 'created')
                    <span class="badge bg-success" style="font-size:.65rem">Создание</span>
                @else
                    <span class="badge bg-warning text-dark" style="font-size:.65rem">Правка</span>
                @endif
            @endif
        </div>

        {{-- Строка 2: пильщик + бейдж типа (только если showActions) --}}
        @if($showActions)
            <div class="d-flex justify-content-between align-items-center" style="margin-bottom:.2rem">
                <span class="fw-semibold small">
                    <i class="bi bi-hammer text-secondary me-1"></i>{{ $log->cutter->name ?? '—' }}
                </span>
                @if($log->type === 'created')
                    <span class="badge bg-success" style="font-size:.65rem">Создание</span>
                @else
                    <span class="badge bg-warning text-dark" style="font-size:.65rem">Правка</span>
                @endif
            </div>
        @endif

        {{-- Блок: плитка --}}
        @if($log->items->count() > 0)
            <div style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem;margin-bottom:.2rem">
                @foreach($log->items as $item)
                    @php
                        $delta = (float) $item->quantity_delta;
                        $receptionItem = $log->stoneReception?->items->firstWhere('product_id', $item->product_id);
                        $isUndercut = $receptionItem?->is_undercut ?? false;
                        $isEdging   = $receptionItem?->is_edging ?? false;
                    @endphp
                    <div class="d-flex justify-content-between align-items-baseline" style="{{ !$loop->last ? 'margin-bottom:.1rem' : '' }}">
                        <span class="text-truncate me-2" style="font-size:.72rem;max-width:80%">
                            @if($isUndercut)
                                <i class="bi bi-lightning-charge-fill me-1" style="color:#ffc107"></i>
                            @elseif($isEdging)
                                <i class="bi bi-scissors me-1" style="color:#0dcaf0"></i>
                            @else
                                <ion-icon name="{{ \App\Models\Product::getIconBySku($item->product?->sku) }}" class="text-secondary me-1"></ion-icon>
                            @endif
                            {{ $item->product?->name ?? '?' }}
                        </span>
                        <span class="fw-semibold {{ $delta >= 0 ? 'text-success' : 'text-danger' }} text-nowrap" style="font-size:.72rem">
                            {{ $delta >= 0 ? '+' : '' }}{{ number_format($delta, 3, ',', '.') }} м²
                        </span>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Блок: партия сырья --}}
        @if($log->rawMaterialBatch)
            @if($showRawDetails)
                @php
                    $rawDelta       = (float) $log->raw_quantity_delta;
                    $snapshot       = $log->raw_quantity_snapshot !== null ? (float) $log->raw_quantity_snapshot : null;
                    $batchRemaining = (float) ($log->rawMaterialBatch->remaining_quantity ?? 0);
                    $deltaDisplay   = $rawDelta != 0 ? ($rawDelta > 0 ? '-' : '+') . number_format(abs($rawDelta), 3, '.', '') : '0.000';
                    $deltaIsConsume = $rawDelta > 0;
                @endphp
                <div class="d-flex justify-content-between align-items-center" style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem;margin-bottom:.2rem">
                    <span class="text-muted text-truncate me-2" style="font-size:.72rem">
                        <ion-icon name="{{ \App\Models\Product::getIconBySku($log->rawMaterialBatch->product?->sku) }}" class="me-1"></ion-icon>
                        <a href="{{ route('raw-batches.show', $log->rawMaterialBatch) }}" class="text-muted">
                            {{ $log->rawMaterialBatch->product->name ?? '?' }}
                        </a>
                    </span>
                    <div class="d-flex gap-1 flex-shrink-0">
                        <span title="Было в партии на момент приёмки" style="font-size:.65rem;padding:1px 4px;border-radius:3px;background:#e9ecef;color:#495057;white-space:nowrap">{{ $snapshot !== null ? number_format($snapshot, 3, '.', '') : '—' }}</span>
                        <span title="Изменение сырья в этой приёмке" style="font-size:.65rem;padding:1px 4px;border-radius:3px;background:{{ $deltaIsConsume ? '#f8d7da' : '#d1e7dd' }};color:{{ $deltaIsConsume ? '#842029' : '#0a3622' }};white-space:nowrap">{{ $deltaDisplay }}</span>
                        <span title="Текущий остаток в партии" style="font-size:.65rem;padding:1px 4px;border-radius:3px;background:{{ $batchRemaining > 0 ? '#cff4fc' : '#fff3cd' }};color:{{ $batchRemaining > 0 ? '#055160' : '#664d03' }};white-space:nowrap">{{ number_format($batchRemaining, 3, '.', '') }}</span>
                    </div>
                </div>
            @else
                <div style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem;margin-bottom:.2rem">
                    <span class="text-muted text-truncate" style="font-size:.72rem">
                        <ion-icon name="{{ \App\Models\Product::getIconBySku($log->rawMaterialBatch->product?->sku) }}" class="me-1"></ion-icon>{{ $log->rawMaterialBatch->product->name ?? '?' }}
                    </span>
                </div>
            @endif
        @endif

        {{-- Нижняя строка --}}
        @if($showStoreBottom)
            <div class="d-flex justify-content-between" style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem">
                <span class="text-muted" style="font-size:.65rem">
                    <i class="bi bi-building me-1"></i>{{ $log->stoneReception?->store?->name ?? '—' }}
                </span>
                @if($isMaster && $log->cutter)
                    <span class="text-muted" style="font-size:.65rem">
                        <i class="bi bi-person me-1"></i>{{ $log->cutter->name }}
                    </span>
                @elseif(!$isMaster && $log->receiver)
                    <span class="text-muted" style="font-size:.65rem">
                        <i class="bi bi-person-gear me-1"></i>{{ $log->receiver->name }}
                    </span>
                @endif
            </div>
        @elseif($log->receiver)
            <div class="d-flex justify-content-end" style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem">
                <span class="text-muted" style="font-size:.65rem">
                    <i class="bi bi-person-gear me-1"></i>{{ $log->receiver->name }}
                </span>
            </div>
        @endif

    </div>
</div>
