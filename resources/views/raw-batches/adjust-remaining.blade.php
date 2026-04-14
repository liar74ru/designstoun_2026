@extends('layouts.app')
@section('title', 'Корректировка остатка')

@section('content')
<div class="container py-3" style="max-width:560px">

    <x-page-header
        title="📦 Корректировка остатка"
        mobileTitle="Корректировка"
        :backUrl="$backUrl"
        backLabel="Назад">
    </x-page-header>

    @include('partials.alerts')

    @php $fmt = fn($v) => rtrim(rtrim(number_format($v, 2), '0'), '.'); @endphp

    {{-- Информация о партии --}}
    @php
        $skuColor = \App\Models\Product::getColorBySku($batch->product?->sku ?? null);
        $skuBg    = $skuColor === '#FFFFFF' ? '' : 'background:' . $skuColor . '18;';
    @endphp
    <div class="info-block mb-3"
         style="border-left:4px solid {{ $skuColor }};border-right:4px solid {{ $skuColor }};{{ $skuBg }}">
        <div class="info-block-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold small text-dark">{{ $batch->batch_number ?? '#'.$batch->id }}</span>
            <span class="badge {{ $batch->statusBadgeClass() }}">{{ $batch->statusLabel() }}</span>
        </div>
        <div class="info-block-body">
            <div class="fw-semibold mb-1">{{ $batch->product->name ?? '—' }}</div>
            <div class="small text-muted mb-1">
                <i class="bi bi-building me-1"></i>{{ $batch->currentStore->name ?? '—' }}
            </div>
            @if($batch->currentWorker)
                <div class="small mb-1">
                    <i class="bi bi-person me-1 text-muted"></i>
                    <span class="fw-semibold">{{ $batch->currentWorker->name }}</span>
                </div>
            @endif
            <div class="small text-muted mb-2">
                <i class="bi bi-calendar me-1"></i>{{ $batch->created_at->format('d.m.Y') }}
            </div>
            <div class="d-flex flex-column gap-1">
                <div>
                    <span class="badge rounded-pill bg-secondary bg-opacity-25 text-dark">
                        нач.: {{ $fmt($batch->initial_quantity) }} м³
                    </span>
                </div>
                <div>
                    <span class="badge rounded-pill"
                          style="{{ $batch->remaining_quantity > 0 ? 'background:#d1e7dd;color:#0a3622' : 'background:#6c757d;color:#fff' }}">
                        остаток: {{ $fmt($batch->remaining_quantity) }} м³
                    </span>
                </div>
            </div>
        </div>
    </div>

    {{-- Пояснение --}}
    <div class="alert alert-secondary py-2 small mb-3">
        <i class="bi bi-info-circle me-1"></i>
        Корректировка меняет остаток в партии. Используйте для исправления ошибки в количестве сырья.
    </div>

    {{-- Блок корректировки --}}
    <div class="card shadow-sm">
        <div class="card-header bg-white py-2">
            <span class="fw-semibold small text-muted">Изменение остатка</span>
        </div>
        <div class="card-body">
            <style>
                #adjustRemainingForm .form-control { border-radius: .4rem; }
                .qty-display {
                    font-size: 1.4rem;
                    font-weight: 700;
                    padding: .4rem .75rem;
                    border-radius: .4rem;
                    background: #f8f9fa;
                    border: 1px solid #dee2e6;
                    text-align: center;
                    letter-spacing: .02em;
                }
            </style>
            <form method="POST" action="{{ route('raw-batches.adjust-remaining', $batch) }}" id="adjustRemainingForm">
                @csrf
                <input type="hidden" name="back_url" value="{{ $backUrl }}">

                <div class="mb-3">
                    <div class="text-muted small mb-1">Текущий остаток</div>
                    <div class="qty-display text-secondary">
                        {{ $fmt($batch->remaining_quantity) }} <span class="fs-6 fw-normal">м³</span>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="delta" class="form-label fw-semibold">
                        Изменение <span class="text-muted fw-normal small">(«−» чтобы убавить)</span>
                    </label>
                    <input type="number" name="delta" id="delta"
                           class="form-control form-control-lg text-center @error('delta') is-invalid @enderror"
                           step="0.001" placeholder="например: +1.5 или -0.5"
                           value="{{ old('delta') }}" autofocus>
                    @error('delta')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-4">
                    <div class="text-muted small mb-1">Итого будет</div>
                    <div class="qty-display" id="result">
                        {{ $fmt($batch->remaining_quantity) }} <span class="fs-6 fw-normal">м³</span>
                    </div>
                    <div id="result_hint" class="mt-2 small"></div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold">Примечание</label>
                    <input type="text" name="notes" class="form-control"
                           placeholder="Причина корректировки..." value="{{ old('notes') }}">
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="bi bi-check-lg"></i> Применить
                    </button>
                    <a href="{{ $backUrl }}" class="btn btn-outline-secondary text-nowrap">Отмена</a>
                </div>
            </form>
        </div>
    </div>

</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const current    = {{ (float)$batch->remaining_quantity }};
    const initial    = {{ (float)$batch->initial_quantity }};
    const deltaInput = document.getElementById('delta');
    const resultEl   = document.getElementById('result');
    const hint       = document.getElementById('result_hint');
    const submitBtn  = document.getElementById('submitBtn');

    function fmt(v) {
        return ('' + (Math.round(v * 1000) / 1000))
            .replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
    }

    deltaInput.addEventListener('input', function () {
        const delta  = parseFloat(this.value) || 0;
        const result = Math.round((current + delta) * 1000) / 1000;

        resultEl.innerHTML = fmt(result) + ' <span class="fs-6 fw-normal">м³</span>';

        if (result < 0) {
            resultEl.style.color       = '#842029';
            resultEl.style.background  = '#f8d7da';
            resultEl.style.borderColor = '#f5c2c7';
            hint.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Нельзя убрать больше чем есть в остатке</span>';
            submitBtn.disabled = true;
        } else if (result > initial) {
            resultEl.style.color       = '#842029';
            resultEl.style.background  = '#f8d7da';
            resultEl.style.borderColor = '#f5c2c7';
            hint.innerHTML = `<span class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Остаток не может превышать начальное количество (${fmt(initial)} м³)</span>`;
            submitBtn.disabled = true;
        } else if (delta > 0) {
            resultEl.style.color       = '#0a3622';
            resultEl.style.background  = '#d1e7dd';
            resultEl.style.borderColor = '#a3cfbb';
            hint.innerHTML = `<span class="text-success"><i class="bi bi-plus-circle me-1"></i>Добавляем ${fmt(Math.abs(delta))} м³ — остаток увеличится</span>`;
            submitBtn.disabled = false;
        } else if (delta < 0) {
            resultEl.style.color       = '#664d03';
            resultEl.style.background  = '#fff3cd';
            resultEl.style.borderColor = '#ffda6a';
            hint.innerHTML = `<span class="text-warning"><i class="bi bi-dash-circle me-1"></i>Убираем ${fmt(Math.abs(delta))} м³ — остаток уменьшится</span>`;
            submitBtn.disabled = false;
        } else {
            resultEl.style.color       = '';
            resultEl.style.background  = '#f8f9fa';
            resultEl.style.borderColor = '#dee2e6';
            hint.innerHTML = '';
            submitBtn.disabled = false;
        }
    });
});
</script>
@endpush
@endsection
