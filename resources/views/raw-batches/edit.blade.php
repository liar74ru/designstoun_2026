@extends('layouts.app')

@section('title', 'Редактировать партию #' . $batch->id)

@section('content')
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2 mb-0">✏️ Редактировать партию #{{ $batch->id }}</h1>
            <a href="{{ route('raw-batches.show', $batch) }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Назад
            </a>
        </div>

        <div class="row justify-content-center">
            <div class="col-md-7">

                <div class="alert alert-info py-2 mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    Редактирование доступно только для партий в статусе <strong>«Новая»</strong>.
                    Изменения продукта и количества будут синхронизированы с МойСклад.
                </div>

                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <form method="POST" action="{{ route('raw-batches.update', $batch) }}">
                            @csrf
                            @method('PUT')

                            @if($errors->any())
                                <div class="alert alert-danger py-2">
                                    @foreach($errors->all() as $error)
                                        <div class="small">{{ $error }}</div>
                                    @endforeach
                                </div>
                            @endif

                            {{-- Номер партии (только для справки, не редактируется) --}}
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-muted">Номер партии</label>
                                <input type="text" class="form-control bg-light" value="{{ $batch->batch_number ?? '—' }}" readonly>
                            </div>

                            {{-- Продукт --}}
                            <div class="mb-3">
                                <label for="product_id" class="form-label fw-semibold">
                                    Сырьё <span class="text-danger">*</span>
                                </label>
                                <select name="product_id" id="product_id"
                                        class="form-select @error('product_id') is-invalid @enderror"
                                        required>
                                    <option value="">— Выберите продукт —</option>
                                    @foreach($products as $product)
                                        <option value="{{ $product->id }}"
                                            {{ old('product_id', $batch->product_id) == $product->id ? 'selected' : '' }}>
                                            {{ $product->name }}
                                            @if($product->sku) ({{ $product->sku }}) @endif
                                        </option>
                                    @endforeach
                                </select>
                                @error('product_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            {{-- Дата создания --}}
                            @if(auth()->user()?->isAdmin())
                                <div class="mb-3">
                                    <label for="manual_created_at" class="form-label fw-semibold">
                                        Дата создания
                                    </label>
                                    <input type="datetime-local"
                                           name="manual_created_at"
                                           id="manual_created_at"
                                           class="form-control @error('manual_created_at') is-invalid @enderror"
                                           value="{{ old('manual_created_at', $batch->created_at->format('Y-m-d\TH:i')) }}">
                                    @error('manual_created_at')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                    <div class="form-text text-muted">Изменение даты синхронизируется с МойСклад.</div>
                                </div>
                            @endif

                            {{-- Количество --}}
                            <div class="mb-4">
                                <label for="quantity" class="form-label fw-semibold">
                                    Количество (м³) <span class="text-danger">*</span>
                                </label>
                                <input type="number"
                                       name="quantity"
                                       id="quantity"
                                       class="form-control @error('quantity') is-invalid @enderror"
                                       value="{{ old('quantity', number_format($batch->initial_quantity, 3, '.', '')) }}"
                                       step="0.001"
                                       min="0.001"
                                       required>
                                @error('quantity')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                <div class="form-text text-muted">
                                    Текущий остаток: <strong>{{ number_format($batch->remaining_quantity, 3) }} м³</strong>
                                    — будет заменён новым значением.
                                </div>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-lg me-1"></i> Сохранить изменения
                                </button>
                                <a href="{{ route('raw-batches.show', $batch) }}" class="btn btn-outline-secondary">
                                    Отмена
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                {{-- Блок удаления --}}
                <div class="card shadow-sm border-danger mt-4">
                    <div class="card-body">
                        <h6 class="text-danger mb-2"><i class="bi bi-trash me-1"></i>Удалить партию</h6>
                        <p class="text-muted small mb-3">
                            Партия будет безвозвратно удалена. Перемещение в МойСклад также будет удалено.
                            Сырьё вернётся на склад-источник.
                        </p>
                        <form method="POST" action="{{ route('raw-batches.destroy-new', $batch) }}"
                              onsubmit="return confirm('Удалить партию #{{ $batch->id }}? Это действие необратимо.')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                <i class="bi bi-trash me-1"></i> Удалить партию
                            </button>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>
@endsection
