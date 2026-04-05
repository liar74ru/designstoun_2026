@extends('layouts.app')

@section('title', 'Новая партия сырья')

@section('content')
    @php
        $fromStoreDefault = old('from_store_id', request('copy_from_store'))
            ?: ($stores->first(fn($s) => mb_stripos($s->name, 'сырь') !== false)?->id ?? '');
        $toStoreDefault = old('to_store_id', request('copy_to_store'))
            ?: ($stores->first(fn($s) => mb_stripos($s->name, 'цех') !== false)?->id ?? '');
    @endphp
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2 mb-0">➕ Новая партия сырья</h1>
            <a href="{{ route('raw-batches.index') }}" class="btn btn-outline-secondary text-nowrap">
                <i class="bi bi-arrow-left"></i> К списку
            </a>
        </div>

        <div class="row g-3">

            {{-- ═══════════════════════ ФОРМА ═══════════════════════ --}}
            <div class="col-12 col-lg-7">
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

                            {{-- Продукт --}}
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
                                                   value="{{ old('product_name', $copyProductName ?? '') }}"
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
                                           value="{{ old('product_id', request('copy_product')) }}"
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
                                            {{ old('worker_id', request('copy_worker')) == $worker->id ? 'selected' : '' }}>
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

                            <input type="hidden" name="and_reception" id="andReceptionInput" value="">
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i> Создать партию
                                </button>
                                <button type="submit" id="andReceptionBtn" class="btn btn-success">
                                    <i class="bi bi-save"></i> Создать партию + приёмку
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            {{-- ═══════════════════════ ПОСЛЕДНИЕ ПАРТИИ ═══════════════════════ --}}
            <div class="col-12 col-lg-5">
                <div class="card shadow-sm">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center py-2"
                         id="recentBatchesToggle" style="cursor:pointer" role="button">
                        <span class="fw-semibold small">
                            <i class="bi bi-clock-history me-1"></i> Последние партии
                        </span>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-secondary">{{ $recentBatches->count() }}</span>
                            <i class="bi bi-chevron-down d-md-none" id="recentBatchesChevron"></i>
                        </div>
                    </div>

                    <div id="recentBatchesBody">
                        <div class="list-group list-group-flush">
                            @forelse($recentBatches as $batch)
                                @php
                                    $createMovement = $batch->movements->first();
                                    $skuColor = \App\Models\Product::getColorBySku($batch->product?->sku ?? null);
                                    $skuBg    = $skuColor === '#FFFFFF' ? '' : 'background:' . $skuColor . '18;';
                                    $copyData = json_encode([
                                        'product_id'    => $batch->product_id,
                                        'product_name'  => $batch->product?->name ?? '',
                                        'quantity'      => (float) $batch->initial_quantity,
                                        'from_store_id' => $createMovement?->from_store_id ?? '',
                                        'to_store_id'   => $createMovement?->to_store_id ?? '',
                                    ], JSON_UNESCAPED_UNICODE);
                                @endphp
                                <div class="list-group-item px-2 py-2" style="border-left:4px solid {{ $skuColor }};border-right:4px solid {{ $skuColor }};{{ $skuBg }}">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1 me-2">
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <span class="badge {{ $batch->statusBadgeClass() }}" style="font-size:.65rem">
                                                    {{ $batch->statusLabel() }}
                                                </span>
                                                @if($batch->batch_number)
                                                    <span class="fw-semibold small text-muted">№{{ $batch->batch_number }}</span>
                                                @endif
                                                <span class="text-muted" style="font-size:.72rem">
                                                    {{ $batch->created_at->format('d.m H:i') }}
                                                </span>
                                            </div>
                                            <div class="small fw-semibold text-truncate" style="max-width:180px" title="{{ $batch->product?->name }}">
                                                {{ $batch->product?->name ?? '—' }}
                                            </div>
                                            <div class="d-flex gap-2 mt-1" style="font-size:.75rem">
                                                <span class="text-muted">
                                                    <i class="bi bi-box me-1"></i>{{ number_format($batch->initial_quantity, 2) }} м³
                                                </span>
                                                @if($batch->currentWorker)
                                                    <span class="text-muted">
                                                        <i class="bi bi-hammer me-1"></i>{{ $batch->currentWorker->name }}
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-secondary copy-batch-btn flex-shrink-0"
                                                data-batch="{{ $copyData }}"
                                                style="width:28px;height:28px;padding:0;font-size:.75rem"
                                                title="Скопировать в форму">
                                            <i class="bi bi-copy"></i>
                                        </button>
                                    </div>
                                </div>
                            @empty
                                <div class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                                    Нет партий
                                </div>
                            @endforelse
                        </div>
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
            const toStoreSelect   = document.querySelector('select[name="to_store_id"]');

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
                    batchInput.readOnly   = false;
                    batchInput.classList.add('border-warning');
                    batchHint.textContent = 'Введите номер партии вручную';
                } else {
                    batchInput.readOnly   = true;
                    batchInput.classList.remove('border-warning');
                    fetchBatchNumber(workerSelect?.value);
                }
            });

            // Если пильщик уже выбран (например, после копирования) — сразу генерируем
            if (workerSelect?.value) {
                fetchBatchNumber(workerSelect.value);
            }

            // Кнопка "Создать партию + приёмку"
            document.getElementById('andReceptionBtn')?.addEventListener('click', function () {
                document.getElementById('andReceptionInput').value = '1';
            });

            // ── Сворачивание панели на мобильном ─────────────────────────────────────
            (function () {
                const toggle  = document.getElementById('recentBatchesToggle');
                const body    = document.getElementById('recentBatchesBody');
                const chevron = document.getElementById('recentBatchesChevron');
                const STORAGE_KEY = 'recent_batches_panel_open';

                function isMobile() { return window.innerWidth < 992; }

                function applyState(open) {
                    if (!isMobile()) { body.style.display = ''; return; }
                    body.style.display = open ? '' : 'none';
                    if (chevron) chevron.className = open ? 'bi bi-chevron-up d-md-none' : 'bi bi-chevron-down d-md-none';
                }

                applyState(localStorage.getItem(STORAGE_KEY) === 'open');

                toggle.addEventListener('click', function () {
                    if (!isMobile()) return;
                    const isHidden = body.style.display === 'none';
                    applyState(isHidden);
                    localStorage.setItem(STORAGE_KEY, isHidden ? 'open' : 'closed');
                });

                window.addEventListener('resize', () => applyState(localStorage.getItem(STORAGE_KEY) === 'open'));
            })();

            // ── Копирование данных из партии в форму ─────────────────────────────────
            document.addEventListener('click', function (e) {
                const btn = e.target.closest('.copy-batch-btn');
                if (!btn) return;
                try {
                    const data = JSON.parse(btn.dataset.batch || '{}');
                    if (!data.product_id) return;

                    // Продукт
                    const pidInput    = document.getElementById('pid_0');
                    const searchInput = document.getElementById('search_0');
                    if (pidInput)    pidInput.value    = data.product_id;
                    if (searchInput) searchInput.value = data.product_name;

                    // Количество
                    const qtyInput = document.querySelector('input[name="quantity"]');
                    if (qtyInput && data.quantity) qtyInput.value = data.quantity;

                    // Склад-источник
                    if (fromStoreSelect && data.from_store_id) {
                        fromStoreSelect.value = data.from_store_id;
                        syncSourceStore();
                    }

                    // Склад-назначение
                    if (toStoreSelect && data.to_store_id) {
                        toStoreSelect.value = data.to_store_id;
                    }

                    window.scrollTo({ top: 0, behavior: 'smooth' });
                } catch (err) {
                    console.error('copy-batch-btn parse error', err);
                }
            });
        });
    </script>
    @vite(['resources/js/product-picker.js'])
@endpush
