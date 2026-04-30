@php
    $skuColor = \App\Models\Product::getColorBySku($batch->product?->sku);
    $skuBg    = $skuColor === '#FFFFFF' ? '#fff' : $skuColor . '18';
    $bInit    = (float) ($batch->initial_quantity ?? 0);
    $bRem     = (float) ($batch->remaining_quantity ?? 0);
    $isActive = in_array($batch->status, ['new', 'in_work']);
@endphp
<div style="margin-bottom:.35rem;border-radius:.35rem;border:1px solid #dee2e6;border-left:4px solid {{ $skuColor }};border-right:4px solid {{ $skuColor }};background:{{ $skuBg }};box-shadow:0 1px 2px rgba(0,0,0,.07);{{ !$isActive ? 'opacity:.75' : '' }}">
    <div style="padding:.1rem .35rem">

        {{-- Строка 1: дата слева, кнопки справа --}}
        <div class="d-flex justify-content-between align-items-center" style="margin-bottom:.2rem">
            <span class="text-muted" style="font-size:.72rem">
                {{ $batch->created_at->format('d.m.Y H:i') }}
            </span>
            <div class="d-flex gap-1 align-items-center">
                <a href="{{ route('raw-batches.show', $batch) }}"
                   class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center"
                   style="width:22px;height:22px;padding:0;font-size:.65rem" title="Посмотреть партию">
                    <i class="bi bi-eye"></i>
                </a>
                @if(auth()->user()->isAdmin() || auth()->user()->isMaster())
                    @if($isActive)
                        <a href="{{ route('raw-batches.adjust.form', $batch) }}"
                           class="btn btn-outline-success d-inline-flex align-items-center justify-content-center"
                           style="width:22px;height:22px;padding:0;font-size:.65rem" title="Изменить количество">
                            <i class="bi bi-plus-slash-minus"></i>
                        </a>
                    @endif
                    @if($isActive && $bRem <= 0)
                        <form method="POST" action="{{ route('raw-batches.mark-used', $batch) }}" class="d-inline-flex"
                              onsubmit="return confirm('Завершить партию? Сырьё будет отмечено как израсходованное.')">
                            @csrf
                            <button type="submit"
                                    class="btn btn-warning d-inline-flex align-items-center justify-content-center"
                                    style="width:22px;height:22px;padding:0;font-size:.65rem" title="Завершить партию">
                                <i class="bi bi-check2-circle"></i>
                            </button>
                        </form>
                    @endif
                    @if($batch->status === \App\Models\RawMaterialBatch::STATUS_USED)
                        <form method="POST" action="{{ route('raw-batches.mark-in-work', $batch) }}" class="d-inline-flex"
                              onsubmit="return confirm('Вернуть партию в работу?')">
                            @csrf
                            <button type="submit"
                                    class="btn btn-outline-success d-inline-flex align-items-center justify-content-center"
                                    style="width:22px;height:22px;padding:0;font-size:.65rem" title="Вернуть в работу">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </button>
                        </form>
                    @endif
                @endif
            </div>
        </div>

        {{-- Строка 2: №партии слева, бейдж статуса справа --}}
        <div class="d-flex justify-content-between align-items-center" style="margin-bottom:.2rem">
            @if($batch->batch_number)
                <span class="text-muted" style="font-size:.72rem">№{{ $batch->batch_number }}</span>
            @else
                <span></span>
            @endif
            <span class="badge {{ $batch->statusBadgeClass() }}" style="font-size:.65rem">
                {{ $batch->statusLabel() }}
            </span>
        </div>

        {{-- Блок: товар слева, нач./ост. справа --}}
        <div class="d-flex justify-content-between align-items-center" style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem;margin-bottom:.2rem">
            <span class="fw-semibold text-truncate me-2" style="font-size:.75rem">
                <i class="bi bi-box text-secondary me-1"></i>{{ $batch->product?->name ?? '—' }}
            </span>
            <div class="d-flex gap-1 align-items-center flex-shrink-0">
                <span style="font-size:.6rem;color:#6c757d;white-space:nowrap">нач.</span>
                <span class="fw-bold" style="font-size:.65rem;padding:1px 4px;border-radius:3px;background:#e9ecef;color:#495057;white-space:nowrap">{{ number_format($bInit, 3, '.', '') }}</span>
                <span style="font-size:.6rem;color:#6c757d;white-space:nowrap">ост.</span>
                <span class="fw-bold" style="font-size:.65rem;padding:1px 4px;border-radius:3px;background:{{ $bRem > 0 ? '#cff4fc' : '#fff3cd' }};color:{{ $bRem > 0 ? '#055160' : '#664d03' }};white-space:nowrap">{{ number_format($bRem, 3, '.', '') }}</span>
            </div>
        </div>

        {{-- Нижняя строка: склад слева, создавший перемещение справа --}}
        @if($batch->currentStore || $batch->currentWorker)
            <div class="d-flex justify-content-between align-items-center" style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem">
                @if($batch->currentStore)
                    <span class="text-muted" style="font-size:.65rem">
                        <i class="bi bi-building me-1"></i>{{ $batch->currentStore->name }}
                    </span>
                @else
                    <span></span>
                @endif
                @if($batch->currentWorker)
                    <span class="text-muted" style="font-size:.65rem">
                        <i class="bi bi-person me-1"></i>{{ $batch->currentWorker->name }}
                    </span>
                @endif
            </div>
        @endif

    </div>
</div>
