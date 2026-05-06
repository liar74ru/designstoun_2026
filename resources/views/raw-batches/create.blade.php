@extends('layouts.app')

@section('title', 'Новая партия сырья')

@section('content')
    @php
        $fromStoreDefault = old('from_store_id', request('copy_from_store'))
            ?: (($defaultFromStore ?? null)?->id
                ?? $stores->first(fn($s) => mb_stripos($s->name, 'сырь') !== false)?->id
                ?? '');
        $toStoreDefault = old('to_store_id', request('copy_to_store'))
            ?: (($defaultToStore ?? null)?->id
                ?? $stores->first(fn($s) => mb_stripos($s->name, 'цех') !== false)?->id
                ?? '');
        $userDeptId = auth()->user()?->worker?->department_id;
    @endphp
    <div class="container py-3 py-md-4">

        <x-page-header
            title="➕ Новая партия сырья"
            back-url="{{ route('raw-batches.index') }}"
            back-label="К списку"
        />

        @include('partials.alerts')

        <div class="row g-3">

            {{-- ═══════════════════════ ФОРМА ═══════════════════════ --}}
            <div class="col-12 col-lg-7">
                <div class="card shadow-sm">
                    <div class="card-body p-0">
                        <style>
                            #batchForm .form-control,
                            #batchForm .form-select { border-radius: .4rem; }
                        </style>
                        <form method="POST" action="{{ route('raw-batches.store') }}" id="batchForm">
                            @csrf

                            @if($errors->any())
                                <div class="alert alert-danger py-2 m-2">
                                    @foreach($errors->all() as $error)
                                        <div class="small">{{ $error }}</div>
                                    @endforeach
                                </div>
                            @endif

                            {{-- Пильщик --}}
                            <div class="info-block">
                                <div class="info-block-header d-flex justify-content-between align-items-center">
                                    <span class="small fw-semibold text-muted">
                                        Закрепить за пильщиком <span class="text-danger">*</span>
                                    </span>
                                    @if($userDeptId)
                                        <div class="form-check form-check-inline mb-0">
                                            <input class="form-check-input" type="checkbox" id="allWorkersWorker">
                                            <label class="form-check-label small text-muted" for="allWorkersWorker">все работники</label>
                                        </div>
                                    @endif
                                </div>
                                <div class="info-block-body">
                                    <select name="worker_id"
                                            id="workerSelect"
                                            class="form-select worker-picker @error('worker_id') is-invalid @enderror"
                                            data-user-dept-id="{{ $userDeptId }}"
                                            data-toggle-id="allWorkersWorker"
                                            required>
                                        <option value="">— Выберите пильщика —</option>
                                        @foreach($workers as $worker)
                                            <option value="{{ $worker->id }}"
                                                data-department-id="{{ $worker->department_id }}"
                                                {{ old('worker_id', request('copy_worker')) == $worker->id ? 'selected' : '' }}>
                                                {{ $worker->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('worker_id')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            {{-- Сырьё --}}
                            <div class="info-block">
                                <div class="info-block-header d-flex justify-content-between align-items-center">
                                    <span class="small fw-semibold text-muted">
                                        Сырьё <span class="text-danger">*</span>
                                    </span>
                                    <div class="form-check form-check-inline mb-0" id="allCatalogWrap">
                                        <input class="form-check-input" type="checkbox" id="allCatalogCheck">
                                        <label class="form-check-label small text-muted" for="allCatalogCheck">весь каталог</label>
                                    </div>
                                </div>
                                <div class="info-block-body">
                                    @include('partials.product-picker', [
                                        'id'          => 'raw_product',
                                        'name'        => 'product_id',
                                        'value'       => old('product_id', request('copy_product')),
                                        'label'       => old('product_name', $copyProductName ?? ''),
                                        'placeholder' => 'Начните вводить название сырья...',
                                        'skuPrefix'   => '01-',
                                        'showTree'    => true,
                                        'required'    => true,
                                    ])
                                    @error('product_id')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            {{-- Количество --}}
                            <div class="info-block">
                                <div class="info-block-header d-flex justify-content-between align-items-center">
                                    <span class="small fw-semibold text-muted">
                                        Количество (м³) <span class="text-danger">*</span>
                                    </span>
                                </div>
                                <div class="info-block-body">
                                    <input type="number" step="0.1" min="0.1" name="quantity"
                                           class="form-control @error('quantity') is-invalid @enderror"
                                           value="{{ old('quantity') ? old('quantity') : 1 }}" required>
                                    @error('quantity')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            {{-- Номер партии и склады (скрытый блок) --}}
                            <div class="info-block">
                                <div class="info-block-header d-flex justify-content-between align-items-center"
                                     id="extraToggle" style="cursor:pointer" role="button">
                                    <span class="small fw-semibold text-muted">Номер партии и склады</span>
                                    <i class="bi bi-chevron-down" id="extraChevron"></i>
                                </div>
                                <div id="extraBody" style="display:none">
                                    <div class="info-block-body">
                                        <div class="row g-2">

                                            {{-- Номер партии --}}
                                            <div class="col-12">
                                                <div class="d-flex align-items-center justify-content-between mb-1">
                                                    <label class="form-label small fw-semibold mb-0">Номер партии</label>
                                                    <div class="form-check form-switch mb-0">
                                                        <input class="form-check-input" type="checkbox"
                                                               id="manualBatchNumber" role="switch">
                                                        <label class="form-check-label small text-muted" for="manualBatchNumber">
                                                            Вручную
                                                        </label>
                                                    </div>
                                                </div>
                                                <input type="text"
                                                       id="batchNumberInput"
                                                       name="batch_number"
                                                       class="form-control form-control-sm @error('batch_number') is-invalid @enderror"
                                                       value="{{ old('batch_number') }}"
                                                       placeholder="Выберите пильщика для автогенерации..."
                                                       readonly>
                                                <div class="form-text text-muted small" id="batchNumberHint">
                                                    Номер сформируется автоматически после выбора пильщика
                                                </div>
                                                @error('batch_number')
                                                    <div class="invalid-feedback">{{ $message }}</div>
                                                @enderror
                                            </div>

                                            {{-- Склад-источник --}}
                                            <div class="col-12">
                                                <label class="form-label small fw-semibold mb-1">
                                                    Склад-источник <span class="text-danger">*</span>
                                                </label>
                                                <select name="from_store_id"
                                                        class="form-select form-select-sm @error('from_store_id') is-invalid @enderror"
                                                        style="font-size:.8rem;padding:.18rem .35rem;border-radius:.4rem"
                                                        required>
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
                                            <div class="col-12">
                                                <label class="form-label small fw-semibold mb-1">
                                                    Склад-назначение (цех) <span class="text-danger">*</span>
                                                </label>
                                                <select name="to_store_id"
                                                        class="form-select form-select-sm @error('to_store_id') is-invalid @enderror"
                                                        style="font-size:.8rem;padding:.18rem .35rem;border-radius:.4rem"
                                                        required>
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

                                        </div>
                                    </div>
                                </div>
                            </div>

                            <x-admin-date-field />

                            @php
                                $canIgnoreStock = auth()->user()?->isAdmin() || auth()->user()?->isMaster();
                            @endphp
                            @if($canIgnoreStock)
                                <div class="mb-3 mx-2 p-3 border border-warning rounded bg-warning bg-opacity-10">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox"
                                               name="ignore_stock_check" value="1" id="ignoreStockCheck"
                                               {{ old('ignore_stock_check') ? 'checked' : '' }}>
                                        <label class="form-check-label fw-semibold text-warning-emphasis" for="ignoreStockCheck">
                                            <i class="bi bi-exclamation-triangle"></i> Игнорировать остаток на складе
                                            <span class="badge bg-warning text-dark ms-1" style="font-size:.7rem">
                                                Только для админа и мастера
                                            </span>
                                        </label>
                                        <div class="form-text">
                                            Создать партию даже если остатка на складе-источнике недостаточно. Остаток уйдёт в минус.
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <input type="hidden" name="and_reception" id="andReceptionInput" value="">
                            <div class="p-2 d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i> Создать партию
                                </button>
                                <button type="submit" id="andReceptionBtn" class="btn btn-success">
                                    <i class="bi bi-save"></i> Создать + приёмку
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
                                        'worker_id'     => $batch->currentWorker?->id ?? '',
                                    ], JSON_UNESCAPED_UNICODE);
                                @endphp
                                <div class="list-group-item px-2 py-2"
                                     style="border-left:4px solid {{ $skuColor }};border-right:4px solid {{ $skuColor }};{{ $skuBg }}">
                                    <div class="d-flex align-items-start gap-2" style="min-width:0">
                                        <div class="flex-grow-1" style="min-width:0">
                                            <div class="small fw-semibold text-truncate" title="{{ $batch->product?->name }}">
                                                {{ $batch->product?->name ?? '—' }}
                                            </div>
                                            <div class="d-flex gap-2 mt-1" style="font-size:.75rem">
                                                <span class="text-muted">
                                                    <i class="bi bi-box me-1"></i>{{ number_format($batch->initial_quantity, 2) }} м³
                                                </span>
                                                @if($batch->currentWorker)
                                                    <span class="text-muted text-truncate">
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

            // ── Блок «Номер партии и склады» (скрыт по умолчанию) ───────────────────
            (function () {
                const toggle  = document.getElementById('extraToggle');
                const body    = document.getElementById('extraBody');
                const chevron = document.getElementById('extraChevron');
                if (!toggle) return;
                @if($errors->has('batch_number') || $errors->has('from_store_id') || $errors->has('to_store_id'))
                    body.style.display = '';
                    chevron.className  = 'bi bi-chevron-up';
                @endif
                toggle.addEventListener('click', function () {
                    const isHidden = body.style.display === 'none';
                    body.style.display = isHidden ? '' : 'none';
                    chevron.className  = isHidden ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
                });
            })();

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

                    // Пильщик — только если ещё не выбран
                    if (data.worker_id && workerSelect && !workerSelect.value) {
                        workerSelect.value = data.worker_id;
                        fetchBatchNumber(data.worker_id);
                    }

                    // Продукт
                    const pidInput    = row.querySelector('input[type="hidden"][name="product_id"]');
                    const searchInput = row.querySelector('.product-picker-search');
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
    @vite(['resources/js/product-picker.js', 'resources/js/worker-picker.js'])
@endpush
