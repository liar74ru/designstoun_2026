@extends('layouts.app')

@section('title', 'Новая приёмка')

@section('content')
    <div class="container py-3 py-md-4">

        <x-page-header
            title="➕ Новая приёмка"
            back-url="{{ route('stone-receptions.logs') }}"
        />

        @include('partials.alerts')

        <div class="row g-3">

            {{-- ═══════════════════════ ФОРМА ═══════════════════════ --}}
            <div class="col-12 col-lg-7">
                <div class="card shadow-sm">
                    <div class="info-block-body">
                        <form method="POST" action="{{ route('stone-receptions.store') }}" id="receptionForm">
                            @csrf

                            {{-- Ошибки --}}
                            @if($errors->any())
                                <div class="alert alert-danger py-2 mb-2">
                                    @foreach($errors->all() as $error)
                                        <div class="small">{{ $error }}</div>
                                    @endforeach
                                </div>
                            @endif

                            {{-- Блок 1: Участники --}}
                            <div class="info-block">
                                <div class="info-block-header">
                                    <span class="small fw-semibold text-muted">Участники</span>
                                </div>
                                <div class="info-block-body">
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <label class="form-label small fw-semibold mb-1">
                                                Пильщик <span class="text-danger">*</span>
                                            </label>
                                            <select name="cutter_id" id="cutterSelect"
                                                    class="form-select form-select-sm @error('cutter_id') is-invalid @enderror"
                                                    style="font-size:.8rem;padding:.18rem .35rem;border-radius:.4rem">
                                                <option value="">— пильщик —</option>
                                                @foreach($workers as $worker)
                                                    <option value="{{ $worker->id }}"
                                                        {{ old('cutter_id', request('cutter_id')) == $worker->id ? 'selected' : '' }}>
                                                        {{ $worker->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            @error('cutter_id')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small fw-semibold mb-1">
                                                Приёмщик <span class="text-danger">*</span>
                                            </label>
                                            <select name="receiver_id"
                                                    class="form-select form-select-sm @error('receiver_id') is-invalid @enderror" required
                                                    style="font-size:.8rem;padding:.18rem .35rem;border-radius:.4rem">
                                                <option value="">— приёмщик —</option>
                                                @foreach($masterWorkers as $worker)
                                                    <option value="{{ $worker->id }}"
                                                        {{ old('receiver_id', auth()->user()->worker_id) == $worker->id ? 'selected' : '' }}>
                                                        {{ $worker->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            @error('receiver_id')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Блок 2: Партия сырья --}}
                            <div class="info-block">
                                <div class="info-block-header">
                                    <span class="small fw-semibold text-muted">Партия сырья</span>
                                </div>
                                <div class="info-block-body">
                                    <div class="row g-2">
                                        <div class="col-8 col-sm-9">
                                            <label class="form-label small fw-semibold mb-1">
                                                Партия <span class="text-danger">*</span>
                                            </label>
                                            <select name="raw_material_batch_id" id="batchSelect"
                                                    class="form-select form-select-sm @error('raw_material_batch_id') is-invalid @enderror"
                                                    required
                                                    style="font-size:.8rem;padding:.18rem .35rem;border-radius:.4rem">
                                                <option value="">
                                                    {{ request('cutter_id') ? '— Выберите партию —' : '— Выберите пильщика —' }}
                                                </option>
                                                @foreach($filteredBatches as $batch)
                                                    <option value="{{ $batch->id }}"
                                                            data-remaining="{{ $batch->remaining_quantity }}"
                                                            data-product-sku="{{ $batch->product->sku ?? '' }}"
                                                            data-status="{{ $batch->status }}"
                                                        {{ old('raw_material_batch_id', request('raw_material_batch_id')) == $batch->id ? 'selected' : '' }}>
                                                        @if((float)$batch->remaining_quantity <= 0) ⚠ @endif{{ $batch->product->name }}
                                                        (ост: {{ number_format($batch->remaining_quantity, 2) }} м³)
                                                        @if($batch->batch_number) №{{ $batch->batch_number }} @endif
                                                    </option>
                                                @endforeach
                                            </select>
                                            @error('raw_material_batch_id')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                            {{-- Блок: активная приёмка уже существует --}}
                                            <div id="activeReceptionAlert" class="mt-1 p-1 rounded bg-info bg-opacity-10 border border-info small" style="display:none">
                                                <i class="bi bi-info-circle me-1 text-info"></i>
                                                У партии есть активная приёмка.
                                                <a id="activeReceptionLink" href="#" class="fw-semibold">Перейти к ней</a>
                                            </div>
                                            {{-- Блок: партия с нулевым остатком --}}
                                            <div id="markUsedBlock" class="mt-1" style="display:none">
                                                <button type="button" id="markUsedBtnCreate" class="btn btn-sm btn-warning w-100">
                                                    <i class="bi bi-check2-circle"></i> Израсходована
                                                </button>
                                            </div>
                                            <div class="mt-1">
                                                <a id="createBatchLink"
                                                   href="{{ route('raw-batches.create') }}"
                                                   class="btn btn-sm btn-outline-success w-100">
                                                    <i class="bi bi-plus-circle"></i> Создать партию
                                                </a>
                                            </div>
                                        </div>
                                        <div class="col-4 col-sm-3">
                                            <label class="form-label small fw-semibold mb-1">
                                                Расход <span class="text-muted fw-normal">(м³)</span> <span class="text-danger">*</span>
                                            </label>
                                            <input type="number" step="0.001" min="0.001"
                                                   name="raw_quantity_used" id="rawQtyInput"
                                                   class="form-control form-control-sm @error('raw_quantity_used') is-invalid @enderror"
                                                   value="{{ old('raw_quantity_used', 0) }}" required>
                                            <div class="form-text" id="remainingInfo" style="font-size:.7rem"></div>
                                            @error('raw_quantity_used')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Склад (скрытый) --}}
                            <input type="hidden" name="store_id" value="{{ $defaultStore?->id }}">

                            {{-- Блок 3: Продукция --}}
                            <div class="info-block">
                                <div class="info-block-header d-flex justify-content-between align-items-center">
                                    <span class="small fw-semibold text-muted">Продукция <span class="text-danger">*</span></span>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="form-check form-check-inline mb-0" id="allCatalogWrap" style="display:none">
                                            <input class="form-check-input" type="checkbox" id="allCatalogCheck">
                                            <label class="form-check-label small text-muted" for="allCatalogCheck">весь каталог</label>
                                        </div>
                                        <span class="text-muted small">Итого: <strong id="totalQty">0</strong> м²</span>
                                    </div>
                                </div>
                                <div class="info-block-body">
                                    <div id="productsContainer" style="margin-bottom:.25rem"></div>
                                    <button type="button" class="btn btn-sm btn-outline-primary mt-1"
                                            id="addProductBtn">
                                        <i class="bi bi-plus-circle"></i> Добавить продукт
                                    </button>
                                </div>
                            </div>

                            {{-- Блок 4: Примечание --}}
                            <div class="info-block">
                                <div class="info-block-header">
                                    <span class="small fw-semibold text-muted">Примечание</span>
                                </div>
                                <div class="info-block-body">
                                    <textarea name="notes" class="form-control form-control-sm" rows="2"
                                    >{{ old('notes') }}</textarea>
                                </div>
                            </div>

                            {{-- Блок 5: Техоперация МойСклад --}}
                            <div class="info-block">
                                <div class="info-block-header d-flex justify-content-between align-items-center"
                                     id="processingToggle" style="cursor:pointer" role="button">
                                    <span class="small fw-semibold text-muted">Техоперация МойСклад</span>
                                    <i class="bi bi-chevron-down" id="processingChevron"></i>
                                </div>
                                <div id="processingBody" style="display:none">
                                    <div class="info-block-body">
                                        <div class="d-flex align-items-center justify-content-between mb-1">
                                            <label class="form-label small fw-semibold mb-0">Имя техоперации</label>
                                            <div class="form-check form-switch mb-0">
                                                <input class="form-check-input" type="checkbox"
                                                       id="manualProcessingName" role="switch">
                                                <label class="form-check-label small text-muted" for="manualProcessingName">
                                                    Вручную
                                                </label>
                                            </div>
                                        </div>
                                        <input type="text"
                                               id="processingNameInput"
                                               name="processing_name"
                                               class="form-control form-control-sm @error('processing_name') is-invalid @enderror"
                                               value="{{ old('processing_name') }}"
                                               placeholder="Сформируется автоматически..."
                                               readonly>
                                        <div class="form-text text-muted small">
                                            Имя сформируется автоматически в формате ГГ-НН-ТО-ПП
                                        </div>
                                        @error('processing_name')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <x-admin-date-field />

                            <input type="hidden" name="close_batch" value="0" id="closeBatchInput">
                            <div class="d-flex gap-2 flex-wrap mt-1">
                                <button type="submit" class="btn btn-primary btn-sm flex-fill">
                                    <i class="bi bi-save"></i> Сохранить приёмку
                                </button>
                                <button type="button" class="btn btn-warning btn-sm flex-fill" id="saveCloseBatchBtn"
                                        title="Доступно когда остаток партии равен нулю" disabled>
                                    <i class="bi bi-check2-circle"></i> Сохранить + Закрыть партию
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            {{-- ═══════════════════════ ПОСЛЕДНИЕ ПРИЁМКИ ═══════════════════════ --}}
            <div class="col-12 col-lg-5">
                <div class="card shadow-sm">
                    {{-- Заголовок — на мобильном кликабельный для сворачивания --}}
                    <div class="card-header bg-white d-flex justify-content-between align-items-center py-2"
                         id="lastReceptionsToggle" style="cursor:pointer" role="button">
                        <span class="fw-semibold small">
                            <i class="bi bi-clock-history me-1"></i> Последние приёмки
                        </span>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-secondary">{{ $lastReceptions->total() }}</span>
                            <i class="bi bi-chevron-down d-md-none" id="lastReceptionsChevron"></i>
                        </div>
                    </div>

                    <div id="lastReceptionsBody">
                        <div class="list-group list-group-flush">
                            @forelse($lastReceptions as $reception)
                                <div class="list-group-item px-2 py-2">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1 me-2">
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <span class="fw-semibold small">#{{ $reception->id }}</span>
                                                <span class="badge bg-primary bg-opacity-10 text-primary" style="font-size:.7rem">
                                                    {{ number_format($reception->total_quantity, 2) }} м²
                                                </span>
                                                <span class="text-muted" style="font-size:.72rem">
                                                    {{ $reception->created_at->format('d.m H:i') }}
                                                </span>
                                            </div>
                                            @foreach($reception->items as $item)
                                                <div class="text-muted" style="font-size:.75rem">
                                                    {{ $item->product->name }}
                                                    <span class="text-dark">× {{ number_format($item->quantity, 2) }}</span>
                                                </div>
                                            @endforeach
                                            @if($reception->cutter)
                                                <div class="text-muted mt-1" style="font-size:.72rem">
                                                    <i class="bi bi-hammer me-1"></i>{{ $reception->cutter->name }}
                                                </div>
                                            @endif
                                        </div>
                                        @php
                                            $copyItemsData = $reception->items->map(fn($item) => [
                                                'product_id'    => $item->product_id,
                                                'product_label' => $item->product?->name ?? '',
                                                'is_undercut'   => (bool) $item->is_undercut,
                                            ])->toJson(JSON_UNESCAPED_UNICODE);
                                        @endphp
                                        <button type="button"
                                                class="btn btn-sm btn-outline-secondary copy-reception-btn flex-shrink-0"
                                                data-items="{{ $copyItemsData }}"
                                                style="width:28px;height:28px;padding:0;font-size:.75rem"
                                                title="Скопировать продукты">
                                            <i class="bi bi-copy"></i>
                                        </button>
                                    </div>
                                </div>
                            @empty
                                <div class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                                    Нет приёмок
                                </div>
                            @endforelse
                        </div>

                        @if($lastReceptions->hasPages())
                            <div class="card-footer py-2">
                                {{ $lastReceptions->links('pagination::bootstrap-5') }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>

        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {

            // ── Последние приёмки: сворачивание на мобильном ────────────────────────
            (function () {
                const toggle  = document.getElementById('lastReceptionsToggle');
                const body    = document.getElementById('lastReceptionsBody');
                const chevron = document.getElementById('lastReceptionsChevron');
                const STORAGE_KEY = 'last_receptions_open';

                function isMobile() { return window.innerWidth < 768; }

                function applyState(open) {
                    if (!isMobile()) { body.style.display = ''; return; }
                    body.style.display = open ? '' : 'none';
                    if (chevron) chevron.className = open ? 'bi bi-chevron-up d-md-none' : 'bi bi-chevron-down d-md-none';
                }

                // По умолчанию на мобильном скрыто
                applyState(localStorage.getItem(STORAGE_KEY) === 'open');

                toggle.addEventListener('click', function () {
                    if (!isMobile()) return;
                    const isHidden = body.style.display === 'none';
                    applyState(isHidden);
                    localStorage.setItem(STORAGE_KEY, isHidden ? 'open' : 'closed');
                });

                window.addEventListener('resize', () => applyState(localStorage.getItem(STORAGE_KEY) === 'open'));
            })();

            // ── Данные ──────────────────────────────────────────────────────────────
            const cutterSelect         = document.getElementById('cutterSelect');
            const batchSelect          = document.getElementById('batchSelect');
            const rawQtyInput          = document.getElementById('rawQtyInput');
            const remainingInfo        = document.getElementById('remainingInfo');
            const container            = document.getElementById('productsContainer');
            const totalQtyEl           = document.getElementById('totalQty');
            const addBtn               = document.getElementById('addProductBtn');
            const activeReceptionAlert = document.getElementById('activeReceptionAlert');
            const activeReceptionLink  = document.getElementById('activeReceptionLink');
            const markUsedBlock        = document.getElementById('markUsedBlock');
            const markUsedBtnCreate    = document.getElementById('markUsedBtnCreate');

            const copyItems = @json($copyItems ?? []);
            let rowIndex = 0;

            // ── Смена пильщика → AJAX загрузка партий ───────────────────────────────
            const createBatchLink = document.getElementById('createBatchLink');

            function updateCreateBatchLink(cutterId) {
                if (!createBatchLink) return;
                const url = new URL(createBatchLink.href, window.location.origin);
                if (cutterId) {
                    url.searchParams.set('copy_worker', cutterId);
                } else {
                    url.searchParams.delete('copy_worker');
                }
                createBatchLink.href = url.toString();
            }

            // При загрузке — если пильщик уже выбран
            updateCreateBatchLink(cutterSelect.value);

            cutterSelect.addEventListener('change', function () {
                const cutterId = this.value;
                updateCreateBatchLink(cutterId);
                batchSelect.innerHTML = '<option value="">Загрузка...</option>';

                if (!cutterId) {
                    batchSelect.innerHTML = '<option value="">— Сначала выберите пильщика —</option>';
                    return;
                }

                fetch(`/api/workers/${cutterId}/batches`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(r => r.json())
                    .then(batches => {
                        if (!batches.length) {
                            batchSelect.innerHTML = '<option value="">— Нет доступных партий —</option>';
                            return;
                        }
                        batchSelect.innerHTML = '<option value="">— Выберите партию —</option>';
                        batches.forEach(b => {
                            const opt = document.createElement('option');
                            opt.value = b.id;
                            opt.dataset.remaining  = b.remaining_quantity;
                            opt.dataset.productSku = b.product_sku || '';
                            opt.dataset.status     = b.status || '';
                            opt.textContent = (b.remaining_quantity <= 0 ? '⚠ ' : '') + b.label;
                            batchSelect.appendChild(opt);
                        });
                    });
            });

            const saveCloseBatchBtn = document.getElementById('saveCloseBatchBtn');
            const closeBatchInput   = document.getElementById('closeBatchInput');

            // ── Остаток партии ───────────────────────────────────────────────────────
            function updateRemainingIndicator() {
                const opt = batchSelect.options[batchSelect.selectedIndex];
                if (opt?.value) {
                    const rem  = parseFloat(opt.dataset.remaining) || 0;
                    const used = parseFloat(rawQtyInput.value) || 0;
                    const afterRem = Math.round((rem - used) * 1000) / 1000;
                    remainingInfo.textContent = `Доступно: ${rem.toFixed(3)} м³`;
                    remainingInfo.className = used > rem
                        ? 'form-text text-danger'
                        : 'form-text text-info';
                    if (saveCloseBatchBtn) saveCloseBatchBtn.disabled = afterRem > 0;
                } else {
                    remainingInfo.textContent = '';
                    if (saveCloseBatchBtn) saveCloseBatchBtn.disabled = true;
                }
            }

            saveCloseBatchBtn?.addEventListener('click', function () {
                if (!confirm('Сохранить приёмку и закрыть партию?\nСвязанная приёмка будет переведена в статус «Завершена».')) return;
                closeBatchInput.value = '1';
                document.getElementById('receptionForm').submit();
            });

            // ── Последние приёмки по партии ─────────────────────────────────────────
            function renderReceptionsList(receptions) {
                const body = document.getElementById('lastReceptionsBody');
                const badge = document.querySelector('#lastReceptionsToggle .badge');
                if (!body) return;

                if (!receptions.length) {
                    body.innerHTML = `<div class="text-center py-4 text-muted"><i class="bi bi-inbox fs-3 d-block mb-1"></i>Нет приёмок</div>`;
                    if (badge) badge.textContent = '0';
                    return;
                }

                if (badge) badge.textContent = receptions.length;

                const items = receptions.map(r => {
                    const productsHtml = r.items.map(i =>
                        `<div class="text-muted" style="font-size:.75rem">${i.product_name} <span class="text-dark">× ${i.quantity}</span></div>`
                    ).join('');
                    const cutterHtml = r.cutter_name
                        ? `<div class="text-muted mt-1" style="font-size:.72rem"><i class="bi bi-hammer me-1"></i>${r.cutter_name}</div>`
                        : '';

                    const itemsJson = JSON.stringify(r.items.map(i => ({
                        product_id:    i.product_id,
                        product_label: i.product_label,
                        is_undercut:   i.is_undercut,
                    }))).replace(/"/g, '&quot;');

                    return `<div class="list-group-item px-2 py-2">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1 me-2">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="fw-semibold small">#${r.id}</span>
                                    <span class="badge bg-primary bg-opacity-10 text-primary" style="font-size:.7rem">${r.total_quantity} м²</span>
                                    <span class="text-muted" style="font-size:.72rem">${r.created_at}</span>
                                </div>
                                ${productsHtml}
                                ${cutterHtml}
                            </div>
                            <button type="button"
                                    class="btn btn-sm btn-outline-secondary copy-reception-btn flex-shrink-0"
                                    data-items="${itemsJson}"
                                    style="width:28px;height:28px;padding:0;font-size:.75rem"
                                    title="Скопировать продукты">
                                <i class="bi bi-copy"></i>
                            </button>
                        </div>
                    </div>`;
                }).join('');

                body.innerHTML = `<div class="list-group list-group-flush">${items}</div>`;
            }

            function loadReceptionsByBatch(batchId) {
                const body = document.getElementById('lastReceptionsBody');
                if (!body) return;
                if (!batchId) return;
                body.innerHTML = `<div class="text-center py-3 text-muted small"><i class="bi bi-hourglass-split me-1"></i>Загрузка...</div>`;
                fetch(`/api/batches/${batchId}/receptions`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(r => r.json())
                    .then(data => renderReceptionsList(data))
                    .catch(() => { body.innerHTML = `<div class="text-center py-3 text-muted small">Ошибка загрузки</div>`; });
            }

            // ── SKU-фильтр продуктов ─────────────────────────────────────────────
            const allCatalogWrap  = document.getElementById('allCatalogWrap');
            const allCatalogCheck = document.getElementById('allCatalogCheck');
            let currentSkuPrefix  = null;

            // Маппинг первой группы сырья → группа готовой продукции
            const SKU_GROUP_MAP = { '01': '04' };

            function localDerivePrefix(rawSku) {
                if (!rawSku) return null;
                const parts = rawSku.split('-');
                if (parts.length < 2) return null;
                const out = SKU_GROUP_MAP[parts[0]];
                return out ? `${out}-${parts[1]}` : null;
            }

            function applySkuPrefix(prefix) {
                currentSkuPrefix = prefix;
                // Устанавливаем data-sku-prefix на все строки продуктов
                container.querySelectorAll('.product-picker-row').forEach(row => {
                    if (prefix) row.dataset.skuPrefix = prefix;
                    else delete row.dataset.skuPrefix;
                });
                // Показываем чекбокс только если есть маппинг
                if (allCatalogWrap) {
                    allCatalogWrap.style.display = prefix ? '' : 'none';
                    if (allCatalogCheck) allCatalogCheck.checked = false;
                }
            }

            if (allCatalogCheck) {
                allCatalogCheck.addEventListener('change', function () {
                    container.querySelectorAll('.product-picker-row').forEach(row => {
                        if (this.checked) delete row.dataset.skuPrefix;
                        else if (currentSkuPrefix) row.dataset.skuPrefix = currentSkuPrefix;
                    });
                });
            }

            // ── Блоки под select: нулевая партия и активная приёмка ─────────────────
            function updateBatchSidePanels(opt) {
                // Сбросить оба блока
                if (activeReceptionAlert) activeReceptionAlert.style.display = 'none';
                if (markUsedBlock) markUsedBlock.style.display = 'none';
                if (!opt?.value) return;

                const rem    = parseFloat(opt.dataset.remaining) || 0;
                const status = opt.dataset.status || '';

                // Блок «Израсходована» — только если in_work + remaining <= 0
                if (markUsedBlock && status === 'in_work' && rem <= 0) {
                    markUsedBlock.style.display = '';
                    markUsedBlock.dataset.batchId = opt.value;
                }

                // Проверяем активную приёмку через AJAX
                if (activeReceptionAlert) {
                    fetch(`/api/batches/${opt.value}/active-reception`, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    })
                        .then(r => r.json())
                        .then(data => {
                            if (data && data.edit_url) {
                                activeReceptionAlert.style.display = '';
                                if (activeReceptionLink) activeReceptionLink.href = data.edit_url;
                            }
                        })
                        .catch(() => {});
                }
            }

            // Кнопка «Израсходована» в create — AJAX + удалить option из select
            if (markUsedBtnCreate) {
                markUsedBtnCreate.addEventListener('click', function () {
                    const batchId = markUsedBlock?.dataset.batchId;
                    if (!batchId) return;
                    if (!confirm('Отметить партию как «Израсходована»? Она исчезнет из списка.')) return;

                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
                        || document.querySelector('input[name="_token"]')?.value;

                    fetch(`/raw-batches/${batchId}/mark-used`, {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken },
                    })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                // Удаляем option из select и сбрасываем выбор
                                const opt = batchSelect.querySelector(`option[value="${batchId}"]`);
                                if (opt) opt.remove();
                                batchSelect.value = '';
                                batchSelect.dispatchEvent(new Event('change'));
                            } else {
                                alert(data.error || 'Ошибка');
                            }
                        })
                        .catch(() => alert('Ошибка сети'));
                });
            }

            batchSelect.addEventListener('change', function () {
                const opt = batchSelect.options[batchSelect.selectedIndex];
                if (opt?.value) {
                    const rem = parseFloat(opt.dataset.remaining) || 0;
                    rawQtyInput.value = rem.toFixed(3);
                    loadReceptionsByBatch(opt.value);
                    applySkuPrefix(localDerivePrefix(opt.dataset.productSku || ''));
                } else {
                    applySkuPrefix(null);
                }
                updateRemainingIndicator();
                updateBatchSidePanels(opt);
            });

            // При загрузке страницы — если партия уже выбрана (old/copy)
            if (batchSelect.value) {
                loadReceptionsByBatch(batchSelect.value);
                const selectedOpt = batchSelect.options[batchSelect.selectedIndex];
                if (selectedOpt?.dataset.productSku) {
                    applySkuPrefix(localDerivePrefix(selectedOpt.dataset.productSku));
                }
                // Заполняем расход остатком партии если поле ещё не заполнено
                if (selectedOpt?.dataset.remaining && (!rawQtyInput.value || parseFloat(rawQtyInput.value) === 0)) {
                    rawQtyInput.value = parseFloat(selectedOpt.dataset.remaining).toFixed(3);
                }
                updateBatchSidePanels(selectedOpt);
            }
            rawQtyInput.addEventListener('input', updateRemainingIndicator);
            updateRemainingIndicator();

            // ── Карта остатков продуктов (для бейджей в пикере) ─────────────────────
            const storeHidden = document.querySelector('input[name="store_id"]');
            fetch('/api/products/stocks', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(data => { window.ProductPickerStockMap = data; });

            // ── Данные продуктов (коэффициенты) ─────────────────────────────────────
            const productCoeffCache = {};

            async function fetchProductCoeff(productId) {
                if (!productId) return null;
                if (productCoeffCache[productId] !== undefined) return productCoeffCache[productId];
                try {
                    const res = await fetch(`/api/products/${productId}/coeff`);
                    if (!res.ok) return null;
                    const data = await res.json();
                    productCoeffCache[productId] = data.prod_cost_coeff ?? 0;
                    return productCoeffCache[productId];
                } catch { return null; }
            }

            function updateRowCoeff(row) {
                const coeffDisplay = row.querySelector('.coeff-display');
                const undercutCb   = row.querySelector('.undercut-checkbox');
                if (!coeffDisplay) return;

                const baseCoeff = parseFloat(coeffDisplay.dataset.baseCoeff);
                if (isNaN(baseCoeff)) return;

                const isUndercut = undercutCb?.checked || false;
                const effective  = isUndercut ? baseCoeff - 1.5 : baseCoeff;
                coeffDisplay.textContent = isUndercut
                    ? `${baseCoeff.toFixed(1)} − 1.5 = ${effective.toFixed(1)}`
                    : baseCoeff.toFixed(1);
                coeffDisplay.classList.toggle('text-warning-emphasis', isUndercut);
                coeffDisplay.classList.toggle('text-dark', !isUndercut);
            }

            document.addEventListener('product-picker:selected', async function (e) {
                const row       = e.detail?.row;
                const productId = e.detail?.productId;
                if (!row || !productId) { updateTotal(); return; }

                const coeffDisplay = row.querySelector('.coeff-display');
                if (coeffDisplay) {
                    const coeff = await fetchProductCoeff(productId);
                    if (coeff !== null) {
                        coeffDisplay.dataset.baseCoeff = coeff;
                        updateRowCoeff(row);
                    }
                }
                updateTotal();
            });

            container.addEventListener('change', function (e) {
                if (e.target.classList.contains('undercut-checkbox')) {
                    const row = e.target.closest('.product-picker-row');
                    if (row) updateRowCoeff(row);
                }
            });

            function updateTotal() {
                let sum = 0;
                container.querySelectorAll('.product-picker-qty').forEach(el => {
                    sum += parseFloat(el.value) || 0;
                });
                totalQtyEl.textContent = sum.toFixed(2);
            }

            document.addEventListener('product-picker:removed', updateTotal);
            container.addEventListener('input', e => {
                if (e.target.classList.contains('product-picker-qty')) updateTotal();
            });

            // ── Добавить строку продукта ─────────────────────────────────────────────
            function addRow(productId = '', productLabel = '', quantity = '', isUndercut = false) {
                const tpl   = document.getElementById('pickerRowTemplate');
                const clone = tpl.content.cloneNode(true);

                clone.querySelectorAll('[data-tpl-index]').forEach(el => {
                    ['id','name','for','data-hidden-id','data-search-id','data-modal'].forEach(attr => {
                        if (el.hasAttribute(attr)) {
                            el.setAttribute(attr, el.getAttribute(attr).replace('__IDX__', rowIndex));
                        }
                    });
                });

                const searchInput  = clone.querySelector('.product-picker-search');
                const hiddenInput  = clone.querySelector('input[type="hidden"][name*="product_id"]');
                const qtyInput     = clone.querySelector('.product-picker-qty');
                const undercutCb   = clone.querySelector('.undercut-checkbox');

                if (searchInput) searchInput.value = productLabel;
                if (hiddenInput) hiddenInput.value  = productId;
                if (qtyInput)    qtyInput.value     = quantity;
                if (undercutCb && isUndercut) undercutCb.checked = true;

                const row = clone.querySelector('.product-picker-row');
                if (currentSkuPrefix && !(allCatalogCheck?.checked)) {
                    row.dataset.skuPrefix = currentSkuPrefix;
                }
                if (storeHidden?.value) {
                    row.dataset.sourceStoreId = storeHidden.value;
                }
                container.appendChild(clone);
                if (window.ProductPicker) window.ProductPicker.initRow(row);

                // Загружаем коэффициент если продукт уже задан (копирование)
                if (productId) {
                    const coeffDisplay = row.querySelector('.coeff-display');
                    if (coeffDisplay) {
                        fetchProductCoeff(productId).then(coeff => {
                            if (coeff !== null) {
                                coeffDisplay.dataset.baseCoeff = coeff;
                                updateRowCoeff(row);
                            }
                        });
                    }
                }

                rowIndex++;
                updateTotal();
            }

            // ── Копирование продуктов из списка последних приёмок ────────────────────
            document.addEventListener('click', function (e) {
                const btn = e.target.closest('.copy-reception-btn');
                if (!btn) return;
                try {
                    const items = JSON.parse(btn.dataset.items || '[]');
                    if (!items.length) return;
                    container.innerHTML = '';
                    rowIndex = 0;
                    items.forEach(p => addRow(p.product_id, p.product_label, '', p.is_undercut));
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                } catch (err) {
                    console.error('copy-reception-btn parse error', err);
                }
            });

            addBtn.addEventListener('click', () => addRow());

            if (copyItems.length > 0) {
                copyItems.forEach(p => addRow(p.product_id, p.product_label, '', p.is_undercut));
            } else {
                addRow();
            }

            // ── Валидация перед отправкой ────────────────────────────────────────────
            document.getElementById('receptionForm').addEventListener('submit', function (e) {
                let ok = true;
                const rows = container.querySelectorAll('.product-picker-row');

                if (!rows.length) {
                    alert('Добавьте хотя бы один продукт');
                    e.preventDefault(); return;
                }

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

                if (!ok) { alert('Заполните все поля продуктов'); e.preventDefault(); return; }

                const opt = batchSelect.options[batchSelect.selectedIndex];
                if (opt?.value) {
                    const rem  = parseFloat(opt.dataset.remaining) || 0;
                    const used = parseFloat(rawQtyInput.value) || 0;
                    if (used > rem) {
                        alert('Расход сырья превышает остаток в партии');
                        e.preventDefault();
                    }
                }
            });

            // ── Техоперация МойСклад: коллапс + переключатель «Вручную» ─────────────
            (function () {
                const toggle     = document.getElementById('processingToggle');
                const body       = document.getElementById('processingBody');
                const chevron    = document.getElementById('processingChevron');
                const nameSwitch = document.getElementById('manualProcessingName');
                const nameInput  = document.getElementById('processingNameInput');

                toggle.addEventListener('click', function () {
                    const open = body.style.display === 'none';
                    body.style.display = open ? '' : 'none';
                    chevron.className  = open ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
                });

                nameSwitch.addEventListener('change', function () {
                    nameInput.readOnly = !this.checked;
                    if (!this.checked) nameInput.value = '';
                });
            })();
        });
    </script>

    {{-- Шаблон строки продукта --}}
    <template id="pickerRowTemplate">
        <div class="product-picker-row" data-tpl-index="__IDX__"
             style="padding:.35rem 0;border-bottom:1px solid #f0f0f0">

            {{-- Строка 1: поиск + кнопка дерева + удалить --}}
            <div class="d-flex gap-1 align-items-start mb-1">
                <div class="flex-grow-1 position-relative">
                    <div class="input-group input-group-sm">
                        <input type="text"
                               id="search___IDX__"
                               data-tpl-index="__IDX__"
                               class="form-control product-picker-search"
                               placeholder="Название продукта..."
                               autocomplete="off"
                               data-hidden-id="pid___IDX__"
                               required>
                        <button type="button"
                                class="btn btn-outline-secondary product-picker-tree-btn"
                                data-modal="modal___IDX__"
                                data-hidden-id="pid___IDX__"
                                data-search-id="search___IDX__"
                                data-tpl-index="__IDX__"
                                title="Выбрать из каталога">
                            <i class="bi bi-diagram-3"></i>
                        </button>
                    </div>
                    <div class="product-picker-dropdown list-group shadow-sm"
                         id="drop___IDX__"
                         style="display:none;position:absolute;z-index:1000;width:100%;max-height:280px;overflow-y:auto">
                    </div>
                </div>
                <button type="button"
                        class="btn btn-sm btn-outline-danger product-picker-remove flex-shrink-0"
                        style="height:31px"
                        title="Удалить">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            {{-- Строка 2: количество + подкол + коэфф --}}
            <div class="d-flex gap-2 align-items-center">
                <div class="input-group input-group-sm" style="width:130px;flex-shrink:0">
                    <span class="input-group-text" style="font-size:.75rem">м²</span>
                    <input type="number"
                           id="qty___IDX__"
                           name="products[__IDX__][quantity]"
                           class="form-control product-picker-qty"
                           placeholder="0.000"
                           step="0.001" min="0.001"
                           data-tpl-index="__IDX__"
                           required>
                </div>

                <div class="form-check mb-0 flex-shrink-0">
                    <input class="form-check-input undercut-checkbox"
                           type="checkbox"
                           id="undercut___IDX__"
                           name="products[__IDX__][is_undercut]"
                           value="1"
                           data-tpl-index="__IDX__">
                    <label class="form-check-label small text-warning-emphasis fw-semibold"
                           for="undercut___IDX__"
                           title="Снижает коэффициент на 1.5">
                        80% подкол
                    </label>
                </div>

                <span class="text-muted small text-nowrap">
                    коэф: <span class="coeff-display fw-semibold text-dark"
                                data-base-coeff=""
                                data-tpl-index="__IDX__">—</span>
                </span>

                <input type="hidden"
                       id="pid___IDX__"
                       name="products[__IDX__][product_id]"
                       data-tpl-index="__IDX__">
            </div>

            {{-- Модальное окно дерева --}}
            <div class="modal fade" id="modal___IDX__" tabindex="-1" data-tpl-index="__IDX__">
                <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Выбрать из каталога</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body" style="max-height:70vh;overflow-y:auto">
                            <input type="text"
                                   class="form-control mb-3 tree-search-input"
                                   placeholder="Поиск по каталогу...">
                            <div class="product-tree-container"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>

    @vite(['resources/js/product-picker.js'])
@endpush
