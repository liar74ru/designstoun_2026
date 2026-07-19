@extends('layouts.app')
@section('title', 'Цех #' . $workshop->id)

@section('content')
@php
    $userDeptId = auth()->user()?->worker?->department_id;
    $rawItems     = $workshop->items->where('role', \App\Models\WorkshopItem::ROLE_RAW)->values();
    $packageItems = $workshop->items->where('role', \App\Models\WorkshopItem::ROLE_PACKAGE)->values();
    $productItems = $workshop->items->where('role', \App\Models\WorkshopItem::ROLE_PRODUCT)->values();
    $ri = 0; // сквозной индекс строк для уникальных id/name
@endphp

<style>
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
.pf-node.prod{background:var(--pf-prod-bg);border-color:var(--pf-prod)}
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
</style>

<div class="container py-3 py-md-4" style="max-width:980px">

    <x-page-header title="Цех #{{ $workshop->id }}" :back-url="route('workshops.index')" mobileTitle="Цех" />

    @include('partials.alerts')

    <form method="POST" action="{{ route('workshops.update', $workshop) }}" id="workshopEditForm">
        @csrf
        @method('PUT')

        {{-- Блок: Участники (свёрнутый) --}}
        <div class="card shadow-sm mb-2">
            <div class="card-header bg-white py-2" role="button" id="peopleToggle">
                <span class="small fw-semibold text-muted"><i class="bi bi-people me-1"></i> Участники</span>
                <i class="bi bi-chevron-down float-end" id="peopleChevron"></i>
            </div>
            <div class="card-body" id="peopleBody" style="display:none">
                <div class="row g-2">
                    <div class="col-12 col-sm-6">
                        <label class="form-label small fw-semibold mb-1">Работник</label>
                        <input type="text" class="form-control form-control-sm" style="border-radius:.4rem" readonly
                               value="{{ $workshop->packer->name ?? '—' }}">
                        <input type="hidden" name="packer_id" value="{{ $workshop->packer_id }}">
                    </div>
                    <div class="col-12 col-sm-6">
                        <div class="d-flex justify-content-between align-items-center">
                            <label class="form-label small fw-semibold mb-1">Приёмщик</label>
                            @if(auth()->user()->isAdmin() && $userDeptId)
                                <div class="form-check form-check-inline mb-0">
                                    <input class="form-check-input" type="checkbox" id="allWorkersReceiverPackEdit">
                                    <label class="form-check-label small text-muted" for="allWorkersReceiverPackEdit">все</label>
                                </div>
                            @endif
                        </div>
                        @if(auth()->user()->isAdmin())
                            <select name="receiver_id"
                                    class="form-select form-select-sm worker-picker"
                                    style="border-radius:.4rem"
                                    data-user-dept-id="{{ $userDeptId }}"
                                    data-toggle-id="allWorkersReceiverPackEdit"
                                    required>
                                @foreach($masterWorkers as $worker)
                                    <option value="{{ $worker->id }}"
                                        data-department-id="{{ $worker->department_id }}"
                                        @if($worker->position === 'Администратор') data-always-visible @endif
                                        {{ old('receiver_id', $workshop->receiver_id) == $worker->id ? 'selected' : '' }}>
                                        {{ $worker->name }}
                                    </option>
                                @endforeach
                            </select>
                        @else
                            <input type="text" class="form-control form-control-sm" style="border-radius:.4rem" readonly
                                   value="{{ $workshop->receiver->name ?? '—' }}">
                            <input type="hidden" name="receiver_id" value="{{ $workshop->receiver_id }}">
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Блок: Склады --}}
        <div class="card shadow-sm mb-2">
            <div class="card-body">
                <span class="small fw-semibold text-muted d-block mb-2"><i class="bi bi-building me-1"></i> Склады <span class="text-danger">*</span></span>
                <div class="row g-2">
                    <div class="col-12 col-sm-6">
                        <label class="form-label small text-muted mb-1">Склад сырья (материалов)</label>
                        <select name="store_id" id="rawStoreSelect"
                                class="form-select form-select-sm @error('store_id') is-invalid @enderror"
                                style="border-radius:.4rem" required>
                            <option value="">— Выберите склад —</option>
                            @foreach($stores as $store)
                                <option value="{{ $store->id }}"
                                    {{ old('store_id', $workshop->store_id) == $store->id ? 'selected' : '' }}>
                                    {{ $store->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text" style="font-size:.7rem">Списание сырья и тары. При смене остаток тары переносится на новый склад.</div>
                        @error('store_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label small text-muted mb-1">Склад продукта</label>
                        <select name="product_store_id" id="productStoreSelect"
                                class="form-select form-select-sm @error('product_store_id') is-invalid @enderror"
                                style="border-radius:.4rem" required>
                            <option value="">— Выберите склад —</option>
                            @foreach($stores as $store)
                                <option value="{{ $store->id }}"
                                    {{ old('product_store_id', $workshop->product_store_id ?? $workshop->store_id) == $store->id ? 'selected' : '' }}>
                                    {{ $store->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text" style="font-size:.7rem">Оприходование результата операции.</div>
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
                    <span class="text-muted small">Итого: <strong id="rawTotalQty">0</strong></span>
                </div>
                <div id="rawContainer">
                    @foreach($rawItems as $item)
                        @include('partials.product-picker-row', [
                            'name' => 'raw_materials', 'index' => $ri++,
                            'value' => $item->product_id, 'label' => $item->product->name,
                            'quantity' => number_format((float) $item->quantity, 3, '.', ''),
                            'placeholder' => 'Введите название сырья...', 'unit' => 'м²',
                            'dynamicUnit' => true, 'qtyWidth' => '120px', 'qtyMode' => 'simple', 'showRemove' => true,
                        ])
                    @endforeach
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary mt-1" data-add="raw">
                    <i class="bi bi-plus-circle"></i> Добавить сырьё
                </button>
            </div>

            {{-- ② Упаковка --}}
            <div class="pf-node pack">
                <div class="pf-head">
                    <span class="pf-title"><span class="pf-step">2</span><i class="bi bi-box-seam"></i> Упаковка <span class="text-muted fw-normal text-lowercase">(опционально)</span></span>
                </div>
                <div id="packagesContainer">
                    @foreach($packageItems as $item)
                        @include('partials.product-picker-row', [
                            'name' => 'packages', 'index' => $ri++,
                            'value' => $item->product_id, 'label' => $item->product->name,
                            'quantity' => number_format((float) $item->quantity, 3, '.', ''),
                            'placeholder' => 'Введите вариант упаковки...', 'unit' => 'шт',
                            'qtyWidth' => '120px', 'qtyMode' => 'simple', 'skuPrefix' => '07-03', 'showRemove' => true,
                        ])
                    @endforeach
                </div>
                <button type="button" class="btn btn-sm btn-outline-warning mt-1" data-add="package">
                    <i class="bi bi-plus-circle"></i> Добавить упаковку
                </button>
            </div>

            {{-- ③ Продукт --}}
            <div class="pf-node prod">
                <div class="pf-head">
                    <span class="pf-title"><span class="pf-step">3</span><i class="bi bi-check2-circle"></i> Продукт <span class="text-danger">*</span></span>
                    <span class="text-muted small">Итого: <strong id="prodTotalQty">0</strong></span>
                </div>
                <div id="productsContainer">
                    @foreach($productItems as $item)
                        @include('partials.product-picker-row', [
                            'name' => 'products', 'index' => $ri++,
                            'value' => $item->product_id, 'label' => $item->product->name,
                            'quantity' => number_format((float) $item->quantity, 3, '.', ''),
                            'placeholder' => 'Введите название продукта...', 'unit' => 'шт',
                            'dynamicUnit' => true, 'qtyWidth' => '120px', 'qtyMode' => 'simple', 'showRemove' => true,
                        ])
                    @endforeach
                </div>
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
                                   value="{{ old('manual_processing_sum', $workshop->manual_processing_sum) }}">
                            <span class="input-group-text">₽/ед</span>
                        </div>
                        @error('manual_processing_sum')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-12 col-sm-6">
                        <div class="form-text mb-1" style="font-size:.72rem">
                            Уходит в МойСклад (processingSum). Оставьте пустым — рассчитается автоматически по зарплате работника.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Блок: Примечание --}}
        <div class="card shadow-sm mb-2">
            <div class="card-body">
                <label class="form-label small fw-semibold mb-1">Примечание</label>
                <textarea name="notes" rows="2" class="form-control form-control-sm" style="border-radius:.4rem">{{ old('notes', $workshop->notes) }}</textarea>
            </div>
        </div>

        {{-- Блок: Дата создания (только для админа) --}}
        <x-admin-date-field :value="$workshop->created_at?->format('Y-m-d\TH:i')" />

        {{-- Кнопки --}}
        <div class="d-flex flex-column flex-sm-row gap-2 mt-3">
            <button type="submit" class="btn btn-primary btn-sm flex-fill">
                <i class="bi bi-save"></i> Сохранить
            </button>
            <button type="submit" name="close_workshop" value="1" class="btn btn-warning btn-sm flex-fill">
                <i class="bi bi-check2-circle"></i> Сохранить + Закрыть операцию
            </button>
        </div>
    </form>
</div>

{{-- Шаблоны строк --}}
<template id="tplRaw">
    @include('partials.product-picker-row', [
        'name' => 'raw_materials', 'index' => '__IDX__',
        'placeholder' => 'Введите название сырья...', 'unit' => 'м²',
        'dynamicUnit' => true, 'qtyWidth' => '120px', 'qtyMode' => 'simple', 'showRemove' => true,
    ])
</template>
<template id="tplPackage">
    @include('partials.product-picker-row', [
        'name' => 'packages', 'index' => '__IDX__',
        'placeholder' => 'Введите вариант упаковки...', 'unit' => 'шт',
        'qtyWidth' => '120px', 'qtyMode' => 'simple', 'skuPrefix' => '07-03', 'showRemove' => true,
    ])
</template>
<template id="tplProduct">
    @include('partials.product-picker-row', [
        'name' => 'products', 'index' => '__IDX__',
        'placeholder' => 'Введите название продукта...', 'unit' => 'шт',
        'dynamicUnit' => true, 'qtyWidth' => '120px', 'qtyMode' => 'simple', 'showRemove' => true,
    ])
</template>
@endsection

@push('scripts')
@vite(['resources/js/product-picker.js', 'resources/js/worker-picker.js'])
<script>
(function () {
    const rawStoreSelect     = document.getElementById('rawStoreSelect');
    const productStoreSelect = document.getElementById('productStoreSelect');

    const blocks = {
        raw:     { container: document.getElementById('rawContainer'),      tpl: 'tplRaw',     store: () => rawStoreSelect.value },
        package: { container: document.getElementById('packagesContainer'), tpl: 'tplPackage', store: () => rawStoreSelect.value },
        product: { container: document.getElementById('productsContainer'), tpl: 'tplProduct', store: () => productStoreSelect.value },
    };

    fetch('/api/products/stocks', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => { window.ProductPickerStockMap = data; });

    let rowIndex = {{ $ri }};

    function addRow(type, { productId = '', label = '', quantity = '' } = {}) {
        const block = blocks[type];
        const clone = document.getElementById(block.tpl).content.cloneNode(true);

        clone.querySelectorAll('[data-tpl-index]').forEach(el => {
            ['id', 'name', 'for', 'data-hidden-id', 'data-search-id', 'data-modal'].forEach(attr => {
                if (el.hasAttribute(attr)) {
                    el.setAttribute(attr, el.getAttribute(attr).replace('__IDX__', rowIndex));
                }
            });
        });

        const row = clone.querySelector('.product-picker-row');
        if (block.store()) row.dataset.sourceStoreId = block.store();
        block.container.appendChild(clone);
        if (window.ProductPicker) window.ProductPicker.initRow(row);

        rowIndex++;
        updateTotals();
    }

    const rawTotalEl  = document.getElementById('rawTotalQty');
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
    function rowsData(container) {
        return Array.from(container.querySelectorAll('.product-picker-row')).map(row => ({
            productId: row.querySelector('input[type="hidden"]')?.value || '',
            qty:       parseFloat(row.querySelector('.product-picker-qty')?.value) || 0,
        })).filter(r => r.productId && r.qty > 0);
    }

    Object.values(blocks).forEach(({ container }) => {
        container.addEventListener('input', e => {
            if (e.target.classList.contains('product-picker-qty')) updateTotals();
        });
    });
    document.addEventListener('product-picker:removed', updateTotals);

    document.querySelectorAll('[data-add]').forEach(btn => {
        btn.addEventListener('click', () => addRow(btn.dataset.add));
    });

    function applyStores() {
        [['raw', rawStoreSelect], ['package', rawStoreSelect], ['product', productStoreSelect]].forEach(([type, sel]) => {
            blocks[type].container.querySelectorAll('.product-picker-row').forEach(row => {
                if (sel.value) row.dataset.sourceStoreId = sel.value;
                else delete row.dataset.sourceStoreId;
            });
        });
    }
    rawStoreSelect.addEventListener('change', applyStores);
    productStoreSelect.addEventListener('change', applyStores);
    applyStores();
    updateTotals();

    // Тоггл блока «Участники»
    const pToggle = document.getElementById('peopleToggle');
    const pBody   = document.getElementById('peopleBody');
    const pChev   = document.getElementById('peopleChevron');
    pToggle.addEventListener('click', () => {
        const open = pBody.style.display === 'none';
        pBody.style.display = open ? '' : 'none';
        pChev.className     = open ? 'bi bi-chevron-up float-end' : 'bi bi-chevron-down float-end';
    });

    document.getElementById('workshopEditForm').addEventListener('submit', function (e) {
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
