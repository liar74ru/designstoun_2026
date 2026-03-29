@extends('layouts.app')

@section('title', 'Новая партия сырья')

@section('content')
    @php
        $fromStoreDefault = old('from_store_id', session('copy_from.from_store_id'))
            ?: ($stores->first(fn($s) => mb_stripos($s->name, 'сырь') !== false)?->id ?? '');
        $toStoreDefault = old('to_store_id', session('copy_from.to_store_id'))
            ?: ($stores->first(fn($s) => mb_stripos($s->name, 'цех') !== false)?->id ?? '');
    @endphp
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2 mb-0">➕ Новая партия сырья</h1>
            <a href="{{ route('raw-batches.index') }}" class="btn btn-outline-secondary text-nowrap">
                <i class="bi bi-arrow-left"></i> К списку
            </a>
        </div>

        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <style>
                            #batchForm .form-control,
                            #batchForm .form-select { border-radius: .4rem; }
                        </style>
                        <form method="POST" action="{{ route('raw-movement.store') }}" id="batchForm">
                            @csrf

                            @if($errors->any())
                                <div class="alert alert-danger py-2">
                                    @foreach($errors->all() as $error)
                                        <div class="small">{{ $error }}</div>
                                    @endforeach
                                </div>
                            @endif

                            {{-- Продукт — новый пикер вместо x-product-search --}}
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label fw-semibold mb-0">
                                        Сырьё <span class="text-danger">*</span>
                                    </label>
                                    <div class="form-check form-check-inline mb-0" id="allCatalogWrap">
                                        <input class="form-check-input" type="checkbox" id="allCatalogCheck">
                                        <label class="form-check-label small text-muted" for="allCatalogCheck">весь каталог</label>
                                    </div>
                                </div>
                                <div class="product-picker-row" data-sku-prefix="01-" data-tpl-index="0">
                                    <div class="flex-grow-1 position-relative">
                                        <div class="input-group">
                                            <input type="text"
                                                   id="search_0"
                                                   class="form-control product-picker-search"
                                                   placeholder="Начните вводить название сырья..."
                                                   autocomplete="off"
                                                   data-hidden-id="pid_0"
                                                   value="{{ old('product_name', session('copy_from.product_name')) }}"
                                                   required>
                                            <button type="button"
                                                    class="btn btn-outline-secondary product-picker-tree-btn"
                                                    data-modal="modal_0"
                                                    data-hidden-id="pid_0"
                                                    data-search-id="search_0"
                                                    title="Выбрать из каталога">
                                                <i class="bi bi-diagram-3"></i>
                                            </button>
                                        </div>
                                        <div class="product-picker-dropdown list-group shadow-sm"
                                             id="drop_0"
                                             style="display:none;position:absolute;z-index:1000;width:100%;max-height:280px;overflow-y:auto">
                                        </div>
                                    </div>
                                    <input type="hidden"
                                           id="pid_0"
                                           name="product_id"
                                           value="{{ old('product_id', session('copy_from.product_id')) }}"
                                           required>

                                    {{-- Модальное окно дерева --}}
                                    <div class="modal fade" id="modal_0" tabindex="-1">
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
                                @error('product_id')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>

                            {{-- Количество --}}
                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    Количество (м³) <span class="text-danger">*</span>
                                </label>
                                <input type="number" step="0.1" min="0.1" name="quantity"
                                       class="form-control @error('quantity') is-invalid @enderror"
                                       value="{{ old('quantity') ? old('quantity') : 1 }}" required>
                                @error('quantity')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            {{-- Номер партии --}}
                            <div class="mb-3">
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <label class="form-label fw-semibold mb-0">Номер партии</label>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox"
                                               id="manualBatchNumber" role="switch">
                                        <label class="form-check-label small text-muted" for="manualBatchNumber">
                                            Задать вручную
                                        </label>
                                    </div>
                                </div>
                                <input type="text"
                                       id="batchNumberInput"
                                       name="batch_number"
                                       class="form-control @error('batch_number') is-invalid @enderror"
                                       value="{{ old('batch_number') }}"
                                       placeholder="Выберите пильщика для автогенерации..."
                                       readonly>
                                <div class="form-text text-muted" id="batchNumberHint">
                                    Номер сформируется автоматически после выбора пильщика
                                </div>
                                @error('batch_number')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            {{-- Склад-источник --}}
                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    Склад-источник <span class="text-danger">*</span>
                                </label>
                                <select name="from_store_id"
                                        class="form-select @error('from_store_id') is-invalid @enderror" required>
                                    <option value="">— Выберите склад —</option>
                                    @foreach($stores as $store)
                                        <option value="{{ $store->id }}"
                                            {{ $fromStoreDefault == $store->id ? 'selected' : '' }}>
                                            {{ $store->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('from_store_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            {{-- Склад-назначение --}}
                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    Склад-назначение (цех) <span class="text-danger">*</span>
                                </label>
                                <select name="to_store_id"
                                        class="form-select @error('to_store_id') is-invalid @enderror" required>
                                    <option value="">— Выберите склад —</option>
                                    @foreach($stores as $store)
                                        <option value="{{ $store->id }}"
                                            {{ $toStoreDefault == $store->id ? 'selected' : '' }}>
                                            {{ $store->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('to_store_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            {{-- Пильщик --}}
                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    Закрепить за пильщиком <span class="text-danger">*</span>
                                </label>
                                <select name="worker_id"
                                        id="workerSelect"
                                        class="form-select @error('worker_id') is-invalid @enderror" required>
                                    <option value="">— Выберите пильщика —</option>
                                    @foreach($workers as $worker)
                                        <option value="{{ $worker->id }}"
                                            {{ old('worker_id', session('copy_from.worker_id')) == $worker->id ? 'selected' : '' }}>
                                            {{ $worker->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('worker_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            @if(auth()->user()?->isAdmin())
                                {{-- Поле для администратора: ручная дата создания --}}
                                <div class="mb-3 p-3 border border-warning rounded bg-warning bg-opacity-10">
                                    <label class="form-label fw-semibold text-warning-emphasis">
                                        <i class="bi bi-calendar-event"></i> Дата создания
                                        <span class="badge bg-warning text-dark ms-1" style="font-size:.7rem">Только для админа</span>
                                    </label>
                                    <input type="datetime-local"
                                           name="manual_created_at"
                                           class="form-control"
                                           value="{{ old('manual_created_at') }}">
                                    <div class="form-text">Оставьте пустым — дата установится автоматически</div>
                                </div>
                            @endif

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

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const row             = document.querySelector('.product-picker-row');
            const workerSelect    = document.getElementById('workerSelect');
            const batchInput      = document.getElementById('batchNumberInput');
            const manualCheckbox  = document.getElementById('manualBatchNumber');
            const batchHint       = document.getElementById('batchNumberHint');
            const allCatalogCheck = document.getElementById('allCatalogCheck');

            const RAW_SKU_PREFIX  = '01-';
            const fromStoreSelect = document.querySelector('select[name="from_store_id"]');

            // Инициализируем пикер продукта
            if (row && window.ProductPicker) {
                window.ProductPicker.initRow(row);
            }

            // Передаём склад-источник в строку пикера для отображения остатков
            function syncSourceStore() {
                if (fromStoreSelect?.value) {
                    row.dataset.sourceStoreId = fromStoreSelect.value;
                } else {
                    delete row.dataset.sourceStoreId;
                }
            }
            fromStoreSelect?.addEventListener('change', syncSourceStore);
            syncSourceStore();

            // Загружаем карту остатков
            fetch('/api/products/stocks', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(data => { window.ProductPickerStockMap = data; });

            // Чекбокс "весь каталог"
            allCatalogCheck?.addEventListener('change', function () {
                if (this.checked) {
                    delete row.dataset.skuPrefix;
                } else {
                    row.dataset.skuPrefix = RAW_SKU_PREFIX;
                }
            });

            // ── Автогенерация номера партии ──────────────────────────────────────────

            async function fetchBatchNumber(workerId) {
                if (!workerId) return;
                try {
                    const res  = await fetch(`/api/workers/${workerId}/next-batch-number`, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    const data = await res.json();
                    batchInput.value       = data.batch_number;
                    batchInput.placeholder = data.batch_number;
                    batchHint.textContent  = `Автономер: ${data.batch_number}`;
                } catch (e) {
                    console.error('Ошибка получения номера партии', e);
                }
            }

            // При смене пильщика — запрашиваем новый номер (если не ручной режим)
            workerSelect?.addEventListener('change', function () {
                if (!manualCheckbox.checked) {
                    fetchBatchNumber(this.value);
                }
            });

            // Переключение чекбокса "Задать вручную"
            manualCheckbox?.addEventListener('change', function () {
                if (this.checked) {
                    // Ручной режим — разблокируем поле
                    batchInput.readOnly   = false;
                    batchInput.classList.add('border-warning');
                    batchHint.textContent = 'Введите номер партии вручную';
                } else {
                    // Авто режим — блокируем и обновляем из API
                    batchInput.readOnly   = true;
                    batchInput.classList.remove('border-warning');
                    fetchBatchNumber(workerSelect?.value);
                }
            });

            // Если пильщик уже выбран (например, после копирования) — сразу генерируем
            if (workerSelect?.value) {
                fetchBatchNumber(workerSelect.value);
            }
        });
    </script>
    @vite(['resources/js/product-picker.js'])
@endpush
