@php
    $showActions   = $showActions   ?? false;
    $showEyeButton = $showEyeButton ?? false;
    $isMaster      = $isMaster      ?? false;

    $skuColor = \App\Models\Product::getColorBySku($reception->rawMaterialBatch?->product?->sku);
    $skuBg    = $skuColor === '#FFFFFF' ? '#fff' : $skuColor . '18';
@endphp
<div style="margin-bottom:.35rem;border-radius:.35rem;border:1px solid #dee2e6;border-left:4px solid {{ $skuColor }};border-right:4px solid {{ $skuColor }};background:{{ $skuBg }};box-shadow:0 1px 2px rgba(0,0,0,.07)">
    <div style="padding:.1rem .35rem">

        {{-- Строка 1: дата + ID слева, кнопки справа --}}
        <div class="d-flex justify-content-between align-items-center" style="margin-bottom:.2rem">
            <span class="text-muted" style="font-size:.72rem">
                {{ $reception->created_at->format('d.m.Y H:i') }}
                <span class="text-secondary ms-1">#{{ $reception->id }}</span>
            </span>
            <div class="d-flex gap-1 align-items-center">
                @if($showActions)
                    @if($reception->status == 'active')
                        <a href="{{ route('stone-receptions.edit', $reception) }}"
                           class="btn btn-success d-inline-flex align-items-center justify-content-center"
                           style="width:22px;height:22px;padding:0;font-size:.65rem" title="Редактировать">
                            <i class="bi bi-plus-lg"></i>
                        </a>
                    @endif
                    @if($reception->status == 'active')
                        <form action="{{ route('stone-receptions.mark-completed', $reception) }}"
                              method="POST" class="d-inline-flex"
                              onsubmit="return confirm('Завершить приёмку?')">
                            @csrf
                            @method('PATCH')
                            <button type="submit"
                                    class="btn btn-warning d-inline-flex align-items-center justify-content-center"
                                    style="width:22px;height:22px;padding:0;font-size:.65rem" title="Завершить приёмку">
                                <i class="bi bi-check2-circle"></i>
                            </button>
                        </form>
                    @endif
                    <form action="{{ route('stone-receptions.copy', $reception) }}"
                          method="POST" class="d-inline-flex">
                        @csrf
                        <button type="submit"
                                class="btn btn-outline-info d-inline-flex align-items-center justify-content-center"
                                style="width:22px;height:22px;padding:0;font-size:.65rem" title="Копировать">
                            <i class="bi bi-copy"></i>
                        </button>
                    </form>
                @endif
                @if($showActions || $showEyeButton)
                    <a href="{{ route('stone-receptions.show', $reception) }}"
                       class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center"
                       style="width:22px;height:22px;padding:0;font-size:.65rem" title="Просмотр">
                        <i class="bi bi-eye"></i>
                    </a>
                @endif
                @if($showActions && $reception->status != 'active')
                    <form action="{{ route('stone-receptions.reset-status', $reception) }}"
                          method="POST" class="d-inline-flex"
                          onsubmit="return confirm('Сбросить статус на Активна?')">
                        @csrf
                        @method('PATCH')
                        <button type="submit"
                                class="btn btn-outline-warning d-inline-flex align-items-center justify-content-center"
                                style="width:22px;height:22px;padding:0;font-size:.65rem" title="Сбросить статус">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </button>
                    </form>
                @endif
                @if($showActions && $reception->status == 'active')
                    <form action="{{ route('stone-receptions.destroy', $reception) }}"
                          method="POST" class="d-inline-flex"
                          onsubmit="return confirm('Удалить приёмку?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                class="btn btn-outline-danger d-inline-flex align-items-center justify-content-center"
                                style="width:22px;height:22px;padding:0;font-size:.65rem" title="Удалить">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                @endif
            </div>
        </div>

        {{-- Строка 2: пильщик слева, статус справа --}}
        <div class="d-flex justify-content-between align-items-center" style="margin-bottom:.2rem">
            <span class="fw-semibold small">
                <i class="bi bi-hammer text-secondary me-1"></i>{{ $reception->cutter->name ?? '—' }}
            </span>
            <div class="d-flex gap-1 align-items-center">
                @if($showActions && $reception->moysklad_sync_status && !$reception->isSynced())
                    <span class="badge {{ $reception->syncStatusBadgeClass() }}" style="font-size:.65rem">{{ $reception->syncStatusLabel() }}</span>
                @endif
                @if($reception->status == 'active')
                    <span class="badge bg-success" style="font-size:.65rem">Активна</span>
                @elseif($reception->status == 'completed')
                    <span class="badge bg-warning text-dark" style="font-size:.65rem">Завершена</span>
                @elseif($reception->status == 'processed')
                    <span class="badge bg-secondary" style="font-size:.65rem">Обработана</span>
                @elseif($reception->status == 'error')
                    <span class="badge bg-danger" style="font-size:.65rem">Ошибка</span>
                @endif
            </div>
        </div>

        {{-- Блок: продукция --}}
        @if($reception->items->count() > 0)
            <div style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem;margin-bottom:.2rem">
                @foreach($reception->items as $item)
                    <div class="d-flex justify-content-between align-items-baseline" style="{{ !$loop->last ? 'margin-bottom:.1rem' : '' }}">
                        <span class="text-truncate me-2" style="font-size:.72rem;max-width:80%">
                            @if($item->is_undercut)
                                <i class="bi bi-lightning-charge-fill me-1" style="color:#ffc107"></i>
                            @else
                                <i class="bi bi-grid-3x3 text-secondary me-1"></i>
                            @endif
                            {{ $item->product->name }}
                        </span>
                        <span class="fw-semibold text-primary text-nowrap" style="font-size:.72rem">
                            {{ number_format($item->quantity, 3, ',', '.') }} м²
                        </span>
                    </div>
                @endforeach
                <div class="d-flex justify-content-end" style="margin-top:.15rem">
                    <span class="fw-semibold text-nowrap" style="font-size:.72rem">
                        Итого: {{ number_format($reception->total_quantity, 3, ',', '.') }} м²
                    </span>
                </div>
            </div>
        @endif

        {{-- Блок: сырьё --}}
        @if($reception->rawMaterialBatch)
            @php
                $bInit = (float) ($reception->rawMaterialBatch->initial_quantity ?? 0);
                $bRem  = (float) ($reception->rawMaterialBatch->remaining_quantity ?? 0);
            @endphp
            <div class="d-flex justify-content-between align-items-center" style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem;margin-bottom:.2rem">
                <span class="text-muted text-truncate me-2" style="font-size:.72rem">
                    <i class="bi bi-box me-1"></i>
                    <a href="{{ route('raw-batches.show', $reception->rawMaterialBatch) }}" class="text-muted">
                        {{ $reception->rawMaterialBatch->product->name ?? '?' }}
                    </a>
                </span>
                <div class="d-flex gap-1 flex-shrink-0">
                    <span title="Всего в партии" style="font-size:.65rem;padding:1px 4px;border-radius:3px;background:#e9ecef;color:#495057;white-space:nowrap">{{ number_format($bInit, 3, '.', '') }}</span>
                    <span title="Доступно в партии" style="font-size:.65rem;padding:1px 4px;border-radius:3px;background:{{ $bRem > 0 ? '#cff4fc' : '#fff3cd' }};color:{{ $bRem > 0 ? '#055160' : '#664d03' }};white-space:nowrap">{{ number_format($bRem, 3, '.', '') }}</span>
                </div>
            </div>
        @endif

        {{-- Последняя строка: склад слева, приёмщик справа --}}
        @if($reception->store || $reception->receiver)
            <div class="d-flex justify-content-between" style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem">
                <span class="text-muted" style="font-size:.65rem">
                    <i class="bi bi-building me-1"></i>{{ $reception->store?->name ?? '—' }}
                </span>
                @if($reception->receiver)
                    <span class="text-muted" style="font-size:.65rem">
                        <i class="bi bi-person-gear me-1"></i>{{ $reception->receiver->name }}
                    </span>
                @endif
            </div>
        @endif

    </div>
</div>
