@extends('layouts.app')

@section('title', 'Новая партия сырья')

@section('content')
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2 mb-0">➕ Новая партия сырья</h1>
            <a href="{{ route('raw-batches.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> К списку
            </a>
        </div>

        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <form method="POST" action="{{ route('raw-batches.store') }}">
                            @csrf

                            <!-- Продукт (сырьё) -->
                            <div class="mb-3">
                                <label class="form-label">Продукт (сырьё) <span class="text-danger">*</span></label>
                                <select name="product_id" class="form-select @error('product_id') is-invalid @enderror" required>
                                    <option value="">— Выберите сырьё —</option>
                                    @foreach($products as $product)
                                        <option value="{{ $product->id }}" {{ old('product_id') == $product->id ? 'selected' : '' }}>
                                            {{ $product->name }} ({{ $product->sku }})
                                        </option>
                                    @endforeach
                                </select>
                                @error('product_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Количество -->
                            <div class="mb-3">
                                <label class="form-label">Количество (м²) <span class="text-danger">*</span></label>
                                <input type="number" step="0.001" min="0.001" name="quantity"
                                       class="form-control @error('quantity') is-invalid @enderror"
                                       value="{{ old('quantity') }}" required>
                                @error('quantity')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Номер партии (опционально) -->
                            <div class="mb-3">
                                <label class="form-label">Номер партии</label>
                                <input type="text" name="batch_number" class="form-control @error('batch_number') is-invalid @enderror"
                                       value="{{ old('batch_number') }}">
                                @error('batch_number')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Склад-источник -->
                            <div class="mb-3">
                                <label class="form-label">Склад-источник <span class="text-danger">*</span></label>
                                <select name="from_store_id" class="form-select @error('from_store_id') is-invalid @enderror" required>
                                    <option value="">— Выберите склад —</option>
                                    @foreach($stores as $store)
                                        <option value="{{ $store->id }}" {{ old('from_store_id') == $store->id ? 'selected' : '' }}>
                                            {{ $store->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('from_store_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Склад-назначение (обычно цех) -->
                            <div class="mb-3">
                                <label class="form-label">Склад-назначение (цех) <span class="text-danger">*</span></label>
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

                            <!-- Пильщик (кому передаётся) -->
                            <div class="mb-3">
                                <label class="form-label">Закрепить за пильщиком <span class="text-danger">*</span></label>
                                <select name="worker_id" class="form-select @error('worker_id') is-invalid @enderror" required>
                                    <option value="">— Выберите пильщика —</option>
                                    @foreach($workers as $worker)
                                        <option value="{{ $worker->id }}" {{ old('worker_id') == $worker->id ? 'selected' : '' }}>
                                            {{ $worker->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('worker_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Создать партию
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
