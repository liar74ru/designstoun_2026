@extends('layouts.app')

@section('title', 'Редактирование приемки')

@section('content')
    <div class="container py-4">
        <!-- Навигация -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2 mb-0">✏️ Редактирование приемки #{{ $stoneReception->id }}</h1>

            <a href="{{ route('stone-receptions.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> К списку
            </a>
        </div>

        <!-- Сообщения об ошибках -->
        @if($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- Форма редактирования -->
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">📝 Данные приемки</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('stone-receptions.update', $stoneReception) }}">
                            @csrf
                            @method('PUT')

                            <!-- Приемщик -->
                            <div class="mb-3">
                                <label for="receiver_id" class="form-label">Приемщик <span class="text-danger">*</span></label>
                                <select name="receiver_id" id="receiver_id" class="form-select @error('receiver_id') is-invalid @enderror" required>
                                    <option value="">— Выберите приемщика —</option>
                                    @foreach($workers as $worker)
                                        <option value="{{ $worker->id }}" {{ old('receiver_id', $stoneReception->receiver_id) == $worker->id ? 'selected' : '' }}>
                                            {{ $worker->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('receiver_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Пильщик -->
                            <div class="mb-3">
                                <label for="cutter_id" class="form-label">Пильщик</label>
                                <select name="cutter_id" id="cutter_id" class="form-select @error('cutter_id') is-invalid @enderror">
                                    <option value="">— Не указан —</option>
                                    @foreach($workers as $worker)
                                        <option value="{{ $worker->id }}" {{ old('cutter_id', $stoneReception->cutter_id) == $worker->id ? 'selected' : '' }}>
                                            {{ $worker->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('cutter_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Продукт -->
                            <div class="mb-3">
                                <label for="product_id" class="form-label">Продукт <span class="text-danger">*</span></label>
                                <select name="product_id" id="product_id" class="form-select @error('product_id') is-invalid @enderror" required>
                                    <option value="">— Выберите продукт —</option>
                                    @foreach($products as $product)
                                        <option value="{{ $product->id }}" {{ old('product_id', $stoneReception->product_id) == $product->id ? 'selected' : '' }}>
                                            {{ $product->name }} ({{ $product->sku }})
                                        </option>
                                    @endforeach
                                </select>
                                @error('product_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Склад -->
                            <div class="mb-3">
                                <label for="store_id" class="form-label">Склад <span class="text-danger">*</span></label>
                                <select name="store_id" id="store_id" class="form-select @error('store_id') is-invalid @enderror" required>
                                    <option value="">— Выберите склад —</option>
                                    @foreach($stores as $store)
                                        <option value="{{ $store->id }}" {{ old('store_id', $stoneReception->store_id) == $store->id ? 'selected' : '' }}>
                                            {{ $store->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('store_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Количество -->
                            <div class="mb-3">
                                <label for="quantity" class="form-label">Количество (м²) <span class="text-danger">*</span></label>
                                <input type="number"
                                       step="0.001"
                                       min="0"
                                       id="quantity"
                                       name="quantity"
                                       class="form-control @error('quantity') is-invalid @enderror"
                                       value="{{ old('quantity', $stoneReception->quantity) }}"
                                       required>
                                <small class="text-muted">Максимум 3 знака после запятой</small>
                                @error('quantity')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Примечания -->
                            <div class="mb-3">
                                <label for="notes" class="form-label">Примечания</label>
                                <textarea name="notes" id="notes" class="form-control @error('notes') is-invalid @enderror" rows="3">{{ old('notes', $stoneReception->notes) }}</textarea>
                                @error('notes')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Дата создания (только для информации) -->
                            <div class="mb-3">
                                <label class="form-label">Дата создания</label>
                                <input type="text" class="form-control" value="{{ $stoneReception->created_at->format('d.m.Y H:i:s') }}" readonly>
                            </div>

                            <!-- Кнопки -->
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i> Обновить
                                </button>
                                <a href="{{ route('stone-receptions.index') }}" class="btn btn-outline-secondary">
                                    Отмена
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Информация о связанных данных -->
                <div class="card shadow-sm mt-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">ℹ️ Информация</h5>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-4">ID записи:</dt>
                            <dd class="col-sm-8">{{ $stoneReception->id }}</dd>

                            <dt class="col-sm-4">Создано:</dt>
                            <dd class="col-sm-8">{{ $stoneReception->created_at->format('d.m.Y H:i:s') }}</dd>

                            <dt class="col-sm-4">Последнее обновление:</dt>
                            <dd class="col-sm-8">{{ $stoneReception->updated_at->format('d.m.Y H:i:s') }}</dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
