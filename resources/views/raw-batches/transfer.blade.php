@extends('layouts.app')

@section('title', 'Передача партии')

@section('content')
<div class="container py-3">

    <x-page-header
        title="🔄 Передача партии #{{ $batch->batch_number ?? $batch->id }}"
        mobileTitle="Передача партии"
        :backUrl="$backUrl"
        backLabel="Назад">
    </x-page-header>

    @include('partials.alerts')

    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-white py-2">
                    <span class="fw-semibold small text-muted">Выберите нового пильщика</span>
                </div>
                <div class="card-body">
                    <style>
                        #transferForm .form-control,
                        #transferForm .form-select { border-radius: .4rem; }
                    </style>
                    <form method="POST" action="{{ route('raw-batches.transfer', $batch) }}" id="transferForm">
                        @csrf

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Текущий пильщик</label>
                            <input type="text" class="form-control" value="{{ $batch->currentWorker->name ?? '—' }}" readonly>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Количество для передачи (м³) <span class="text-danger">*</span></label>
                            <input type="number" name="quantity"
                                   class="form-control @error('quantity') is-invalid @enderror"
                                   step="0.001" min="0.001" max="{{ $batch->remaining_quantity }}"
                                   value="{{ old('quantity', rtrim(rtrim(number_format($batch->remaining_quantity, 3, '.', ''), '0'), '.')) }}"
                                   required>
                            <div class="form-text text-muted">Доступно: {{ rtrim(rtrim(number_format($batch->remaining_quantity, 2), '0'), '.') }} м³</div>
                            @error('quantity')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Новый пильщик <span class="text-danger">*</span></label>
                            <select name="to_worker_id" class="form-select @error('to_worker_id') is-invalid @enderror" required>
                                <option value="">— Выберите пильщика —</option>
                                @foreach($workers as $worker)
                                    <option value="{{ $worker->id }}" {{ old('to_worker_id') == $worker->id ? 'selected' : '' }}>
                                        {{ $worker->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('to_worker_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-arrow-left-right"></i> Передать
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
@endsection
