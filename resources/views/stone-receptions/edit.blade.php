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
                                        @foreach($masterWorkers as $worker)
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
                                        {{--                                        <option value="">— Выберите партию сырья —</option>--}}
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
    @vite(['resources/js/product-picker.js'])
    <script>
        document.addEventListener('DOMContentLoaded', function () {

            let rowIndex = 0;
            const container     = document.getElementById('products-container');
            const addBtn        = document.getElementById('addProductBtn');
            const batchSelect   = document.getElementById('raw_material_batch_id');
            const rawQuantity   = document.getElementById('raw_quantity_used');
            const remainingInfo = document.getElementById('remainingInfo');
            const totalSpan     = document.getElementById('totalProducts');

            // ── Добавить строку ────────────────────────────────────────────────
            function addProduct(productId = '', productLabel = '', quantity = '') {
                const idx = rowIndex;
                const tpl = document.getElementById('editPickerRowTemplate');
                const clone = tpl.content.cloneNode(true);

                // Заменяем __IDX__ во всех нужных атрибутах
                clone.querySelectorAll('[data-tpl-idx]').forEach(el => {
                    ['id', 'name', 'data-hidden-id', 'data-search-id', 'data-modal']
                        .forEach(attr => {
                            if (el.hasAttribute(attr)) {
                                el.setAttribute(attr,
                                    el.getAttribute(attr).replace(/__IDX__/g, idx));
                            }
                        });
                });

                // Сохраняем ссылку до того как fragment растворится
                const row = clone.querySelector('.product-picker-row');

                container.appendChild(clone);

                // Заполняем значения
                if (productLabel) row.querySelector('.product-picker-search').value = productLabel;
                if (productId)    row.querySelector(`[name="products[${idx}][product_id]"]`).value = productId;
                if (quantity !== '') row.querySelector('.product-picker-qty').value = quantity;

                // Инициализируем через ProductPicker
                if (window.ProductPicker) window.ProductPicker.initRow(row);

                row.querySelector('.product-picker-qty')
                    ?.addEventListener('input', updateTotal);

                rowIndex++;
                updateTotal();
            }

            // ── Итого ──────────────────────────────────────────────────────────
            function updateTotal() {
                let total = 0;
                container.querySelectorAll('.product-picker-qty')
                    .forEach(el => { total += parseFloat(el.value) || 0; });
                totalSpan.textContent = total.toFixed(2);
            }

            // ── Загружаем существующие продукты ───────────────────────────────
            const existingProducts = @json($stoneReception->items->map(fn($i) => [
                'product_id' => $i->product_id,
                'quantity'   => (float)$i->quantity,
            ])->values());

            if (existingProducts.length > 0 && window.ProductPicker) {
                window.ProductPicker.fetchTree().then(tree => {
                    const flat = {};
                    (function flatMap(groups) {
                        groups.forEach(g => {
                            (g.products || []).forEach(p => { flat[p.id] = p.label; });
                            if (g.children?.length) flatMap(g.children);
                        });
                    })(tree);
                    existingProducts.forEach(p =>
                        addProduct(p.product_id, flat[p.product_id] || '', p.quantity));
                });
            } else if (existingProducts.length === 0) {
                addProduct();
            }

            addBtn.addEventListener('click', () => addProduct());
            document.addEventListener('product-picker:removed', updateTotal);

            // ── Остаток партии ─────────────────────────────────────────────────
            function updateRemainingInfo() {
                const opt = batchSelect?.options[batchSelect.selectedIndex];
                if (opt?.value) {
                    const rem  = parseFloat(opt.dataset.remaining) || 0;
                    const used = parseFloat(rawQuantity.value) || 0;
                    remainingInfo.textContent = `Доступно сырья: ${rem.toFixed(2)} м³`;
                    rawQuantity.setCustomValidity(used > rem ? 'Расход превышает остаток' : '');
                } else {
                    remainingInfo.textContent = '';
                }
            }
            batchSelect?.addEventListener('change', updateRemainingInfo);
            rawQuantity?.addEventListener('input', updateRemainingInfo);
            updateRemainingInfo();

            // ── Валидация ──────────────────────────────────────────────────────
            document.getElementById('receptionForm').addEventListener('submit', function (e) {
                const rows = container.querySelectorAll('.product-picker-row');
                if (!rows.length) {
                    e.preventDefault(); alert('Добавьте хотя бы один продукт'); return;
                }
                let ok = true;
                rows.forEach(row => {
                    const pid = row.querySelector('input[type="hidden"][name*="product_id"]')?.value;
                    const qty = parseFloat(row.querySelector('.product-picker-qty')?.value);
                    if (!pid || !qty || qty <= 0) {
                        ok = false;
                        row.classList.add('border', 'border-danger', 'rounded');
                    } else {
                        row.classList.remove('border', 'border-danger', 'rounded');
                    }
                });
                if (!ok) { e.preventDefault(); alert('Заполните все поля продуктов корректно'); }
            });
        });
    </script>
@endpush

{{-- Шаблон строки продукта — идентичен create.blade.php --}}
<template id="editPickerRowTemplate">
    <div class="product-picker-row d-flex gap-2 align-items-start mb-2">
        <div class="flex-grow-1 position-relative">
            <div class="input-group">
                <input type="text"
                       id="edit_search___IDX__"
                       data-tpl-idx="1"
                       class="form-control product-picker-search"
                       placeholder="Введите название продукта..."
                       autocomplete="off"
                       data-hidden-id="edit_pid___IDX__">
                <button type="button"
                        class="btn btn-outline-secondary product-picker-tree-btn"
                        data-modal="edit_modal___IDX__"
                        data-hidden-id="edit_pid___IDX__"
                        data-search-id="edit_search___IDX__"
                        data-tpl-idx="1"
                        title="Выбрать из каталога">
                    <i class="bi bi-diagram-3"></i>
                </button>
            </div>
            <div class="product-picker-dropdown list-group shadow-sm"
                 style="display:none;position:absolute;z-index:1000;width:100%;max-height:280px;overflow-y:auto">
            </div>
        </div>

        <input type="number"
               id="edit_qty___IDX__"
               name="products[__IDX__][quantity]"
               class="form-control product-picker-qty"
               style="width:100px"
               placeholder="м²"
               step="0.001" min="0.001"
               data-tpl-idx="1"
               required>

        <input type="hidden"
               id="edit_pid___IDX__"
               name="products[__IDX__][product_id]"
               data-tpl-idx="1">

        <button type="button"
                class="btn btn-outline-danger product-picker-remove"
                title="Удалить">
            <i class="bi bi-x-lg"></i>
        </button>

        <div class="modal fade" id="edit_modal___IDX__" tabindex="-1" data-tpl-idx="1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Выбрать из каталога</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" style="max-height:70vh;overflow-y:auto">
                        <input type="text" class="form-control mb-3 tree-search-input"
                               placeholder="Поиск по каталогу...">
                        <div class="product-tree-container"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
