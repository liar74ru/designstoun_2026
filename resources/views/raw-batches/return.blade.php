@extends('layouts.app')
@section('title', 'Возврат партии на склад')

@section('content')
@php
    $fmt = fn($v) => rtrim(rtrim(number_format($v, 2), '0'), '.');
    $toStoreDefault = old('to_store_id')
        ?: ($stores->first(fn($s) => mb_stripos($s->name, 'сырь') !== false)?->id ?? '');
@endphp
<div class="container py-3" style="max-width:560px">

    <x-page-header
        title="↩️ Возврат партии #{{ $batch->batch_number ?? $batch->id }}"
        mobileTitle="Возврат партии"
        :backUrl="$backUrl"
        backLabel="Назад">
    </x-page-header>

    @include('partials.alerts')

    {{-- Информация о партии --}}
    <div class="info-block mb-3">
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
                <div class="small mb-2">
                    <i class="bi bi-person me-1 text-muted"></i>
                    <span class="fw-semibold">{{ $batch->currentWorker->name }}</span>
                </div>
            @endif
            <div class="d-flex flex-column gap-1">
                <div>
                    <span class="badge rounded-pill bg-primary">
                        перемещ.: {{ $batch->latestMovement?->quantity ? $fmt($batch->latestMovement->quantity).' м³' : '—' }}
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

    <div class="card shadow-sm">
        <div class="card-header bg-white py-2">
            <span class="fw-semibold small text-muted">Выберите склад для возврата</span>
        </div>
        <div class="card-body">
            <style>
                #returnForm .form-control,
                #returnForm .form-select { border-radius: .4rem; }
            </style>
            <form method="POST" action="{{ route('raw-batches.return', $batch) }}" id="returnForm">
                @csrf

                <div class="mb-3">
                    <label class="form-label fw-semibold">Текущий склад</label>
                    <input type="text" class="form-control" value="{{ $batch->currentStore->name ?? '—' }}" readonly>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold">
                        Склад для возврата <span class="text-danger">*</span>
                    </label>
                    <select name="to_store_id" class="form-select @error('to_store_id') is-invalid @enderror" required>
                        <option value="">— Выберите склад —</option>
                        @foreach($stores as $store)
                            <option value="{{ $store->id }}" {{ $toStoreDefault == $store->id ? 'selected' : '' }}>
                                {{ $store->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('to_store_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-secondary">
                        <i class="bi bi-arrow-return-left"></i> Вернуть на склад
                    </button>
                    <a href="{{ $backUrl }}" class="btn btn-outline-secondary text-nowrap">Отмена</a>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection
