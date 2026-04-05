@extends('layouts.app')

@section('title', 'Редактировать поступление')

@section('content')
<div class="container py-3" style="max-width:700px">

    <x-page-header
        title="✏️ Редактировать поступление"
        mobileTitle="Редактировать поступление"
        :backUrl="route('supplier-orders.index')"
        backLabel="К списку">
    </x-page-header>

    @include('partials.alerts')

    <div class="alert alert-info py-2 mb-3 small">
        <i class="bi bi-info-circle me-1"></i>
        Редактирование доступно только для поступлений в статусе <strong>«Новый»</strong>.
        После сохранения изменения будут синхронизированы с МойСклад.
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <style>
                #editOrderForm .form-control,
                #editOrderForm .form-select { border-radius: .4rem; }
            </style>
            <form method="POST" action="{{ route('supplier-orders.update', $supplierOrder) }}" id="editOrderForm">
                @csrf
                @method('PUT')

                @if($errors->any())
                    <div class="alert alert-danger py-2 m-2">
                        @foreach($errors->all() as $error)
                            <div class="small">{{ $error }}</div>
                        @endforeach
                    </div>
                @endif

                {{-- Блок 1: Детали поступления --}}
                <div class="info-block">
                    <div class="info-block-header">
                        <span class="small fw-semibold text-muted">Детали поступления</span>
                    </div>
                    <div class="info-block-body">
                        <div class="row g-2">

                            {{-- Контрагент --}}
                            <div class="col-12">
                                <label class="form-label small fw-semibold mb-1">
                                    Поставщик <span class="text-danger">*</span>
                                </label>
                                <div class="position-relative">
                                    <input type="text"
                                           id="counterpartySearch"
                                           class="form-control form-control-sm @error('counterparty_id') is-invalid @enderror"
                                           placeholder="Введите название поставщика..."
                                           autocomplete="off"
                                           value="{{ old('counterparty_id') ? ($counterparties->firstWhere('id', old('counterparty_id'))?->name ?? '') : ($supplierOrder->counterparty?->name ?? '') }}"
                                           required>
                                    <input type="hidden"
                                           id="counterpartyId"
                                           name="counterparty_id"
                                           value="{{ old('counterparty_id', $supplierOrder->counterparty_id) }}">
                                    <div id="counterpartyDropdown"
                                         class="list-group shadow-sm"
                                         style="display:none;position:absolute;z-index:1050;width:100%;max-height:250px;overflow-y:auto">
                                    </div>
                                </div>
                                @error('counterparty_id')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>

                            {{-- Склад --}}
                            <div class="col-12">
                                <label class="form-label small fw-semibold mb-1">
                                    Склад приёмки <span class="text-danger">*</span>
                                </label>
                                <select name="store_id"
                                        class="form-select form-select-sm @error('store_id') is-invalid @enderror"
                                        style="font-size:.8rem;padding:.18rem .35rem;border-radius:.4rem"
                                        required>
                                    <option value="">— Выберите склад —</option>
                                    @foreach($stores as $store)
                                        <option value="{{ $store->id }}"
                                            {{ old('store_id', $supplierOrder->store_id) == $store->id ? 'selected' : '' }}>
                                            {{ $store->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('store_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                        </div>
                    </div>
                </div>

                {{-- Блок 2: Позиции сырья --}}
                <div class="info-block">
                    <div class="info-block-header d-flex justify-content-between align-items-center">
                        <span class="small fw-semibold text-muted">
                            Сырьё <span class="text-danger">*</span>
                        </span>
                        <div class="d-flex align-items-center gap-2">
                            <div class="form-check form-check-inline mb-0" id="allCatalogWrap">
                                <input class="form-check-input" type="checkbox" id="allCatalogCheck">
                                <label class="form-check-label small text-muted" for="allCatalogCheck">весь каталог</label>
                            </div>
                            <span class="text-muted small">Итого: <strong id="totalQty">0</strong> м³</span>
                        </div>
                    </div>
                    <div class="info-block-body">
                        <div id="productsContainer" style="margin-bottom:.25rem"></div>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-1" id="addProductBtn">
                            <i class="bi bi-plus-circle"></i> Добавить сырьё
                        </button>
                    </div>
                </div>

                {{-- Блок 3: Номер поступления --}}
                <div class="info-block">
                    <div class="info-block-header">
                        <span class="small fw-semibold text-muted">Номер поступления</span>
                    </div>
                    <div class="info-block-body">
                        <input type="text"
                               name="number"
                               class="form-control form-control-sm @error('number') is-invalid @enderror"
                               value="{{ old('number', $supplierOrder->number) }}"
                               required>
                        @error('number')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                {{-- Блок 4: Мастер-приёмщик --}}
                <div class="info-block">
                    <div class="info-block-header">
                        <span class="small fw-semibold text-muted">Мастер-приёмщик</span>
                    </div>
                    <div class="info-block-body">
                        <select name="receiver_id"
                                class="form-select form-select-sm @error('receiver_id') is-invalid @enderror"
                                style="font-size:.8rem;padding:.18rem .35rem;border-radius:.4rem">
                            <option value="">— Не указан —</option>
                            @foreach($receivers as $worker)
                                <option value="{{ $worker->id }}"
                                    {{ old('receiver_id', $supplierOrder->receiver_id) == $worker->id ? 'selected' : '' }}>
                                    {{ $worker->name }}
                                    @if($worker->position) ({{ $worker->position }}) @endif
                                </option>
                            @endforeach
                        </select>
                        @error('receiver_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                {{-- Блок 5: Примечание --}}
                <div class="info-block">
                    <div class="info-block-header d-flex justify-content-between align-items-center"
                         id="noteToggle" style="cursor:pointer" role="button">
                        <span class="small fw-semibold text-muted">Примечание</span>
                        <i class="bi bi-chevron-down" id="noteChevron"></i>
                    </div>
                    <div id="noteBody" style="display:none">
                        <div class="info-block-body">
                            <textarea name="note" class="form-control form-control-sm" rows="2"
                            >{{ old('note', $supplierOrder->note) }}</textarea>
                        </div>
                    </div>
                </div>

                <x-admin-date-field
                    hint="Изменение даты синхронизируется с МойСклад."
                    value="{{ old('manual_created_at', $supplierOrder->created_at->format('Y-m-d\TH:i')) }}" />

                <div class="p-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i> Сохранить
                    </button>
                    <a href="{{ route('supplier-orders.index') }}" class="btn btn-outline-secondary">Отмена</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Блок удаления --}}
    <div class="card shadow-sm border-danger mt-3">
        <div class="card-body py-3">
            <h6 class="text-danger mb-1"><i class="bi bi-trash me-1"></i>Удалить поступление</h6>
            <p class="text-muted small mb-3">
                Поступление будет безвозвратно удалено. Запись в МойСклад также будет удалена.
            </p>
            <form method="POST" action="{{ route('supplier-orders.destroy', $supplierOrder) }}"
                  onsubmit="return confirm('Удалить поступление №{{ $supplierOrder->number }}? Это действие необратимо.')">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-trash me-1"></i> Удалить поступление
                </button>
            </form>
        </div>
    </div>

</div>

@push('scripts')
<script>
const COUNTERPARTIES = @json($counterparties->map(fn($c) => ['id' => $c->id, 'name' => $c->name])->values());

// Существующие позиции для предзаполнения
@php
    $existingItems = $supplierOrder->items->map(fn($i) => [
        'product_id'    => $i->product_id,
        'product_label' => $i->product?->name ?? '',
        'quantity'      => (float) $i->quantity,
    ])->values();
@endphp
const EXISTING_ITEMS = @json($existingItems);

document.addEventListener('DOMContentLoaded', function () {

    // ── Поиск поставщика ─────────────────────────────────────────────────────
    (function () {
        const searchEl   = document.getElementById('counterpartySearch');
        const hiddenEl   = document.getElementById('counterpartyId');
        const dropdownEl = document.getElementById('counterpartyDropdown');
        if (!searchEl) return;

        function showMatches(q) {
            const matches = q
                ? COUNTERPARTIES.filter(c => c.name.toLowerCase().includes(q.toLowerCase())).slice(0, 30)
                : [];
            if (!matches.length) { dropdownEl.style.display = 'none'; return; }
            dropdownEl.innerHTML = matches.map(c =>
                `<button type="button"
                         class="list-group-item list-group-item-action py-1 px-2 cp-item"
                         style="font-size:.82rem"
                         data-id="${c.id}"
                         data-name="${c.name.replace(/"/g,'&quot;')}">${c.name}</button>`
            ).join('');
            dropdownEl.style.display = '';
        }

        searchEl.addEventListener('input', function () {
            hiddenEl.value = '';
            showMatches(this.value.trim());
        });

        dropdownEl.addEventListener('click', function (e) {
            const btn = e.target.closest('.cp-item');
            if (!btn) return;
            searchEl.value = btn.dataset.name;
            hiddenEl.value = btn.dataset.id;
            dropdownEl.style.display = 'none';
        });

        document.addEventListener('click', function (e) {
            if (!searchEl.contains(e.target) && !dropdownEl.contains(e.target)) {
                dropdownEl.style.display = 'none';
            }
        });

        searchEl.addEventListener('focus', function () {
            if (this.value.trim() && !hiddenEl.value) showMatches(this.value.trim());
        });
    })();

    // ── Примечание (скрыто по умолчанию, открыто если есть текст) ───────────
    (function () {
        const toggle  = document.getElementById('noteToggle');
        const body    = document.getElementById('noteBody');
        const chevron = document.getElementById('noteChevron');
        if (!toggle) return;
        @if(old('note', $supplierOrder->note))
            body.style.display = '';
            chevron.className  = 'bi bi-chevron-up';
        @endif
        toggle.addEventListener('click', function () {
            const isHidden = body.style.display === 'none';
            body.style.display = isHidden ? '' : 'none';
            chevron.className  = isHidden ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
        });
    })();

    // ── Карта остатков ────────────────────────────────────────────────────────
    fetch('/api/products/stocks', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => { window.ProductPickerStockMap = data; });

    // ── Итого м³ ──────────────────────────────────────────────────────────────
    const container       = document.getElementById('productsContainer');
    const totalQtyEl      = document.getElementById('totalQty');
    const addBtn          = document.getElementById('addProductBtn');
    const allCatalogCheck = document.getElementById('allCatalogCheck');
    const RAW_PREFIX      = '01-';
    let rowIndex = 0;

    // ── Чекбокс "весь каталог" ────────────────────────────────────────────────
    allCatalogCheck?.addEventListener('change', function () {
        container.querySelectorAll('.product-picker-row').forEach(row => {
            if (this.checked) delete row.dataset.skuPrefix;
            else row.dataset.skuPrefix = RAW_PREFIX;
        });
    });

    function updateTotal() {
        let sum = 0;
        container.querySelectorAll('.product-picker-qty').forEach(el => {
            sum += parseFloat(el.value) || 0;
        });
        totalQtyEl.textContent = sum.toFixed(3);
    }

    document.addEventListener('product-picker:removed', updateTotal);
    container.addEventListener('input', e => {
        if (e.target.classList.contains('product-picker-qty')) updateTotal();
    });

    // ── Добавить строку сырья ─────────────────────────────────────────────────
    function addRow(productId = '', productLabel = '', quantity = '') {
        const tpl   = document.getElementById('rawPickerRowTemplate');
        const clone = tpl.content.cloneNode(true);

        clone.querySelectorAll('[data-tpl-index]').forEach(el => {
            ['id','name','for','data-hidden-id','data-search-id','data-modal'].forEach(attr => {
                if (el.hasAttribute(attr)) {
                    el.setAttribute(attr, el.getAttribute(attr).replace('__IDX__', rowIndex));
                }
            });
        });

        const searchInput = clone.querySelector('.product-picker-search');
        const hiddenInput = clone.querySelector('input[type="hidden"][name*="product_id"]');
        const qtyInput    = clone.querySelector('.product-picker-qty');

        if (searchInput) searchInput.value = productLabel;
        if (hiddenInput) hiddenInput.value  = productId;
        if (qtyInput && quantity) qtyInput.value = quantity;

        const row = clone.querySelector('.product-picker-row');
        if (!(allCatalogCheck?.checked)) {
            row.dataset.skuPrefix = RAW_PREFIX;
        }

        container.appendChild(clone);
        if (window.ProductPicker) window.ProductPicker.initRow(row);

        rowIndex++;
        updateTotal();
    }

    addBtn.addEventListener('click', () => addRow());

    // Загружаем существующие позиции
    if (EXISTING_ITEMS.length) {
        EXISTING_ITEMS.forEach(i => addRow(i.product_id, i.product_label, i.quantity));
    } else {
        addRow();
    }

    // ── Валидация перед отправкой ─────────────────────────────────────────────
    document.getElementById('editOrderForm').addEventListener('submit', function (e) {
        const rows = container.querySelectorAll('.product-picker-row');
        if (!rows.length) {
            alert('Добавьте хотя бы одну позицию сырья');
            e.preventDefault(); return;
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
        if (!ok) { alert('Заполните все поля сырья'); e.preventDefault(); }
    });

});
</script>

{{-- Шаблон строки сырья --}}
<template id="rawPickerRowTemplate">
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
                           placeholder="Название сырья..."
                           autocomplete="off"
                           data-hidden-id="pid___IDX__"
                           required>
                    <button type="button"
                            class="btn btn-outline-secondary product-picker-tree-btn"
                            data-modal="rawmodal___IDX__"
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

        {{-- Строка 2: количество --}}
        <div class="d-flex gap-2 align-items-center">
            <div class="input-group input-group-sm" style="width:150px;flex-shrink:0">
                <span class="input-group-text" style="font-size:.75rem">м³</span>
                <input type="number"
                       id="qty___IDX__"
                       name="products[__IDX__][quantity]"
                       class="form-control product-picker-qty"
                       placeholder="0.000"
                       step="0.001" min="0.001"
                       data-tpl-index="__IDX__"
                       required>
            </div>
            <input type="hidden"
                   id="pid___IDX__"
                   name="products[__IDX__][product_id]"
                   data-tpl-index="__IDX__">
        </div>

        {{-- Модальное окно дерева --}}
        <div class="modal fade" id="rawmodal___IDX__" tabindex="-1" data-tpl-index="__IDX__">
            <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
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

@vite(['resources/js/product-picker.js'])
@endpush
@endsection
