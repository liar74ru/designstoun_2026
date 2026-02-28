@extends('layouts.app')

@section('title', 'Передача партии')

@section('content')
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2 mb-0">🔄 Передача партии #{{ $batch->id }}</h1>
            <a href="{{ route('raw-batches.show', $batch) }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Назад
            </a>
        </div>

        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">Выберите нового пильщика</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('raw-batches.transfer', $batch) }}">
                            @csrf

                            <div class="mb-3">
                                <label class="form-label">Текущий пильщик</label>
                                <input type="text" class="form-control" value="{{ $batch->currentWorker->name ?? '—' }}" readonly>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Новый пильщик <span class="text-danger">*</span></label>
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

                            <div class="mb-3">
                                <label class="form-label">Остаток в партии</label>
                                <input type="text" class="form-control" value="{{ number_format($batch->remaining_quantity, 3) }} м²" readonly>
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
