@extends('layouts.app')
@section('title', 'Новая операция цеха')

@section('content')
@php
    $userDeptId  = auth()->user()?->primaryDepartmentId();
    $userDeptIds = implode(',', auth()->user()?->worker?->departmentIds() ?? []);
@endphp

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
/* Выбор шаблона (workshop-preset-picker.js) */
.wpp-menu{max-height:340px;overflow-y:auto;min-width:min(320px,90vw)}
.wpp-rich{white-space:normal;padding:.4rem .75rem}
.wpp-rich__sub{font-size:.72rem;color:#495057}
.dropdown-item.active .wpp-rich__sub,.dropdown-item.active .text-muted{color:rgba(255,255,255,.85)!important}
.wpp-gcard{display:flex;flex-direction:column;align-items:flex-start;width:100%;height:100%;padding:.5rem .65rem;border:1px solid #dee2e6;border-radius:.5rem;background:#fff;text-align:left;line-height:1.2}
.wpp-gcard:hover{border-color:#86b7fe}
.wpp-gcard.is-active{border-color:#0d6efd;background:#f0f6ff;box-shadow:0 0 0 2px rgba(13,110,253,.2)}
.wpp-gcard__icon{font-size:1.3rem;color:#0d6efd;margin-bottom:.15rem}
.wpp-gcard__name{font-size:.82rem;font-weight:600}
.wpp-gcard__sub{font-size:.7rem;color:#6c757d;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%}
.wpp-selbar{display:flex;align-items:center;flex-wrap:wrap;gap:.5rem;padding:.45rem .6rem;border:1px solid #dee2e6;border-radius:.5rem}
.min-w-0{min-width:0}
</style>

<div class="container py-3 py-md-4" style="max-width:980px">

    <x-page-header title="Новая операция цеха" :back-url="route('workshops.index')" mobileTitle="Цех" />

    @include('partials.alerts')

    <form method="POST" action="{{ route('workshops.store') }}" id="workshopForm" data-submit-guard>
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
                                            data-raw-store-id="{{ $department->defaultRawStore?->id }}"
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
                                        data-user-dept-ids="{{ $userDeptIds }}"
                                        data-dept-select-id="departmentSelect"
                                        data-toggle-id="allWorkersParticipantsPack"
                                        required>
                                    @foreach($masterWorkers as $worker)
                                        <option value="{{ $worker->id }}"
                                            data-department-ids="{{ implode(',', $worker->departmentIds()) }}"
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

                {{-- Блок: Склады (свёрнутый) --}}
                @php
                    $storesOpen = $errors->has('store_id') || $errors->has('product_store_id')
                        || !old('store_id', $defaultStore?->id) || !old('product_store_id', $defaultProductStore?->id);
                @endphp
                <div class="card shadow-sm mb-2">
                    <div class="card-header bg-white py-2" role="button" id="storeToggle">
                        <span class="small fw-semibold text-muted"><i class="bi bi-building me-1"></i> Склады <span class="text-danger">*</span></span>
                        <i class="bi {{ $storesOpen ? 'bi-chevron-up' : 'bi-chevron-down' }} float-end" id="storeChevron"></i>
                    </div>
                    <div class="card-body py-2" id="storeBody" @if(!$storesOpen) style="display:none" @endif>
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
                                data-user-dept-ids="{{ $userDeptIds }}"
                                data-dept-select-id="departmentSelect"
                                data-toggle-id="allWorkersParticipantsPack"
                                required>
                            <option value="">— работник —</option>
                            @foreach($workers as $worker)
                                <option value="{{ $worker->id }}"
                                    data-department-ids="{{ implode(',', $worker->departmentIds()) }}"
                                    data-department-id="{{ $worker->department_id }}"
                                    @if($worker->position === 'Администратор') data-always-visible @endif
                                    {{ old('packer_id', $selectedPackerId ?? auth()->user()->worker_id) == $worker->id ? 'selected' : '' }}>
                                    {{ $worker->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Блок: Шаблоны (пресеты отдела). Свёрнут, пока у отдела нет шаблонов. --}}
                <div class="card shadow-sm mb-2">
                    <div class="card-header bg-white py-2" role="button" id="presetToggle">
                        <span class="small fw-semibold text-muted"><i class="bi bi-collection me-1"></i> Шаблоны</span>
                        <span class="small text-muted ms-1" id="presetTotal"></span>
                        <i class="bi bi-chevron-down float-end" id="presetChevron"></i>
                    </div>
                    <div class="card-body py-2" id="presetBody" style="display:none">
                        <div id="presetPicker"></div>
                    </div>
                </div>

                {{-- Конвейер: Сырьё → Упаковка → Продукт → Затраты --}}
                <div class="pack-flow mb-2">

                    {{-- ① Сырьё --}}
                    <div class="pf-node raw">
                        <div class="pf-head">
                            <span class="pf-title"><span class="pf-step">1</span><i class="bi bi-box"></i> Сырьё <span class="text-danger">*</span></span>
                            <span class="pf-badge">Итого: <strong id="rawTotalQty">0</strong></span>
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
                                    Оставьте пустым — рассчитается автоматически: зарплата работника и мастера + накладные отдела.
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
        'qtyWidth'    => '120px',
        'qtyMode'     => 'simple',
        'showRemove'  => true,
    ])
</template>

@endsection

@push('scripts')
@include('partials.production-rates-js')
@vite(['resources/js/product-picker.js', 'resources/js/worker-picker.js'])
<script>
(function () {
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
    const prodTotalEl = document.getElementById('prodTotalQty');

    function sumQty(container) {
        let sum = 0;
        container.querySelectorAll('.product-picker-qty').forEach(el => sum += parseFloat(el.value) || 0);
        return sum;
    }
    // Итоги — по единицам товаров строк: «2,00 м² · 5,00 шт»
    function updateTotals() {
        rawTotalEl.textContent  = Uom.formatTotals(Uom.totalsFromRows(blocks.raw.container), 2);
        prodTotalEl.textContent = Uom.formatTotals(Uom.totalsFromRows(blocks.product.container), 2);
    }
    document.addEventListener('product-picker:unit', updateTotals);

    // ── Автоподсказка затрат (₽/ед продукта) ─────────────────────────────────
    const costInput = document.getElementById('manualProcessingSum');
    const costHint  = document.getElementById('costSuggestHint');
    let costTouched = costInput.value !== '';
    costInput.addEventListener('input', () => { costTouched = true; });

    const coeffCache = {};
    const noCoeff = { worker: 0, master: 0 };
    async function fetchCoeff(productId) {
        if (!productId) return noCoeff;
        if (coeffCache[productId] !== undefined) return coeffCache[productId];
        try {
            const res = await fetch(`/api/products/${productId}/coeff`);
            if (!res.ok) return noCoeff;
            const data = await res.json();
            coeffCache[productId] = {
                worker: parseFloat(data.prod_cost_coeff) || 0,
                master: parseFloat(data.master_cost_coeff) || 0,
            };
            return coeffCache[productId];
        } catch { return noCoeff; }
    }

    function rowsData(container) {
        return Array.from(container.querySelectorAll('.product-picker-row')).map(row => ({
            productId: row.querySelector('input[type="hidden"]')?.value || '',
            qty:       parseFloat(row.querySelector('.product-picker-qty')?.value) || 0,
        })).filter(r => r.productId && r.qty > 0);
    }

    async function recomputeSuggestedCost() {
        const products = rowsData(blocks.product.container);
        const totalProduct = sumQty(blocks.product.container);

        if (!products.length || totalProduct <= 0) {
            if (costHint) costHint.textContent = '';
            return;
        }

        // Ставки и накладные задаются per-department, а отдел выбирается на этой же
        // странице — берём значения текущего выбранного отдела.
        // Зеркало WorkshopSyncService::computeProcessingSum(): работник + мастер + накладные.
        const departmentId = departmentSelect?.value || null;

        let workerTotal = 0;
        let masterTotal = 0;
        for (const p of products) {
            const coeff = await fetchCoeff(p.productId);
            workerTotal += RateFormula.prodCost(coeff.worker, departmentId) * p.qty;
            masterTotal += RateFormula.masterCost(coeff.master, departmentId) * p.qty;
        }

        const worker   = workerTotal / totalProduct;
        const master   = masterTotal / totalProduct;
        const overhead = RateFormula.overheadPerUnit(departmentId);

        const suggested = Math.round((worker + master + overhead) * 100) / 100;
        if (costHint) {
            costHint.textContent = suggested > 0
                ? `Авторасчёт: ${suggested.toFixed(2)} ₽/ед (работник ${worker.toFixed(2)} + мастер ${master.toFixed(2)} + накладные ${overhead.toFixed(2)})`
                : '';
        }
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

    // ── Шаблоны (пресеты отдела) ─────────────────────────────────────────────
    const presetBody    = document.getElementById('presetBody');
    const presetChevron = document.getElementById('presetChevron');
    const presetTotal   = document.getElementById('presetTotal');

    function setPresetBodyOpen(open) {
        presetBody.style.display = open ? '' : 'none';
        presetChevron.className  = open ? 'bi bi-chevron-up float-end' : 'bi bi-chevron-down float-end';
    }
    document.getElementById('presetToggle').addEventListener('click', () => {
        setPresetBodyOpen(presetBody.style.display === 'none');
    });

    // Модули Vite (app.js) исполняются после этого инлайн-скрипта — пикер создаём на DOMContentLoaded.
    let presetPicker = null;
    document.addEventListener('DOMContentLoaded', () => {
        presetPicker = new WorkshopPresetPicker(document.getElementById('presetPicker'),
            { onApply: applyPreset });
        loadPresets();
    });

    // Нет шаблонов у отдела — блок свёрнут; есть — развёрнут.
    function showPresetTotal(total, emptyText = '— у отдела нет') {
        presetTotal.textContent = total ? `(${total})` : emptyText;
        setPresetBodyOpen(total > 0);
    }

    async function loadPresets() {
        if (!presetPicker) return;
        presetPicker.setData(null);
        const deptId = departmentSelect.value;
        if (!deptId) { showPresetTotal(0, '— выберите отдел'); return; }
        try {
            const res = await fetch(`/api/departments/${deptId}/workshop-presets`,
                { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) return;
            showPresetTotal(presetPicker.setData(await res.json()));
        } catch (err) {
            console.error('loadPresets', err);
        }
    }

    function applyPreset(preset, count) {
        ['raw', 'package', 'product'].forEach(t => { blocks[t].container.innerHTML = ''; });
        rowIndex = 0;
        preset.items.forEach(it => {
            if (!blocks[it.role]) return;
            const qty = Math.round(it.quantity * count * 1000) / 1000;
            addRow(it.role, { productId: it.product_id, label: it.product_label, quantity: qty });
        });
    }

    departmentSelect.addEventListener('change', loadPresets);
    // Ставки и накладные — отдела; сменили отдел → подсказка затрат пересчитывается.
    departmentSelect.addEventListener('change', recomputeSuggestedCost);

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
        if (!rawStoreSelect.dataset.touched && opt.dataset.rawStoreId) {
            rawStoreSelect.value = opt.dataset.rawStoreId;
        }
        if (!productStoreSelect.dataset.touched && opt.dataset.productStoreId) {
            productStoreSelect.value = opt.dataset.productStoreId;
        }
        applyStores();
    }
    departmentSelect.addEventListener('change', syncStoresFromDepartment);
    // Ставка пильщика у отделов своя — авторасчёт затрат обязан пересчитаться
    departmentSelect.addEventListener('change', recomputeSuggestedCost);
    @if(old('store_id') || old('product_store_id'))
        rawStoreSelect.dataset.touched = '1';
        productStoreSelect.dataset.touched = '1';
    @endif

    // ── Отдел от выбранного работника ────────────────────────────────────────
    // Ручной выбор отдела приоритетнее: isTrusted отличает человека от dispatchEvent.
    departmentSelect.addEventListener('change', function (e) {
        if (e.isTrusted) this.dataset.touched = '1';
    });
    @if(old('department_id'))
        departmentSelect.dataset.touched = '1';
    @endif

    const packerSelect = document.getElementById('packerSelect');
    function syncDeptFromPacker() {
        if (departmentSelect.dataset.touched) return;
        const deptId = packerSelect.options[packerSelect.selectedIndex]?.dataset.departmentId;
        if (!deptId || departmentSelect.value === deptId) return;
        // Отдела может не быть в списке: не-админ выбирает только среди своих отделов
        if (!departmentSelect.querySelector(`option[value="${deptId}"]`)) return;
        departmentSelect.value = deptId;
        departmentSelect.dispatchEvent(new Event('change', { bubbles: true }));
    }
    packerSelect.addEventListener('change', syncDeptFromPacker);
    syncDeptFromPacker();

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

    // ── Тоггл блока «Шаблоны» ────────────────────────────────────────────────
    // Тоггл блока «Склады» + автораскрытие, если скрытый required-селект не заполнен
    const storeToggle  = document.getElementById('storeToggle');
    const storeBody    = document.getElementById('storeBody');
    const storeChevron = document.getElementById('storeChevron');
    function openStoreBody() {
        storeBody.style.display = '';
        storeChevron.className  = 'bi bi-chevron-up float-end';
    }
    storeToggle.addEventListener('click', () => {
        const open = storeBody.style.display === 'none';
        storeBody.style.display = open ? '' : 'none';
        storeChevron.className  = open ? 'bi bi-chevron-up float-end' : 'bi bi-chevron-down float-end';
    });
    [rawStoreSelect, productStoreSelect].forEach(sel => {
        sel.addEventListener('invalid', openStoreBody);
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
