@extends('layouts.app')

@section('title', 'Приемка камня')

@section('content')
    <div class="container py-4">
        <div class="row">
            <!-- Форма приемки -->
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">➕ Новая приемка</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('stone-receptions.store') }}">
                            @csrf

                            <!-- Приемщик -->
                            <div class="mb-3">
                                <label class="form-label">Приемщик <span class="text-danger">*</span></label>
                                <select name="receiver_id" class="form-select @error('receiver_id') is-invalid @enderror" required>
                                    <option value="">— Выберите приемщика —</option>
                                    @foreach($workers as $worker)
                                        <option value="{{ $worker->id }}" {{ old('receiver_id', session('copy_from.receiver_id')) == $worker->id ? 'selected' : '' }}>
                                            {{ $worker->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <!-- Пильщик -->
                            <div class="mb-3">
                                <label class="form-label">Пильщик</label>
                                <select name="cutter_id" class="form-select @error('cutter_id') is-invalid @enderror">
                                    <option value="">— Не указан —</option>
                                    @foreach($workers as $worker)
                                        <option value="{{ $worker->id }}" {{ old('cutter_id', session('copy_from.cutter_id')) == $worker->id ? 'selected' : '' }}>
                                            {{ $worker->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <!-- Продукт -->
                            <!-- Используем компонент поиска товаров -->
                            <x-product-search
                                :products="$products"
                                name="product_id"
                                label="Продукт"
                                placeholder="Начните вводить название или артикул..."
                                required="true"
                                :value="old('product_id', session('copy_from.product_id'))"
                                :error="$errors->first('product_id')"
                                :maxResults="10"
                            />

                            <!-- Партия Сырья -->
                            <div class="mb-3">
                                <label class="form-label">Партия сырья <span class="text-danger">*</span></label>
                                <select name="raw_material_batch_id" class="form-select @error('raw_material_batch_id') is-invalid @enderror" required>
                                    <option value="">— Выберите партию сырья —</option>
                                    @foreach($activeBatches as $batch)
                                        <option value="{{ $batch->id }}" {{ old('raw_material_batch_id', session('copy_from.raw_material_batch_id')) == $batch->id ? 'selected' : '' }}
                                        data-product="{{ $batch->product->name }}" data-worker="{{ $batch->currentWorker->name ?? '—' }}" data-remaining="{{ $batch->remaining_quantity }}">
                                            {{ $batch->product->name }} (остаток: {{ number_format($batch->remaining_quantity, 3) }}) — {{ $batch->currentWorker->name ?? 'Без пильщика' }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('raw_material_batch_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Расход сырья (м3) <span class="text-danger">*</span></label>
                                <input type="number" step="0.001" min="0.001" name="raw_quantity_used"
                                       class="form-control @error('raw_quantity_used') is-invalid @enderror"
                                       value="{{ old('raw_quantity_used', session('copy_from.raw_quantity_used')) }}" required>
                                @error('raw_quantity_used')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                <small class="text-muted">Сколько сырья израсходовано на эту приемку</small>
                            </div>

                            <!-- Склад (фиксированный) -->
                            <div class="mb-3">
                                <label class="form-label">Склад</label>
                                <input type="text" class="form-control" value="{{ $defaultStore->name }}" readonly>
                                <input type="hidden" name="store_id" value="{{ $defaultStore->id }}">
                                <small class="text-muted">Приемка только на склад "{{ $defaultStore->name }}"</small>
                            </div>

                            <!-- Количество -->
                            <div class="mb-3">
                                <label class="form-label">Количество (м2) <span class="text-danger">*</span></label>
                                <input type="number"
                                       step="0.001"
                                       min="0"
                                       name="quantity"
                                       class="form-control @error('quantity') is-invalid @enderror"
                                       value="{{ old('quantity', session('copy_from.quantity')) }}"
                                       required>
                                <small class="text-muted">Максимум 3 знака после запятой</small>
                            </div>

                            <!-- Примечания -->
                            <div class="mb-3">
                                <label class="form-label">Примечания</label>
                                <textarea name="notes" class="form-control @error('notes') is-invalid @enderror" rows="2">{{ old('notes', session('copy_from.notes')) }}</textarea>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Сохранить приемку
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Последние приемки -->
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">📋 Последние 10 приемок</h5>
                    </div>
                    <div class="card-body p-0">
                        @if($lastReceptions->count() > 0)
                            <div class="list-group list-group-flush">
                                @foreach($lastReceptions as $reception)
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div class="flex-grow-1">
                                                <div class="d-flex justify-content-between">
                                                    <strong>{{ $reception->product->name }}</strong>
                                                    <span class="badge bg-primary">{{ number_format($reception->quantity, 3) }} м²</span>
                                                </div>
                                                <div class="small text-muted">
                                                    <i class="bi bi-person"></i> Приемщик: {{ $reception->receiver->name }}
                                                    @if($reception->cutter)
                                                        | <i class="bi bi-tools"></i> Пильщик: {{ $reception->cutter->name }}
                                                    @endif
                                                </div>
                                                <div class="small text-muted">
                                                    <i class="bi bi-clock"></i> {{ $reception->created_at->format('d.m.Y H:i') }}
                                                </div>
                                                @if($reception->notes)
                                                    <div class="small text-muted mt-1">
                                                        <i class="bi bi-chat"></i> {{ $reception->notes }}
                                                    </div>
                                                @endif
                                            </div>
                                            <div class="btn-group btn-group-sm ms-2">
                                                <!-- Кнопка копирования -->
                                                <form action="{{ route('stone-receptions.copy', $reception) }}" method="POST" class="d-inline">
                                                    @csrf
                                                    <button type="submit" class="btn btn-outline-info" title="Скопировать">
                                                        <i class="bi bi-copy"></i>
                                                    </button>
                                                </form>

                                                <!-- Кнопка редактирования -->
                                                <a href="{{ route('stone-receptions.edit', $reception) }}" class="btn btn-outline-primary" title="Редактировать">
                                                    <i class="bi bi-pencil"></i>
                                                </a>

                                                <!-- Кнопка удаления -->
                                                <form action="{{ route('stone-receptions.destroy', $reception) }}" method="POST" class="d-inline" onsubmit="return confirm('Удалить приемку?')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-outline-danger" title="Удалить">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="text-center py-5">
                                <i class="bi bi-inbox display-4 text-muted"></i>
                                <p class="text-muted mt-3">Нет приемок</p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
