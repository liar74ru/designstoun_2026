@extends('layouts.app')

@section('title', 'Новое поступление сырья')

@section('content')
<div class="container py-3 py-md-4">

    <x-page-header
        title="➕ Новое поступление сырья"
        back-url="{{ route('supplier-orders.index') }}"
        back-label="К списку"
    />

    @include('partials.alerts')

    <div class="row g-3">

        {{-- ═══════════════════════ ФОРМА ═══════════════════════ --}}
        <div class="col-12 col-lg-7">
            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <style>
                        #supplierOrderForm .form-control,
                        #supplierOrderForm .form-select { border-radius: .4rem; }
                    </style>
                    <form method="POST" action="{{ route('supplier-orders.store') }}" id="supplierOrderForm">
                        @csrf

                        @if($errors->any())
                            <div class="alert alert-danger py-2 m-2">
                                @foreach($errors->all() as $error)
                                    <div class="small">{{ $error }}</div>
                                @endforeach
                            </div>
                        @endif

                        {{-- Блок 1: Детали заказа --}}
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
                                                   value="{{ old('counterparty_id') ? ($counterparties->firstWhere('id', old('counterparty_id'))?->name ?? '') : '' }}"
                                                   required>
                                            <input type="hidden"
                                                   id="counterpartyId"
                                                   name="counterparty_id"
                                                   value="{{ old('counterparty_id') }}">
                                            <div id="counterpartyDropdown"
                                                 class="list-group shadow-sm"
                                                 style="display:none;position:absolute;z-index:1050;width:100%;max-height:250px;overflow-y:auto">
                                            </div>
                                        </div>
                                        @error('counterparty_id')
                                            <div class="text-danger small mt-1">{{ $message }}</div>
                                        @enderror
                                        @if($counterparties->isEmpty())
                                            <div class="form-text text-warning">
                                                <i class="bi bi-exclamation-triangle"></i>
                                                Нет контрагентов.
                                                <a href="{{ route('counterparties.sync') }}"
                                                   onclick="event.preventDefault(); document.getElementById('sync-cp-form').submit()">
                                                    Синхронизировать
                                                </a>
                                            </div>
                                            <form id="sync-cp-form" action="{{ route('counterparties.sync') }}" method="POST" class="d-none">@csrf</form>
                                        @endif
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
                                                    {{ old('store_id', $defaultStore?->id) == $store->id ? 'selected' : '' }}>
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

                        {{-- Блок 3: Прочее (номер, мастер-приёмщик, примечание) — скрыто по умолчанию --}}
                        <div class="info-block">
                            <div class="info-block-header d-flex justify-content-between align-items-center"
                                 id="extraToggle" style="cursor:pointer" role="button">
                                <span class="small fw-semibold text-muted">Прочее</span>
                                <i class="bi bi-chevron-down" id="extraChevron"></i>
                            </div>
                            <div id="extraBody" style="display:none">
                                <div class="info-block-body">
                                    <div class="row g-2">

                                        {{-- Номер поступления --}}
                                        <div class="col-12">
                                            <div class="d-flex align-items-center justify-content-between mb-1">
                                                <label class="form-label small fw-semibold mb-0">
                                                    Номер партии <span class="text-danger">*</span>
                                                </label>
                                                <div class="form-check form-switch mb-0">
                                                    <input class="form-check-input" type="checkbox"
                                                           id="manualNumberCheck" role="switch">
                                                    <label class="form-check-label small text-muted" for="manualNumberCheck">
                                                        Задать вручную
                                                    </label>
                                                </div>
                                            </div>
                                            <input type="text"
                                                   id="orderNumberInput"
                                                   name="number"
                                                   class="form-control form-control-sm @error('number') is-invalid @enderror"
                                                   value="{{ old('number') }}"
                                                   placeholder="Загрузка..."
                                                   readonly required>
                                            <div class="form-text text-muted small" id="numberHint">
                                                Формат: ГГ-НН-ПРОГ-ПП
                                            </div>
                                            @error('number')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>

                                        {{-- Мастер-приёмщик --}}
                                        <div class="col-12">
                                            <label class="form-label small fw-semibold mb-1">Мастер-приёмщик</label>
                                            <select name="receiver_id"
                                                    class="form-select form-select-sm @error('receiver_id') is-invalid @enderror"
                                                    style="font-size:.8rem;padding:.18rem .35rem;border-radius:.4rem">
                                                <option value="">— Не указан —</option>
                                                @foreach($receivers as $worker)
                                                    <option value="{{ $worker->id }}"
                                                        {{ old('receiver_id', $defaultReceiver?->id) == $worker->id ? 'selected' : '' }}>
                                                        {{ $worker->name }}
                                                        @if($worker->position) ({{ $worker->position }}) @endif
                                                    </option>
                                                @endforeach
                                            </select>
                                            @error('receiver_id')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>

                                        {{-- Примечание --}}
                                        <div class="col-12">
                                            <label class="form-label small fw-semibold mb-1">Примечание</label>
                                            <textarea name="note" class="form-control form-control-sm" rows="2">{{ old('note') }}</textarea>
                                        </div>

                                    </div>
                                </div>
                            </div>
                        </div>

                        <x-admin-date-field />

                        <div class="p-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-send"></i> Создать поступление сырья
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- ═══════════════════════ ПОСЛЕДНИЕ ПОСТУПЛЕНИЯ ═══════════════════════ --}}
        <div class="col-12 col-lg-5">
            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-2"
                     id="recentOrdersToggle" style="cursor:pointer" role="button">
                    <span class="fw-semibold small">
                        <i class="bi bi-clock-history me-1"></i> Последние поступления
                    </span>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-secondary" id="recentOrdersBadge">{{ $recentOrders->count() }}</span>
                        <i class="bi bi-chevron-down d-md-none" id="recentOrdersChevron"></i>
                    </div>
                </div>

                <div id="recentOrdersBody">
                    <div class="list-group list-group-flush">
                        @forelse($recentOrders as $order)
                            @php
                                $firstItem = $order->items->first();
                                $skuColor = \App\Models\Product::getColorBySku($firstItem?->product?->sku ?? null);
                                $skuBg    = $skuColor === '#FFFFFF' ? '' : 'background:' . $skuColor . '18;';
                                $copyData = json_encode([
                                    'counterparty_id'   => $order->counterparty_id,
                                    'counterparty_name' => $order->counterparty?->name ?? '',
                                    'store_id'          => $order->store_id,
                                    'products'          => $order->items->map(fn($i) => [
                                        'product_id'    => $i->product_id,
                                        'product_label' => $i->product?->name ?? '',
                                        'quantity'      => (float)$i->quantity,
                                    ])->values()->toArray(),
                                ], JSON_UNESCAPED_UNICODE);
                            @endphp
                            <div class="list-group-item px-2 py-2"
                                 data-counterparty-id="{{ $order->counterparty_id }}"
                                 style="border-left:4px solid {{ $skuColor }};border-right:4px solid {{ $skuColor }};{{ $skuBg }}">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1 me-2">
                                        <div class="small fw-semibold text-truncate" style="max-width:180px">
                                            {{ $order->counterparty?->name ?? '—' }}
                                        </div>
                                        @foreach($order->items as $item)
                                            <div class="text-muted" style="font-size:.75rem">
                                                {{ $item->product?->name ?? '—' }}
                                                <span class="text-dark">× {{ number_format($item->quantity, 3) }} м³</span>
                                            </div>
                                        @endforeach
                                    </div>
                                    <button type="button"
                                            class="btn btn-sm btn-outline-secondary copy-order-btn flex-shrink-0"
                                            data-order="{{ $copyData }}"
                                            style="width:28px;height:28px;padding:0;font-size:.75rem"
                                            title="Скопировать в форму">
                                        <i class="bi bi-copy"></i>
                                    </button>
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-4 text-muted" id="recentOrdersEmpty">
                                <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                                Нет поступлений
                            </div>
                        @endforelse
                        <div class="text-center py-4 text-muted" id="recentOrdersFilteredEmpty" style="display:none">
                            <i class="bi bi-funnel fs-3 d-block mb-1"></i>
                            Нет поступлений от этого поставщика
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
@endsection

@push('scripts')
<script>
// Данные контрагентов для поиска
const COUNTERPARTIES = @json($counterparties->map(fn($c) => ['id' => $c->id, 'name' => $c->name])->values());

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
            filterRecentOrders(null);
        });

        dropdownEl.addEventListener('click', function (e) {
            const btn = e.target.closest('.cp-item');
            if (!btn) return;
            searchEl.value = btn.dataset.name;
            hiddenEl.value = btn.dataset.id;
            dropdownEl.style.display = 'none';
            filterRecentOrders(btn.dataset.id);
        });

        document.addEventListener('click', function (e) {
            if (!searchEl.contains(e.target) && !dropdownEl.contains(e.target)) {
                dropdownEl.style.display = 'none';
            }
        });

        // Если после ошибки валидации поле заполнено, но ID уже есть — не трогаем
        searchEl.addEventListener('focus', function () {
            if (this.value.trim() && !hiddenEl.value) showMatches(this.value.trim());
        });

        // Применить фильтр при загрузке (если already выбран контрагент)
        if (hiddenEl.value) filterRecentOrders(hiddenEl.value);
    })();

    // ── Фильтрация последних поступлений по поставщику ───────────────────────
    function filterRecentOrders(counterpartyId) {
        const items         = document.querySelectorAll('#recentOrdersBody .list-group-item[data-counterparty-id]');
        const emptyAll      = document.getElementById('recentOrdersEmpty');
        const emptyFiltered = document.getElementById('recentOrdersFilteredEmpty');
        const badge         = document.getElementById('recentOrdersBadge');
        if (!counterpartyId) {
            items.forEach(el => el.style.display = '');
            if (emptyAll) emptyAll.style.display = '';
            if (emptyFiltered) emptyFiltered.style.display = 'none';
            if (badge) badge.textContent = items.length;
            return;
        }
        let visible = 0;
        items.forEach(el => {
            const show = el.dataset.counterpartyId == counterpartyId;
            el.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        if (emptyAll) emptyAll.style.display = 'none';
        if (emptyFiltered) emptyFiltered.style.display = (visible === 0 && items.length > 0) ? '' : 'none';
        if (badge) badge.textContent = visible;
    }

    // ── Блок «Прочее» (скрыт по умолчанию) ──────────────────────────────────
    (function () {
        const toggle  = document.getElementById('extraToggle');
        const body    = document.getElementById('extraBody');
        const chevron = document.getElementById('extraChevron');
        if (!toggle) return;
        @if(old('note') || old('number') || $errors->has('number') || $errors->has('receiver_id'))
            body.style.display = '';
            chevron.className  = 'bi bi-chevron-up';
        @endif
        toggle.addEventListener('click', function () {
            const isHidden = body.style.display === 'none';
            body.style.display = isHidden ? '' : 'none';
            chevron.className  = isHidden ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
        });
    })();

    // ── Сворачивание правой панели на мобильном ──────────────────────────────
    (function () {
        const toggle  = document.getElementById('recentOrdersToggle');
        const body    = document.getElementById('recentOrdersBody');
        const chevron = document.getElementById('recentOrdersChevron');
        const KEY = 'recent_supplier_orders_open';
        function isMobile() { return window.innerWidth < 992; }
        function applyState(open) {
            if (!isMobile()) { body.style.display = ''; return; }
            body.style.display = open ? '' : 'none';
            if (chevron) chevron.className = open ? 'bi bi-chevron-up d-md-none' : 'bi bi-chevron-down d-md-none';
        }
        applyState(localStorage.getItem(KEY) === 'open');
        toggle.addEventListener('click', function () {
            if (!isMobile()) return;
            const isHidden = body.style.display === 'none';
            applyState(isHidden);
            localStorage.setItem(KEY, isHidden ? 'open' : 'closed');
        });
        window.addEventListener('resize', () => applyState(localStorage.getItem(KEY) === 'open'));
    })();

    // ── Автогенерация номера ─────────────────────────────────────────────────
    const numberInput   = document.getElementById('orderNumberInput');
    const manualCheck   = document.getElementById('manualNumberCheck');
    const numberHint    = document.getElementById('numberHint');

    async function loadNextNumber() {
        try {
            const res  = await fetch('/api/supplier-orders/next-number', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await res.json();
            if (!manualCheck.checked) {
                numberInput.value       = data.number;
                numberHint.textContent  = 'Автономер: ' + data.number;
            }
        } catch (e) { console.error('Ошибка загрузки номера', e); }
    }

    manualCheck.addEventListener('change', function () {
        if (this.checked) {
            numberInput.readOnly = false;
            numberInput.classList.add('border-warning');
            numberHint.textContent = 'Введите номер вручную';
        } else {
            numberInput.readOnly = true;
            numberInput.classList.remove('border-warning');
            loadNextNumber();
        }
    });

    loadNextNumber();

    // ── Карта остатков (для бейджей в пикере) ────────────────────────────────
    fetch('/api/products/stocks', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => { window.ProductPickerStockMap = data; });

    // ── Итого м³ ─────────────────────────────────────────────────────────────
    const container      = document.getElementById('productsContainer');
    const totalQtyEl     = document.getElementById('totalQty');
    const addBtn         = document.getElementById('addProductBtn');
    const allCatalogCheck = document.getElementById('allCatalogCheck');
    const RAW_PREFIX     = '01-';
    let rowIndex = 0;

    // ── Чекбокс "весь каталог" ───────────────────────────────────────────────
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

    // ── Добавить строку сырья ────────────────────────────────────────────────
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

    // ── Данные для копирования (если пришли с кнопки «Копировать») ───────────
    @if(!empty($copyFrom))
    @php
        $copyFromData = [
            'counterparty_id'   => $copyFrom->counterparty_id,
            'counterparty_name' => $copyFrom->counterparty?->name ?? '',
            'store_id'          => $copyFrom->store_id,
            'products'          => $copyFrom->items->map(fn($i) => [
                'product_id'    => $i->product_id,
                'product_label' => $i->product?->name ?? '',
                'quantity'      => (float) $i->quantity,
            ])->values()->toArray(),
        ];
    @endphp
    (function () {
        const data = @json($copyFromData);
        const cpSearch = document.getElementById('counterpartySearch');
        const cpHidden = document.getElementById('counterpartyId');
        if (cpSearch && cpHidden && data.counterparty_id) {
            cpHidden.value = data.counterparty_id;
            cpSearch.value = data.counterparty_name || '';
        }
        const storeSelect = document.querySelector('select[name="store_id"]');
        if (storeSelect && data.store_id) storeSelect.value = data.store_id;
        if (data.products?.length) {
            data.products.forEach(p => addRow(p.product_id, p.product_label, p.quantity));
        } else {
            addRow();
        }
    })();
    @else
    // Добавить первую строку при загрузке
    addRow();
    @endif

    // ── Копирование из последних поступлений ─────────────────────────────────
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.copy-order-btn');
        if (!btn) return;
        try {
            const data = JSON.parse(btn.dataset.order || '{}');

            // Контрагент
            const cpSearch = document.getElementById('counterpartySearch');
            const cpHidden = document.getElementById('counterpartyId');
            if (cpSearch && cpHidden && data.counterparty_id) {
                cpHidden.value = data.counterparty_id;
                cpSearch.value = data.counterparty_name || '';
            }

            // Склад
            const storeSelect = document.querySelector('select[name="store_id"]');
            if (storeSelect && data.store_id) {
                storeSelect.value = data.store_id;
            }

            // Позиции сырья — сначала удаляем пустые строки, затем добавляем скопированные
            if (data.products?.length) {
                container.querySelectorAll('.product-picker-row').forEach(row => {
                    const pid = row.querySelector('input[type="hidden"][name*="product_id"]')?.value;
                    if (!pid) row.remove();
                });
                const existingIds = new Set(
                    [...container.querySelectorAll('input[type="hidden"][name*="product_id"]')]
                        .map(el => el.value).filter(Boolean)
                );
                data.products
                    .filter(p => !existingIds.has(String(p.product_id)))
                    .forEach(p => addRow(p.product_id, p.product_label, p.quantity));
            }

            window.scrollTo({ top: 0, behavior: 'smooth' });
        } catch (err) {
            console.error('copy-order-btn parse error', err);
        }
    });

    // ── Валидация перед отправкой ────────────────────────────────────────────
    document.getElementById('supplierOrderForm').addEventListener('submit', function (e) {
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

        {{-- Строка 2: количество в м³ --}}
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
