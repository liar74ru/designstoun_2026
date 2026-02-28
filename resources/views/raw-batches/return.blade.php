@extends('layouts.app')

@section('title', 'Возврат партии на склад')

@section('content')
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2 mb-0">↩️ Возврат партии #{{ $batch->id }} на склад</h1>
            <a href="{{ route('raw-batches.show', $batch) }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Назад
            </a>
        </div>

        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">Выберите склад для возврата</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('raw-batches.return', $batch) }}">
                            @csrf

                            <div class="mb-3">
                                <label class="form-label">Текущий склад</label>
                                <input type="text" class="form-control" value="{{ $batch->currentStore->name ?? '—' }}" readonly>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Склад для возврата <span class="text-danger">*</span></label>
                                <select name="to_store_id" class="form-select @error('to_store_id') is-invalid @enderror" required>
                                    <option value="">— Выберите склад —</option>
                                    @foreach($stores as $store)
                                        <option value="{{ $store->id }}" {{ old('to_store_id') == $store->id ? 'selected' : '' }}>
                                            {{ $store->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('to_store_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Остаток к возврату</label>
                                <input type="text" class="form-control" value="{{ number_format($batch->remaining_quantity, 3) }} м²" readonly>
                            </div>

                            <button type="submit" class="btn btn-secondary">
                                <i class="bi bi-arrow-return-left"></i> Вернуть на склад
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
