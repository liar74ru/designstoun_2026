@extends('layouts.app')
@section('title', 'Корректировка партии #' . $batch->id)

@section('content')
    <div class="container py-4" style="max-width:600px">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0">⚖️ Корректировка партии #{{ $batch->id }}</h1>
            <a href="{{ route('raw-batches.show', $batch) }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Назад
            </a>
        </div>

        @if($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        {{-- Текущее состояние --}}
        <div class="card mb-4 border-0 bg-light">
            <div class="card-body py-3">
                <div class="row text-center">
                    <div class="col">
                        <div class="text-muted small">Продукт</div>
                        <div class="fw-semibold">{{ $batch->product->name ?? '—' }}</div>
                    </div>
                    <div class="col">
                        <div class="text-muted small">Партия</div>
                        <div class="fw-semibold">{{ $batch->batch_number ?? '#'.$batch->id }}</div>
                    </div>
                    <div class="col">
                        <div class="text-muted small">Текущий остаток</div>
                        <div class="fw-bold fs-5">{{ number_format($batch->remaining_quantity, 3) }} м³</div>
                    </div>
                    <div class="col">
                        <div class="text-muted small">Статус</div>
                        <div>
                            @if($batch->status === 'active') <span class="badge bg-success">Активна</span>
                            @elseif($batch->status === 'used') <span class="badge bg-warning text-dark">Израсходована</span>
                            @else <span class="badge bg-secondary">Возвращена</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h5 class="mb-0">Изменить количество</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('raw-batches.adjust', $batch) }}">
                    @csrf

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Величина изменения (м³)</label>
                        <div class="row align-items-end g-3">
                            <div class="col-auto">
                                <div class="form-control bg-light text-muted" style="min-width:120px">
                                    {{ number_format($batch->remaining_quantity, 3) }} м³
                                </div>
                                <div class="form-text text-center">сейчас</div>
                            </div>
                            <div class="col-auto pb-4 fs-4 text-muted">+</div>
                            <div class="col-auto">
                                <input type="number"
                                       name="delta"
                                       id="delta"
                                       class="form-control @error('delta') is-invalid @enderror"
                                       style="width:140px"
                                       step="0.001"
                                       placeholder="0.000"
                                       value="{{ old('delta') }}"
                                       autofocus>
                                <div class="form-text text-center">«−» чтобы убавить</div>
                                @error('delta')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-auto pb-4 fs-4 text-muted">=</div>
                            <div class="col-auto">
                                <div id="result" class="form-control bg-light fw-bold" style="min-width:120px">
                                    {{ number_format($batch->remaining_quantity, 3) }} м³
                                </div>
                                <div class="form-text text-center">итого</div>
                            </div>
                        </div>
                        <div id="result_hint" class="mt-2 small"></div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Примечание</label>
                        <input type="text" name="notes" class="form-control"
                               placeholder="Причина корректировки..." value="{{ old('notes') }}">
                    </div>

                    @if(auth()->user()?->isAdmin())
                        {{-- Поле для администратора: ручная дата --}}
                        <div class="mb-4 p-3 border border-warning rounded bg-warning bg-opacity-10">
                            <label class="form-label fw-semibold text-warning-emphasis">
                                <i class="bi bi-calendar-event"></i> Дата корректировки
                                <span class="badge bg-warning text-dark ms-1" style="font-size:.7rem">Только для админа</span>
                            </label>
                            <input type="datetime-local"
                                   name="manual_created_at"
                                   class="form-control"
                                   value="{{ old('manual_created_at') }}">
                            <div class="form-text">Оставьте пустым — дата установится автоматически</div>
                        </div>
                    @endif

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="bi bi-check-lg"></i> Применить
                        </button>
                        <a href="{{ route('raw-batches.show', $batch) }}" class="btn btn-outline-secondary">Отмена</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const current   = {{ (float)$batch->remaining_quantity }};
            const deltaInput = document.getElementById('delta');
            const resultDiv  = document.getElementById('result');
            const hint       = document.getElementById('result_hint');
            const submitBtn  = document.getElementById('submitBtn');

            deltaInput.addEventListener('input', function () {
                const delta  = parseFloat(this.value) || 0;
                const result = Math.round((current + delta) * 1000) / 1000;

                resultDiv.textContent = result.toFixed(3) + ' м³';

                if (result < 0) {
                    resultDiv.className = 'form-control bg-light fw-bold text-danger';
                    hint.innerHTML = '<span class="text-danger">⚠ Нельзя убрать больше чем есть в остатке</span>';
                    submitBtn.disabled = true;
                } else if (delta > 0) {
                    resultDiv.className = 'form-control bg-light fw-bold text-success';
                    hint.innerHTML = `<span class="text-success">+ добавляем ${Math.abs(delta).toFixed(3)} м³ — остаток увеличится, склад синхронизируется</span>`;
                    submitBtn.disabled = false;
                } else if (delta < 0) {
                    resultDiv.className = 'form-control bg-light fw-bold text-warning';
                    hint.innerHTML = `<span class="text-warning">− убираем ${Math.abs(delta).toFixed(3)} м³ — остаток уменьшится, склад синхронизируется</span>`;
                    submitBtn.disabled = false;
                } else {
                    resultDiv.className = 'form-control bg-light fw-bold';
                    hint.innerHTML = '';
                    submitBtn.disabled = false;
                }
            });
        });
    </script>
@endsection
