@extends('layouts.app')
@section('title', 'Новая операция цеха')

@section('content')
@php $userDeptId = auth()->user()?->worker?->department_id; @endphp

<style>
/* Компактные по высоте селекты (склады, работник) */
.compact-select{padding-top:.15rem;padding-bottom:.15rem;min-height:0;height:auto;line-height:1.25}
/* Конвейер цеха: сырьё + тара → продукт */
.pack-flow{
    --pf-raw:#2f6df6;  --pf-raw-bg:#eef4ff;  --pf-raw-bd:#cfe0ff;
    --pf-pack:#d9820e; --pf-pack-bg:#fdf3e3; --pf-pack-bd:#f4dcae;
    --pf-prod:#0f9e6a; --pf-prod-bg:#e8f7f0; --pf-prod-bd:#bfe9d5;
    --pf-cost:#6f42c1; --pf-cost-bg:#f3eefc; --pf-cost-bd:#ddccf5;
    --pf-line:#dee2e6;
    display:flex;flex-direction:column;gap:.5rem;
}
.pf-node{border:1px solid var(--pf-line);border-radius:.6rem;padding:.85rem .9rem;background:#fff}
.pf-node.raw{background:var(--pf-raw-bg);border-color:var(--pf-raw-bd)}
.pf-node.pack{background:var(--pf-pack-bg);border-color:var(--pf-pack-bd)}
.pf-node.prod{background:var(--pf-prod-bg);border-color:var(--pf-prod);box-shadow:0 .35rem 1.1rem rgba(15,158,106,.15)}
.pf-node.cost{background:var(--pf-cost-bg);border-color:var(--pf-cost-bd)}
.pf-head{display:flex;align-items:center;justify-content:space-between;gap:.5rem;margin-bottom:.6rem;flex-wrap:wrap}
.pf-title{display:flex;align-items:center;gap:.4rem;font-size:.78rem;font-weight:650;text-transform:uppercase;letter-spacing:.02em;margin:0}
.pf-node.raw .pf-title{color:var(--pf-raw)}
.pf-node.pack .pf-title{color:var(--pf-pack)}
.pf-node.prod .pf-title{color:var(--pf-prod)}
.pf-node.cost .pf-title{color:var(--pf-cost)}
.pf-step{width:1.35rem;height:1.35rem;border-radius:50%;display:inline-grid;place-items:center;font-size:.72rem;font-weight:700;color:#fff;flex:none}
.pf-node.raw .pf-step{background:var(--pf-raw)}
.pf-node.pack .pf-step{background:var(--pf-pack)}
.pf-node.prod .pf-step{background:var(--pf-prod)}
.pf-node.cost .pf-step{background:var(--pf-cost)}
.pf-badge{font-size:.72rem;font-weight:600;padding:.15rem .5rem;border-radius:.4rem;background:#fff}
.pf-node.raw .pf-badge{color:var(--pf-raw)}
.pf-node.prod .pf-badge{color:var(--pf-prod)}
</style>

<div class="container py-3 py-md-4" style="max-width:980px">

    <x-page-header title="Новая операция цеха" :back-url="route('workshops.index')" mobileTitle="Цех" />

    @include('partials.alerts')

    <form method="POST" action="{{ route('workshops.store') }}" id="workshopForm">
        @csrf

        <div class="row g-3">
            <div class="col-12 col-lg-7">

                {{-- Блок: Отдел и Мастер (свёрнутый) --}}
                <div class="card shadow-sm mb-2">
                    <div class="card-header bg-white py-2" role="button" id="deptToggle">
                        <span class="small fw-semibold text-muted"><i class="bi bi-diagram-2 me-1"></i> Отдел и Мастер</span>
                        <i class="bi bi-chevron-down float-end" id="deptChevron"></i>
                    </div>
                    <div class="card-body" id="deptBody" style="display:none">
                        <div class="row g-2">
                            <div class="col-12 col-sm-6">
                                <label for="departmentSelect" class="form-label small fw-semibold mb-1">Отдел</label>
                                <select name="department_id"
                                        id="departmentSelect"
                                        class="form-select form-select-sm @error('department_id') is-invalid @enderror"
                                        style="border-radius:.4rem">
                                    <option value="">— Не задан —</option>
                                    @foreach($departments as $department)
                                        <option value="{{ $department->id }}"
                                            data-production-store-id="{{ $department->defaultProductionStore?->id }}"
                                            data-product-store-id="{{ $department->defaultProductStore?->id }}"
                                            {{ (string) old('department_id', $userDeptId) === (string) $department->id ? 'selected' : '' }}>
                                            {{ $department->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="form-text" style="font-size:.7rem">
                                    По умолчанию — ваш отдел. Смена отдела перефильтрует списки работников и подставит склады отдела.
                                </div>
                                @error('department_id')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label small fw-semibold mb-1">Мастер <span class="text-danger">*</span></label>
                                <select name="receiver_id"
                                        class="form-select form-select-sm worker-picker"
                                        style="border-radius:.4rem"
                                        data-user-dept-id="{{ $userDeptId }}"
                                        data-dept-select-id="departmentSelect"
                                        data-toggle-id="allWorkersParticipantsPack"
                                        required>
                                    @foreach($masterWorkers as $worker)
                                        <option value="{{ $worker->id }}"
                                            data-department-id="{{ $worker->department_id }}"
                                            @if($worker->position === 'Администратор') data-always-visible @endif
                                            {{ old('receiver_id', auth()->user()->worker_id) == $worker->id ? 'selected' : '' }}>
                                            {{ $worker->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Блок: Работник --}}
                <div class="card shadow-sm mb-2">
                    <div class="card-body py-2">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <label for="packerSelect" class="form-label small fw-semibold mb-0">Работник <span class="text-danger">*</span></label>
                            @if($userDeptId)
                                <div class="form-check form-check-inline mb-0 me-0">
                                    <input class="form-check-input" type="checkbox" id="allWorkersParticipantsPack">
                                    <label class="form-check-label small text-muted" for="allWorkersParticipantsPack">все работники</label>
                                </div>
                            @endif
                        </div>
                        <select name="packer_id" id="packerSelect"
                                class="form-select form-select-sm worker-picker compact-select"
                                style="border-radius:.4rem"
                                data-user-dept-id="{{ $userDeptId }}"
                                data-dept-select-id="departmentSelect"
                                data-toggle-id="allWorkersParticipantsPack"
                                required>
                            <option value="">— работник —</option>
                            @foreach($packers as $worker)
                                <option value="{{ $worker->id }}"
                                    data-department-id="{{ $worker->department_id }}"
                                    @if($worker->position === 'Администратор') data-always-visible @endif
                                    {{ old('packer_id', $selectedPackerId ?? '') == $worker->id ? 'selected' : '' }}>
                                    {{ $worker->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Блок: Склады --}}
                <div class="card shadow-sm mb-2">
                    <div class="card-body py-2">
                        <span class="small fw-semibold text-muted d-block mb-1"><i class="bi bi-building me-1"></i> Склады <span class="text-danger">*</span></span>
                        <div class="row g-2">
                            <div class="col-12 col-sm-6">
                                <label for="rawStoreSelect" class="form-label small text-muted mb-1">Склад сырья</label>
                                <select name="store_id" id="rawStoreSelect"
                                        class="form-select form-select-sm compact-select @error('store_id') is-invalid @enderror"
                                        style="border-radius:.4rem" required>
                                    <option value="">— Выберите склад —</option>
                                    @foreach($stores as $store)
                                        <option value="{{ $store->id }}"
                                            {{ old('store_id', $defaultStore?->id) == $store->id ? 'selected' : '' }}>
                                            {{ $store->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('store_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-12 col-sm-6">
                                <label for="productStoreSelect" class="form-label small text-muted mb-1">Склад продукта</label>
                                <select name="product_store_id" id="productStoreSelect"
                                        class="form-select form-select-sm compact-select @error('product_store_id') is-invalid @enderror"
                                        style="border-radius:.4rem" required>
                                    <option value="">— Выберите склад —</option>
                                    @foreach($stores as $store)
                                        <option value="{{ $store->id }}"
                                            {{ old('product_store_id', $defaultProductStore?->id) == $store->id ? 'selected' : '' }}>
                                            {{ $store->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('product_store_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Конвейер: Сырьё → Упаковка → Продукт → Затраты --}}
                <div class="pack-flow mb-2">

                    {{-- ① Сырьё --}}
                    <div class="pf-node raw">
                        <div class="pf-head">
                            <span class="pf-title"><span class="pf-step">1</span><i class="bi bi-box"></i> Сырьё <span class="text-danger">*</span></span>
                            <span class="pf-badge">Итого: <strong id="rawTotalQty">0</strong> <span id="rawTotalQtyUnit">м²</span></span>
                        </div>

                        <div id="rawContainer"></div>

                        <button type="button" class="btn btn-sm btn-outline-primary mt-1" data-add="raw">
                            <i class="bi bi-plus-circle"></i> Добавить сырьё
                        </button>
                    </div>

                    {{-- ② Упаковка --}}
                    <div class="pf-node pack">
                        <div class="pf-head">
                            <span class="pf-title"><span class="pf-step">2</span><i class="bi bi-box-seam"></i> Упаковка <span class="text-muted fw-normal text-lowercase">(опционально)</span></span>
                        </div>

                        <div id="packagesContainer"></div>

                        <button type="button" class="btn btn-sm btn-outline-warning mt-1" data-add="package">
                            <i class="bi bi-plus-circle"></i> Добавить упаковку
                        </button>
                    </div>

                    {{-- ③ Продукт --}}
                    <div class="pf-node prod">
                        <div class="pf-head">
                            <span class="pf-title"><span class="pf-step">3</span><i class="bi bi-check2-circle"></i> Продукт <span class="text-danger">*</span></span>
                            <span class="pf-badge">Итого: <strong id="prodTotalQty">0</strong></span>
                        </div>

                        <div id="productsContainer"></div>

                        <button type="button" class="btn btn-sm btn-outline-success mt-1" data-add="product">
                            <i class="bi bi-plus-circle"></i> Добавить продукт
                        </button>
                    </div>

                    {{-- ④ Затраты на производство --}}
                    <div class="pf-node cost">
                        <div class="pf-head">
                            <span class="pf-title"><span class="pf-step">4</span><i class="bi bi-cash-coin"></i> Затраты на производство</span>
                        </div>

                        <div class="row g-2 align-items-end">
                            <div class="col-12 col-sm-6">
                                <label class="form-label small text-muted mb-1">Себестоимость производства, ₽ за единицу продукта</label>
                                <div class="input-group input-group-sm">
                                    <input type="number" name="manual_processing_sum" id="manualProcessingSum"
                                           step="0.01" min="0"
                                           class="form-control @error('manual_processing_sum') is-invalid @enderror"
                                           style="border-radius:.4rem 0 0 .4rem"
                                           placeholder="0.00"
                                           value="{{ old('manual_processing_sum') }}">
                                    <span class="input-group-text">₽/ед</span>
                                </div>
                                @error('manual_processing_sum')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-12 col-sm-6">
                                <div class="form-text mb-1" style="font-size:.72rem">
                                    Уходит в МойСклад (processingSum) и влияет на себестоимость продукта.
                                    Оставьте пустым — рассчитается автоматически по зарплате работника.
                                    <span id="costSuggestHint" class="d-block text-muted"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Блок: Примечание --}}
                <div class="card shadow-sm mb-2">
                    <div class="card-body">
                        <label class="form-label small fw-semibold mb-1">Примечание</label>
                        <textarea name="notes" rows="2" class="form-control form-control-sm" style="border-radius:.4rem">{{ old('notes') }}</textarea>
                    </div>
                </div>

                {{-- Блок: Техоперация МойСклад --}}
                <div class="card shadow-sm mb-2">
                    <div class="card-header bg-white py-2" role="button" id="msToggle">
                        <span class="small fw-semibold text-muted"><i class="bi bi-cloud me-1"></i> Техоперация МойСклад</span>
                        <i class="bi bi-chevron-down float-end" id="msChevron"></i>
                    </div>
                    <div class="card-body" id="msBody" style="display:none">
                        <label class="form-label small fw-semibold mb-1">Имя техоперации</label>
                        <input type="text" name="processing_name" id="processingNameInput" readonly
                               class="form-control form-control-sm" style="border-radius:.4rem"
                               placeholder="Сформируется автоматически: 26-XX-ЦЕХ-NN">
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="manualProcessingName">
                            <label class="form-check-label small" for="manualProcessingName">Задать имя вручную</label>
                        </div>
                    </div>
                </div>

                {{-- Блок: Дата создания (только для админа) --}}
                <x-admin-date-field />

                {{-- Кнопки --}}
                <div class="d-flex flex-column flex-sm-row gap-2 mt-3">
                    <button type="submit" class="btn btn-primary btn-sm flex-fill">
                        <i class="bi bi-save"></i> Сохранить операцию
                    </button>
                    <button type="submit" name="close_workshop" value="1" class="btn btn-warning btn-sm flex-fill">
                        <i class="bi bi-check2-circle"></i> Сохранить + Закрыть операцию
                    </button>
                </div>
            </div>

            <div class="col-12 col-lg-5 d-none d-lg-block">
                <div class="card shadow-sm">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
                        <span class="fw-semibold small">
                            <i class="bi bi-clock-history me-1"></i> Последние операции
                        </span>
                        <span class="badge bg-secondary">{{ $lastWorkshops->count() }}</span>
                    </div>

                    <div class="list-group list-group-flush">
                        @forelse($lastWorkshops as $w)
                            @php
                                $wRaw     = $w->items->where('role', \App\Models\WorkshopItem::ROLE_RAW);
                                $wProduct = $w->items->where('role', \App\Models\WorkshopItem::ROLE_PRODUCT);
                                $copyData = $w->items->map(fn($i) => [
                                    'role'          => $i->role,
                                    'product_id'    => $i->product_id,
                                    'product_label' => $i->product?->name ?? '',
                                ])->toJson(JSON_UNESCAPED_UNICODE);
                            @endphp
                            <div class="list-group-item px-2 py-2">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1 me-2">
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <span class="fw-semibold small">#{{ $w->id }}</span>
                                            <span class="text-muted" style="font-size:.72rem">
                                                {{ $w->created_at->format('d.m H:i') }}
                                            </span>
                                        </div>
                                        @foreach($wRaw as $item)
                                            <div class="text-muted" style="font-size:.75rem">
                                                {{ $item->product?->name }}
                                                <span class="text-dark">× {{ number_format($item->quantity, 2) }}</span>
                                            </div>
                                        @endforeach
                                        @foreach($wProduct as $item)
                                            <div class="text-success-emphasis" style="font-size:.75rem">
                                                <i class="bi bi-arrow-return-right me-1"></i>{{ $item->product?->name }}
                                            </div>
                                        @endforeach
                                        @if($w->packer)
                                            <div class="text-muted mt-1" style="font-size:.72rem">
                                                <i class="bi bi-person me-1"></i>{{ $w->packer->name }}
                                            </div>
                                        @endif
                                    </div>
                                    <button type="button"
                                            class="btn btn-sm btn-outline-secondary copy-workshop-btn flex-shrink-0"
                                            data-items="{{ $copyData }}"
                                            style="width:28px;height:28px;padding:0;font-size:.75rem"
                                            title="Скопировать позиции">
                                        <i class="bi bi-copy"></i>
                                    </button>
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                                Нет операций
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

{{-- Шаблоны строк --}}
<template id="tplRaw">
    @include('partials.product-picker-row', [
        'name'        => 'raw_materials',
        'index'       => '__IDX__',
        'placeholder' => 'Введите название сырья...',
        'unit'        => 'м²',
        'dynamicUnit' => true,
        'qtyWidth'    => '120px',
        'qtyMode'     => 'simple',
        'showRemove'  => true,
    ])
</template>

<template id="tplPackage">
    @include('partials.product-picker-row', [
        'name'        => 'packages',
        'index'       => '__IDX__',
        'placeholder' => 'Введите вариант упаковки...',
        'unit'        => 'шт',
        'qtyWidth'    => '120px',
        'qtyMode'     => 'simple',
        'skuPrefix'   => '07-03',
        'showRemove'  => true,
    ])
</template>

<template id="tplProduct">
    @include('partials.product-picker-row', [
        'name'        => 'products',
        'index'       => '__IDX__',
        'placeholder' => 'Введите название продукта...',
        'unit'        => 'шт',
        'dynamicUnit' => true,
        'qtyWidth'    => '120px',
        'qtyMode'     => 'simple',
        'showRemove'  => true,
    ])
</template>

@endsection

@push('scripts')
@vite(['resources/js/product-picker.js', 'resources/js/worker-picker.js'])
<script>
(function () {
    const PACKAGING_PROD_COST = {{ (float) \App\Models\Setting::get('PACKAGING_PROD_COST', 0) }};
    const PACKAGING_COST      = {{ (float) \App\Models\Setting::get('PACKAGING_COST', 0) }};

    const departmentSelect   = document.getElementById('departmentSelect');
    const rawStoreSelect     = document.getElementById('rawStoreSelect');
    const productStoreSelect = document.getElementById('productStoreSelect');

    const blocks = {
        raw:     { container: document.getElementById('rawContainer'),      tpl: 'tplRaw',     store: () => rawStoreSelect.value },
        package: { container: document.getElementById('packagesContainer'), tpl: 'tplPackage', store: () => rawStoreSelect.value },
        product: { container: document.getElementById('productsContainer'), tpl: 'tplProduct', store: () => productStoreSelect.value },
    };

    // Карта остатков товаров — бейджи остатка в выпадающем списке пикера.
    fetch('/api/products/stocks', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => { window.ProductPickerStockMap = data; });

    // Единый счётчик индексов строк — уникальные id/name по всем блокам.
    let rowIndex = 0;

    function addRow(type, { productId = '', label = '', quantity = '' } = {}) {
        const block = blocks[type];
        const tpl   = document.getElementById(block.tpl);
        const clone = tpl.content.cloneNode(true);

        clone.querySelectorAll('[data-tpl-index]').forEach(el => {
            ['id', 'name', 'for', 'data-hidden-id', 'data-search-id', 'data-modal'].forEach(attr => {
                if (el.hasAttribute(attr)) {
                    el.setAttribute(attr, el.getAttribute(attr).replace('__IDX__', rowIndex));
                }
            });
        });

        const search = clone.querySelector('.product-picker-search');
        const hidden = clone.querySelector('input[type="hidden"]');
        const qty    = clone.querySelector('.product-picker-qty');

        if (label)     search.value = label;
        if (productId) hidden.value = productId;
        if (quantity)  qty.value    = quantity;

        const row = clone.querySelector('.product-picker-row');
        const storeId = block.store();
        if (storeId) row.dataset.sourceStoreId = storeId;

        block.container.appendChild(clone);
        if (window.ProductPicker) window.ProductPicker.initRow(row);

        rowIndex++;
        updateTotals();
        recomputeSuggestedCost();
    }

    // ── Итоги по блокам ──────────────────────────────────────────────────────
    const rawTotalEl  = document.getElementById('rawTotalQty');
    const rawUnitEl   = document.getElementById('rawTotalQtyUnit');
    const prodTotalEl = document.getElementById('prodTotalQty');

    function sumQty(container) {
        let sum = 0;
        container.querySelectorAll('.product-picker-qty').forEach(el => sum += parseFloat(el.value) || 0);
        return sum;
    }
    function updateTotals() {
        rawTotalEl.textContent  = sumQty(blocks.raw.container).toFixed(2);
        prodTotalEl.textContent = sumQty(blocks.product.container).toFixed(2);
    }

    // Единица «Итого» сырья — по выбранному товару.
    document.addEventListener('product-picker:selected', (e) => {
        const row = e.detail?.row;
        if (row && blocks.raw.container.contains(row) && rawUnitEl) {
            rawUnitEl.textContent = e.detail.unit || 'м²';
        }
    });

    // ── Автоподсказка затрат (₽/ед продукта) ─────────────────────────────────
    const costInput = document.getElementById('manualProcessingSum');
    const costHint  = document.getElementById('costSuggestHint');
    let costTouched = costInput.value !== '';
    costInput.addEventListener('input', () => { costTouched = true; });

    const coeffCache = {};
    async function fetchCoeff(productId) {
        if (!productId) return 0;
        if (coeffCache[productId] !== undefined) return coeffCache[productId];
        try {
            const res = await fetch(`/api/products/${productId}/coeff`);
            if (!res.ok) return 0;
            const data = await res.json();
            coeffCache[productId] = parseFloat(data.prod_cost_coeff) || 0;
            return coeffCache[productId];
        } catch { return 0; }
    }

    function rowsData(container) {
        return Array.from(container.querySelectorAll('.product-picker-row')).map(row => ({
            productId: row.querySelector('input[type="hidden"]')?.value || '',
            qty:       parseFloat(row.querySelector('.product-picker-qty')?.value) || 0,
        })).filter(r => r.productId && r.qty > 0);
    }

    async function recomputeSuggestedCost() {
        const products = rowsData(blocks.product.container);
        const packages = rowsData(blocks.package.container);
        const totalProduct = sumQty(blocks.product.container);

        if (!products.length || totalProduct <= 0) {
            if (costHint) costHint.textContent = '';
            return;
        }

        // Коэффициент тары — по первой позиции упаковки.
        const packageCoeff = packages.length ? await fetchCoeff(packages[0].productId) : 0;

        let salaryTotal = 0;
        for (const p of products) {
            const coeff = await fetchCoeff(p.productId);
            const workerCost = PACKAGING_PROD_COST * coeff + PACKAGING_COST * packageCoeff;
            salaryTotal += workerCost * p.qty;
        }

        const suggested = Math.round((salaryTotal / totalProduct) * 100) / 100;
        if (costHint) costHint.textContent = suggested > 0 ? `Авторасчёт: ${suggested.toFixed(2)} ₽/ед` : '';
        if (!costTouched) costInput.value = suggested > 0 ? suggested.toFixed(2) : '';
    }

    // ── Обработчики контейнеров ──────────────────────────────────────────────
    Object.values(blocks).forEach(({ container }) => {
        container.addEventListener('input', e => {
            if (e.target.classList.contains('product-picker-qty')) {
                updateTotals();
                recomputeSuggestedCost();
            }
        });
    });
    document.addEventListener('product-picker:selected', () => { updateTotals(); recomputeSuggestedCost(); });
    document.addEventListener('product-picker:removed', () => { updateTotals(); recomputeSuggestedCost(); });

    document.querySelectorAll('[data-add]').forEach(btn => {
        btn.addEventListener('click', () => addRow(btn.dataset.add));
    });

    // ── Копирование позиций (из списка операций / панели «Последние операции») ─
    // Роль позиции (raw/package/product) совпадает с типом блока 1:1.
    function copyItemsIntoForm(items) {
        ['raw', 'package', 'product'].forEach(t => { blocks[t].container.innerHTML = ''; });
        rowIndex = 0;
        items.forEach(it => {
            if (!blocks[it.role]) return;
            addRow(it.role, { productId: it.product_id, label: it.product_label });
        });
        updateTotals();
    }

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.copy-workshop-btn');
        if (!btn) return;
        try {
            const items = JSON.parse(btn.dataset.items || '[]');
            if (!items.length) return;
            copyItemsIntoForm(items);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } catch (err) {
            console.error('copy-workshop-btn parse error', err);
        }
    });

    // ── Склады строк по блокам ───────────────────────────────────────────────
    function applyStores() {
        [['raw', rawStoreSelect], ['package', rawStoreSelect], ['product', productStoreSelect]].forEach(([type, sel]) => {
            blocks[type].container.querySelectorAll('.product-picker-row').forEach(row => {
                if (sel.value) row.dataset.sourceStoreId = sel.value;
                else delete row.dataset.sourceStoreId;
            });
        });
    }
    rawStoreSelect.addEventListener('change', () => { rawStoreSelect.dataset.touched = '1'; applyStores(); });
    productStoreSelect.addEventListener('change', () => { productStoreSelect.dataset.touched = '1'; applyStores(); });

    function syncStoresFromDepartment() {
        const opt = departmentSelect.options[departmentSelect.selectedIndex];
        if (!opt || !opt.value) return;
        if (!rawStoreSelect.dataset.touched && opt.dataset.productionStoreId) {
            rawStoreSelect.value = opt.dataset.productionStoreId;
        }
        if (!productStoreSelect.dataset.touched && opt.dataset.productStoreId) {
            productStoreSelect.value = opt.dataset.productStoreId;
        }
        applyStores();
    }
    departmentSelect.addEventListener('change', syncStoresFromDepartment);
    @if(old('store_id') || old('product_store_id'))
        rawStoreSelect.dataset.touched = '1';
        productStoreSelect.dataset.touched = '1';
    @endif

    // Начальные строки: копия операции (copy_from) либо две пустые строки.
    const copyItems = @json($copyItems ?? []);
    if (copyItems.length) {
        copyItemsIntoForm(copyItems);
    } else {
        addRow('raw');
        addRow('product');
    }

    // ── Тоггл блока МойСклад ──────────────────────────────────────────────────
    const msToggle  = document.getElementById('msToggle');
    const msBody    = document.getElementById('msBody');
    const msChevron = document.getElementById('msChevron');
    msToggle.addEventListener('click', () => {
        const open = msBody.style.display === 'none';
        msBody.style.display = open ? '' : 'none';
        msChevron.className  = open ? 'bi bi-chevron-up float-end' : 'bi bi-chevron-down float-end';
    });

    // ── Тоггл блока «Отдел» (раскрыт при ошибке валидации) ───────────────────
    const deptToggle  = document.getElementById('deptToggle');
    const deptBody    = document.getElementById('deptBody');
    const deptChevron = document.getElementById('deptChevron');
    @if($errors->has('department_id'))
        deptBody.style.display = '';
        deptChevron.className  = 'bi bi-chevron-up float-end';
    @endif
    deptToggle.addEventListener('click', () => {
        const open = deptBody.style.display === 'none';
        deptBody.style.display = open ? '' : 'none';
        deptChevron.className  = open ? 'bi bi-chevron-up float-end' : 'bi bi-chevron-down float-end';
    });

    const manualCb = document.getElementById('manualProcessingName');
    const procName = document.getElementById('processingNameInput');
    manualCb?.addEventListener('change', () => {
        procName.readOnly = !manualCb.checked;
    });

    // ── Валидация перед отправкой ─────────────────────────────────────────────
    document.getElementById('workshopForm').addEventListener('submit', function (e) {
        const rawRows  = blocks.raw.container.querySelectorAll('.product-picker-row');
        const prodRows = blocks.product.container.querySelectorAll('.product-picker-row');

        if (!rowsData(blocks.raw.container).length) {
            alert('Добавьте хотя бы одну позицию сырья'); e.preventDefault(); return;
        }
        if (!rowsData(blocks.product.container).length) {
            alert('Добавьте хотя бы один продукт на выходе'); e.preventDefault(); return;
        }
    });
})();
</script>
@endpush
