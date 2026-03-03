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
            <div class="col-md-10">
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">📝 Данные приемки</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('stone-receptions.update', $stoneReception) }}" id="receptionForm">
                            @csrf
                            @method('PUT')

                            <!-- Приемщик и Пильщик в одной строке -->
                            <div class="row">
                                <div class="col-md-6 mb-3">
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

                                <div class="col-md-6 mb-3">
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
                            </div>

                            <!-- Партия сырья и расход -->
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label for="raw_material_batch_id" class="form-label">Партия сырья <span class="text-danger">*</span></label>
                                    <select name="raw_material_batch_id" id="raw_material_batch_id" class="form-select @error('raw_material_batch_id') is-invalid @enderror" required>
                                        <option value="">— Выберите партию сырья —</option>
                                        @foreach($activeBatches as $batch)
                                            <option value="{{ $batch->id }}"
                                                    data-remaining="{{ $batch->remaining_quantity }}"
                                                {{ old('raw_material_batch_id', $stoneReception->raw_material_batch_id) == $batch->id ? 'selected' : '' }}>
                                                {{ $batch->product->name }} (остаток: {{ number_format($batch->remaining_quantity, 2) }} м³)
                                                @if($batch->currentWorker) — {{ $batch->currentWorker->name }} @endif
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('raw_material_batch_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label for="raw_quantity_used" class="form-label">Расход сырья (м³) <span class="text-danger">*</span></label>
                                    <input type="number"
                                           step="0.001"
                                           min="0.001"
                                           id="raw_quantity_used"
                                           name="raw_quantity_used"
                                           class="form-control @error('raw_quantity_used') is-invalid @enderror"
                                           value="{{ old('raw_quantity_used', $stoneReception->raw_quantity_used) }}"
                                           required>
                                    <small id="remainingInfo" class="text-info"></small>
                                    @error('raw_quantity_used')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            <!-- Склад (фиксированный или выбираемый) -->
                            <div class="mb-3">
                                <label for="store_id" class="form-label">Склад <span class="text-danger">*</span></label>
                                @if(env('DEFAULT_STORE_ID'))
                                    <input type="text" class="form-control" value="{{ $defaultStore->name ?? 'Склад по умолчанию' }}" readonly>
                                    <input type="hidden" name="store_id" value="{{ $defaultStore->id }}">
                                    <small class="text-muted">Приемка только на склад "{{ $defaultStore->name }}"</small>
                                @else
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
                                @endif
                            </div>

                            <!-- Блок с продуктами -->
                            <div class="mb-3">
                                <label class="form-label">Продукция <span class="text-danger">*</span></label>

                                <!-- Контейнер для продуктов -->
                                <div id="products-container">
                                    <!-- Существующие продукты будут добавлены через JavaScript -->
                                </div>

                                <!-- Кнопка добавления -->
                                <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="addProductBtn">
                                    <i class="bi bi-plus-circle"></i> Добавить продукт
                                </button>

                                <!-- Итого -->
                                <div class="mt-3 p-2 bg-light rounded">
                                    <strong>Всего продукции:</strong> <span id="totalProducts">0</span> м²
                                </div>
                            </div>

                            <!-- Примечания -->
                            <div class="mb-3">
                                <label for="notes" class="form-label">Примечания</label>
                                <textarea name="notes" id="notes" class="form-control @error('notes') is-invalid @enderror" rows="3">{{ old('notes', $stoneReception->notes) }}</textarea>
                                @error('notes')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
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

                            <dt class="col-sm-4">Количество продукции:</dt>
                            <dd class="col-sm-8">{{ $stoneReception->items->count() }} позиций</dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            let productCount = 0;
            const container = document.getElementById('products-container');
            const addBtn = document.getElementById('addProductBtn');
            const batchSelect = document.getElementById('raw_material_batch_id');
            const rawQuantity = document.getElementById('raw_quantity_used');
            const remainingInfo = document.getElementById('remainingInfo');
            const totalSpan = document.getElementById('totalProducts');

            // Существующие продукты из базы данных
            const existingProducts = @json($stoneReception->items->map(function($item) {
        return [
            'product_id' => $item->product_id,
            'quantity' => $item->quantity
        ];
    }));

            // Функция обновления информации об остатке
            function updateRemainingInfo() {
                const selected = batchSelect.options[batchSelect.selectedIndex];
                if (selected && selected.value) {
                    const remaining = parseFloat(selected.dataset.remaining) || 0;
                    remainingInfo.textContent = `Доступно сырья: ${remaining.toFixed(2)} м³`;

                    const used = parseFloat(rawQuantity.value) || 0;
                    if (used > remaining) {
                        rawQuantity.setCustomValidity('Расход сырья превышает остаток в партии');
                    } else {
                        rawQuantity.setCustomValidity('');
                    }
                } else {
                    remainingInfo.textContent = '';
                }
            }

            // Функция подсчета общего количества
            function updateTotal() {
                let total = 0;
                document.querySelectorAll('.product-quantity').forEach(input => {
                    total += parseFloat(input.value) || 0;
                });
                totalSpan.textContent = total.toFixed(2);
            }

            // Функция добавления нового продукта
            function addProduct(productData = null) {
                const productHtml = `
            <div class="product-item card mb-2" data-index="${productCount}">
                <div class="card-body p-2">
                    <div class="row g-2 align-items-center">
                        <div class="col-md-7">
                            <div class="product-search-wrapper">
                                <input type="text"
                                       class="form-control product-search-input"
                                       list="products-list-product_${productCount}"
                                       placeholder="Начните вводить название или артикул..."
                                       id="productInput-product_${productCount}"
                                       data-target="productIdHidden-product_${productCount}"
                                       data-max-results="10"
                                       data-products='${JSON.stringify(@json($products->mapWithKeys(function($product) {
                                           return [$product->name . ' (' . $product->sku . ')' => $product->id];
                                       })))}'
                                       ${productData ? `value="${Object.entries(@json($products->mapWithKeys(function($product) {
                                           return [$product->name . ' (' . $product->sku . ')' => $product->id];
                                       }))).find(([name, id]) => id == productData.product_id)?.[0] || ''}"` : ''}
                                       required>
                                <datalist id="products-list-product_${productCount}"></datalist>
                                <input type="hidden"
                                       name="products[${productCount}][product_id]"
                                       id="productIdHidden-product_${productCount}"
                                       value="${productData ? productData.product_id : ''}"
                                       required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <input type="number"
                                   step="0.001"
                                   min="0.001"
                                   name="products[${productCount}][quantity]"
                                   class="form-control form-control-sm product-quantity"
                                   placeholder="Кол-во"
                                   value="${productData ? productData.quantity : ''}"
                                   required>
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-sm btn-outline-danger remove-product">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;

                container.insertAdjacentHTML('beforeend', productHtml);

                // Получаем новый элемент
                const newItem = container.lastElementChild;

                // Инициализируем поиск в новом элементе
                if (window.initSingleProductSearch) {
                    window.initSingleProductSearch(newItem.querySelector('.product-search-wrapper'));
                }

                // Обработчик удаления
                newItem.querySelector('.remove-product').addEventListener('click', () => {
                    newItem.remove();
                    updateTotal();
                });

                // Обработчик изменения количества
                newItem.querySelector('.product-quantity').addEventListener('input', updateTotal);

                productCount++;
                updateTotal();
            }

            // Загружаем существующие продукты
            if (existingProducts && existingProducts.length > 0) {
                existingProducts.forEach(product => {
                    addProduct(product);
                });
            } else {
                // Если нет продуктов, добавляем один пустой
                addProduct();
            }

            // Обработчики событий
            addBtn.addEventListener('click', () => addProduct());
            batchSelect.addEventListener('change', updateRemainingInfo);
            rawQuantity.addEventListener('input', updateRemainingInfo);

            // Инициализация информации об остатке
            updateRemainingInfo();

            // Валидация формы
            document.getElementById('receptionForm').addEventListener('submit', function(e) {
                const products = document.querySelectorAll('.product-item');

                if (products.length === 0) {
                    e.preventDefault();
                    alert('Добавьте хотя бы один продукт');
                    return;
                }

                let valid = true;
                products.forEach(item => {
                    const hiddenInput = item.querySelector('input[type="hidden"][name*="[product_id]"]');
                    const quantity = item.querySelector('.product-quantity');

                    if (!hiddenInput.value || !quantity.value || parseFloat(quantity.value) <= 0) {
                        valid = false;
                        item.classList.add('border', 'border-danger');
                    } else {
                        item.classList.remove('border', 'border-danger');
                    }
                });

                if (!valid) {
                    e.preventDefault();
                    alert('Заполните все поля продуктов корректно');
                    return;
                }

                // Проверка расхода сырья
                const selected = batchSelect.options[batchSelect.selectedIndex];
                if (selected && selected.value) {
                    const remaining = parseFloat(selected.dataset.remaining) || 0;
                    const used = parseFloat(rawQuantity.value) || 0;

                    if (used > remaining) {
                        e.preventDefault();
                        alert('Расход сырья превышает остаток в партии');
                        return;
                    }
                }
            });
        });
    </script>
@endpush
