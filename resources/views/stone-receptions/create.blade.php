@extends('layouts.app')

@section('title', 'Приемка камня')

@section('content')
    <div class="container py-4">
        <div class="row">
            <!-- Форма приемки -->
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">➕ Новая приемка</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('stone-receptions.store') }}" id="receptionForm">
                            @csrf

                            <!-- Поле пильщика ВНУТРИ ФОРМЫ -->
                            <div class="row mb-4">
                                <div class="col-md-12">
                                    <label class="form-label fw-bold">Пильщик <span class="text-danger">*</span></label>
                                    <select name="cutter_id" id="cutter_id" class="form-select form-select-lg @error('cutter_id') is-invalid @enderror" onchange="window.location = '{{ route('stone-receptions.create') }}?cutter_id=' + this.value + '&raw_material_batch_id={{ request('raw_material_batch_id') }}'">
                                        <option value="">— Выберите пильщика —</option>
                                        @foreach($workers as $worker)
                                            <option value="{{ $worker->id }}"
                                                {{ request('cutter_id') == $worker->id ? 'selected' : '' }}>
                                                {{ $worker->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('cutter_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            <!-- Приемщик -->
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <label class="form-label">Приемщик <span class="text-danger">*</span></label>
                                    <select name="receiver_id" class="form-select @error('receiver_id') is-invalid @enderror" required>
                                        @foreach($masterWorkers as $worker)
                                            <option value="{{ $worker->id }}"
                                                {{ old('receiver_id', session('copy_data.receiver_id')) == $worker->id ? 'selected' : '' }}>
                                                {{ $worker->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('receiver_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            <!-- Партия сырья и расход -->
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label class="form-label">Партия сырья <span class="text-danger">*</span></label>
                                    <select name="raw_material_batch_id" id="raw_material_batch_id" class="form-select @error('raw_material_batch_id') is-invalid @enderror" required>
                                        @if(!request('cutter_id'))
                                            <option value="">— Сначала выберите пильщика —</option>
                                        @elseif($filteredBatches->isEmpty())
                                            <option value="">— У выбранного пильщика нет партий —</option>
                                        @else
                                            <option value="">— Выберите партию —</option>
                                        @endif

                                        @foreach($filteredBatches as $batch)
                                            <option value="{{ $batch->id }}"
                                                    data-remaining="{{ $batch->remaining_quantity }}"
                                                {{ old('raw_material_batch_id', request('raw_material_batch_id')) == $batch->id ? 'selected' : '' }}>
                                                {{ $batch->product->name }} (ост: {{ number_format($batch->remaining_quantity, 2) }} м³)
                                                @if($batch->batch_number) №{{ $batch->batch_number }} @endif
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Расход (м³) <span class="text-danger">*</span></label>
                                    <input type="number" step="0.001" min="0.001" name="raw_quantity_used" id="raw_quantity_used"
                                           class="form-control @error('raw_quantity_used') is-invalid @enderror"
                                           value="{{ old('raw_quantity_used', 1) }}"
                                        {{ !request('cutter_id') ? 'disabled' : '' }}>
                                    <small id="remainingInfo" class="text-info"></small>
                                    @error('raw_quantity_used')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            <!-- Склад (скрытый) -->
                            <input type="hidden" name="store_id" value="{{ $defaultStore->id }}">

                            <!-- Блок с продуктами (всегда видим) -->
                            <div class="mb-3">
                                <label class="form-label">Продукция <span class="text-danger">*</span></label>

                                <!-- Контейнер для продуктов -->
                                <div id="products-container">
                                    <!-- Продукты будут добавляться сюда через JavaScript -->
                                </div>

                                <!-- Кнопка добавления -->
                                <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="addProductBtn">
                                    <i class="bi bi-plus-circle"></i> Добавить продукт
                                </button>

                                <!-- Итого -->
                                <div class="mt-3 p-2 bg-light rounded">
                                    <strong>Всего:</strong> <span id="totalProducts">0</span> м²
                                </div>
                            </div>

                            <!-- Примечания -->
                            <div class="mb-3">
                                <label class="form-label">Примечания</label>
                                <textarea name="notes" class="form-control" rows="2">{{ old('notes', session('copy_data.notes')) }}</textarea>
                            </div>

                            <button type="submit" class="btn btn-primary btn-lg">
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
                        <h5 class="mb-0">📋 Последние приемки</h5>
                    </div>
                    <div class="card-body p-0">
                        @forelse($lastReceptions as $reception)
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <strong>Приемка #{{ $reception->id }}</strong>
                                        <span class="badge bg-primary">{{ number_format($reception->total_quantity, 2) }} м²</span>

                                        @foreach($reception->items as $item)
                                            <div class="small text-muted">
                                                {{ $item->product->name }}: {{ number_format($item->quantity, 2) }} м²
                                            </div>
                                        @endforeach

                                        <div class="small text-muted mt-1">
                                            <i class="bi bi-person"></i> {{ $reception->receiver->name }}
                                            @if($reception->cutter) | <i class="bi bi-tools"></i> {{ $reception->cutter->name }} @endif
                                            <br>
                                            <i class="bi bi-clock"></i> {{ $reception->created_at->format('d.m.Y H:i') }}
                                        </div>
                                    </div>

                                    <div class="btn-group btn-group-sm">
                                        <form action="{{ route('stone-receptions.copy', $reception) }}" method="POST">
                                            @csrf
                                            <input type="hidden" name="cutter_id" value="{{ request('cutter_id') }}">
                                            <input type="hidden" name="raw_material_batch_id" value="{{ request('raw_material_batch_id') }}">
                                            <button type="submit" class="btn btn-outline-info" title="Копировать">
                                                <i class="bi bi-copy"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-inbox display-4"></i>
                                <p>Нет приемок</p>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        // Глобальная функция для инициализации поиска
        window.initSingleProductSearch = function(wrapper) {
            const searchInput = wrapper.querySelector('.product-search-input');
            const hiddenInput = wrapper.querySelector('input[type="hidden"]');
            const datalist = wrapper.querySelector('datalist');

            if (!searchInput || !hiddenInput || !datalist) return;

            // Получаем данные продуктов из атрибута
            let allProducts = {};
            try {
                allProducts = JSON.parse(searchInput.dataset.products);
            } catch(e) {
                return;
            }

            const maxResults = parseInt(searchInput.dataset.maxResults) || 10;

            function matchesParts(text, query) {
                const parts = query.toLowerCase().split(/\s+/).filter(p => p.length > 0);
                return parts.every(part => text.toLowerCase().includes(part));
            }

            function updateDatalist(filterQuery = '') {
                datalist.innerHTML = '';

                let count = 0;
                for (const [productName, id] of Object.entries(allProducts)) {
                    if ((filterQuery === '' || matchesParts(productName, filterQuery)) && count < maxResults) {
                        const option = document.createElement('option');
                        option.value = productName;
                        option.setAttribute('data-id', id);
                        datalist.appendChild(option);
                        count++;
                    }
                }
            }

            searchInput.addEventListener('input', function() {
                updateDatalist(this.value);
            });

            searchInput.addEventListener('change', function() {
                const selectedValue = allProducts[this.value];
                if (selectedValue) {
                    hiddenInput.value = selectedValue;
                } else {
                    hiddenInput.value = '';
                }
            });

            updateDatalist();

            if (hiddenInput.value) {
                const productEntry = Object.entries(allProducts).find(([name, id]) => id == hiddenInput.value);
                if (productEntry) {
                    searchInput.value = productEntry[0];
                }
            }
        };

        document.addEventListener('DOMContentLoaded', function() {
            // Получаем параметры URL
            const urlParams = new URLSearchParams(window.location.search);
            const cutterId = urlParams.get('cutter_id');

            // Инициализация
            let productCount = 0;
            const container = document.getElementById('products-container');
            const addBtn = document.getElementById('addProductBtn');
            const batchSelect = document.getElementById('raw_material_batch_id');
            const rawQuantity = document.getElementById('raw_quantity_used');
            const remainingInfo = document.getElementById('remainingInfo');
            const totalSpan = document.getElementById('totalProducts');

            if (!container) return;

            // Получаем скопированные продукты из сессии
            const copiedProducts = @json(session('copy_data.products', []));
            const copiedReceiverId = @json(session('copy_data.receiver_id', ''));
            const copiedNotes = @json(session('copy_data.notes', ''));

            // Функция обновления информации об остатке
            function updateRemainingInfo() {
                const selected = batchSelect.options[batchSelect.selectedIndex];
                if (selected && selected.value) {
                    const remaining = parseFloat(selected.dataset.remaining) || 0;
                    remainingInfo.textContent = `Доступно: ${remaining.toFixed(2)} м³`;

                    const used = parseFloat(rawQuantity.value) || 0;
                    if (used > remaining) {
                        rawQuantity.setCustomValidity('Превышение остатка');
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
                const productsData = @json($products->mapWithKeys(function($product) {
                    return [$product->name . ' (' . $product->sku . ')' => $product->id];
                }));

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
                                               data-products='${JSON.stringify(productsData)}'
                                               ${productData ? `value="${Object.entries(productsData).find(([name, id]) => id == productData.product_id)?.[0] || ''}"` : ''}
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
                const newItem = container.lastElementChild;

                if (window.initSingleProductSearch) {
                    window.initSingleProductSearch(newItem.querySelector('.product-search-wrapper'));
                }

                newItem.querySelector('.remove-product').addEventListener('click', () => {
                    newItem.remove();
                    updateTotal();
                });

                newItem.querySelector('.product-quantity').addEventListener('input', updateTotal);
                productCount++;
                updateTotal();
            }

            // Добавляем продукты из сессии
            if (copiedProducts && copiedProducts.length > 0) {
                copiedProducts.forEach(product => addProduct(product));

                // Устанавливаем приемщика если есть
                if (copiedReceiverId) {
                    const receiverSelect = document.querySelector('select[name="receiver_id"]');
                    if (receiverSelect) {
                        receiverSelect.value = copiedReceiverId;
                    }
                }

                // Устанавливаем примечания если есть
                if (copiedNotes) {
                    const notesTextarea = document.querySelector('textarea[name="notes"]');
                    if (notesTextarea) {
                        notesTextarea.value = copiedNotes;
                    }
                }

                // Подставляем сумму продуктов в поле расхода, если оно пустое
                const totalProductQuantity = copiedProducts.reduce((sum, product) => {
                    return sum + (parseFloat(product.quantity) || 0);
                }, 0);

                if (totalProductQuantity > 0 && !rawQuantity.value) {
                    rawQuantity.value = totalProductQuantity.toFixed(3);
                }
            } else {
                // Если нет скопированных продуктов, добавляем один пустой
                addProduct();
            }

            // Обработчики
            if (addBtn) addBtn.addEventListener('click', () => addProduct());
            if (batchSelect) batchSelect.addEventListener('change', updateRemainingInfo);
            if (rawQuantity) rawQuantity.addEventListener('input', updateRemainingInfo);

            updateRemainingInfo();

            // Валидация формы
            document.getElementById('receptionForm')?.addEventListener('submit', function(e) {
                const products = document.querySelectorAll('.product-item');
                const cutterId = document.querySelector('select[name="cutter_id"]').value;

                if (!cutterId) {
                    e.preventDefault();
                    alert('Выберите пильщика');
                    return;
                }

                if (!batchSelect.value) {
                    e.preventDefault();
                    alert('Выберите партию сырья');
                    return;
                }

                if (products.length === 0) {
                    e.preventDefault();
                    alert('Добавьте хотя бы один продукт');
                    return;
                }

                let valid = true;
                products.forEach((item) => {
                    const hiddenInput = item.querySelector('input[type="hidden"][name*="[product_id]"]');
                    const quantity = item.querySelector('.product-quantity');

                    if (!hiddenInput.value) {
                        valid = false;
                        item.classList.add('border', 'border-danger');
                    }

                    if (!quantity.value || parseFloat(quantity.value) <= 0) {
                        valid = false;
                        item.classList.add('border', 'border-danger');
                        quantity.classList.add('is-invalid');
                    } else {
                        quantity.classList.remove('is-invalid');
                    }
                });

                if (!valid) {
                    e.preventDefault();
                    alert('Заполните все поля продуктов корректно');
                    return;
                }

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
