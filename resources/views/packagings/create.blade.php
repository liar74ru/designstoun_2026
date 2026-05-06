@extends('layouts.app')
@section('title', 'Новая упаковка')

@section('content')
@php $userDeptId = auth()->user()?->worker?->department_id; @endphp
<div class="container py-3 py-md-4" style="max-width:980px">

    <x-page-header title="Новая упаковка" :back-url="route('packagings.index')" mobileTitle="Упаковка" />

    @include('partials.alerts')

    <form method="POST" action="{{ route('packagings.store') }}" id="packagingForm">
        @csrf

        <div class="row g-3">
            <div class="col-12 col-lg-7">

                {{-- Блок: Участники --}}
                <div class="card shadow-sm mb-3">
                    <div class="card-body">
                        @if($userDeptId)
                            <div class="d-flex justify-content-end mb-1">
                                <div class="form-check form-check-inline mb-0">
                                    <input class="form-check-input" type="checkbox" id="allWorkersParticipantsPack">
                                    <label class="form-check-label small text-muted" for="allWorkersParticipantsPack">все работники</label>
                                </div>
                            </div>
                        @endif
                        <div class="row g-2">
                            <div class="col-12 col-sm-6">
                                <label for="packerSelect" class="form-label small fw-semibold mb-1">Упаковщик <span class="text-danger">*</span></label>
                                <select name="packer_id" id="packerSelect"
                                        class="form-select form-select-sm worker-picker"
                                        style="border-radius:.4rem"
                                        data-user-dept-id="{{ $userDeptId }}"
                                        data-toggle-id="allWorkersParticipantsPack"
                                        required>
                                    <option value="">— упаковщик —</option>
                                    @foreach($packers as $worker)
                                        <option value="{{ $worker->id }}"
                                            data-store-id="{{ optional($worker->department?->defaultProductionStore)->id }}"
                                            data-store-name="{{ optional($worker->department?->defaultProductionStore)->name }}"
                                            data-department-id="{{ $worker->department_id }}"
                                            {{ old('packer_id', $selectedPackerId ?? '') == $worker->id ? 'selected' : '' }}>
                                            {{ $worker->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label small fw-semibold mb-1">Приёмщик <span class="text-danger">*</span></label>
                                <select name="receiver_id"
                                        class="form-select form-select-sm worker-picker"
                                        style="border-radius:.4rem"
                                        data-user-dept-id="{{ $userDeptId }}"
                                        data-toggle-id="allWorkersParticipantsPack"
                                        required>
                                    @foreach($masterWorkers as $worker)
                                        <option value="{{ $worker->id }}"
                                            data-department-id="{{ $worker->department_id }}"
                                            {{ old('receiver_id', auth()->user()->worker_id) == $worker->id ? 'selected' : '' }}>
                                            {{ $worker->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="mt-2">
                            <label class="form-label small fw-semibold mb-1">Склад производства</label>
                            <input type="text" id="storeNameInput" class="form-control form-control-sm" style="border-radius:.4rem"
                                   readonly value="{{ $defaultStore?->name ?? 'Выберите упаковщика' }}">
                            <input type="hidden" name="store_id" id="storeHidden" value="{{ $defaultStore?->id }}">
                            <div class="form-text" style="font-size:.7rem">Берётся из настроек отдела упаковщика (склад производства).</div>
                        </div>
                    </div>
                </div>

                {{-- Блок: Продукт упаковки --}}
                <div class="card shadow-sm mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small fw-semibold text-muted"><i class="bi bi-box me-1"></i> Продукт упаковки <span class="text-danger">*</span></span>
                            <span class="text-muted small">Итого: <strong id="totalQty">0</strong></span>
                        </div>

                        <div id="productsContainer"></div>

                        <button type="button" class="btn btn-sm btn-outline-primary mt-1" id="addProductBtn">
                            <i class="bi bi-plus-circle"></i> Добавить продукт
                        </button>
                    </div>
                </div>

                {{-- Блок: Упаковка (тара) --}}
                <div class="card shadow-sm mb-3">
                    <div class="card-body">
                        <span class="small fw-semibold text-muted d-block mb-2"><i class="bi bi-box-seam me-1"></i> Упаковка (тара) <span class="text-danger">*</span></span>

                        <div class="row g-2">
                            <div class="col-12 col-sm-8">
                                <label class="form-label small text-muted mb-1">Вариант упаковки</label>
                                @include('partials.product-picker', [
                                    'id'          => 'package',
                                    'name'        => 'package_product_id',
                                    'value'       => old('package_product_id'),
                                    'label'       => '',
                                    'placeholder' => 'Введите вариант упаковки...',
                                    'skuPrefix'   => '07-03',
                                    'showTree'    => true,
                                    'required'    => true,
                                ])
                            </div>
                            <div class="col-12 col-sm-4">
                                <label class="form-label small text-muted mb-1">Количество, шт</label>
                                <input type="number" name="package_quantity" step="0.001" min="0.001" required
                                       class="form-control form-control-sm" style="border-radius:.4rem"
                                       value="{{ old('package_quantity', 1) }}">
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Блок: Примечание --}}
                <div class="card shadow-sm mb-3">
                    <div class="card-body">
                        <label class="form-label small fw-semibold mb-1">Примечание</label>
                        <textarea name="notes" rows="2" class="form-control form-control-sm" style="border-radius:.4rem">{{ old('notes') }}</textarea>
                    </div>
                </div>

                {{-- Блок: Техоперация МойСклад --}}
                <div class="card shadow-sm mb-3">
                    <div class="card-header bg-white py-2" role="button" id="msToggle">
                        <span class="small fw-semibold text-muted"><i class="bi bi-cloud me-1"></i> Техоперация МойСклад</span>
                        <i class="bi bi-chevron-down float-end" id="msChevron"></i>
                    </div>
                    <div class="card-body" id="msBody" style="display:none">
                        <label class="form-label small fw-semibold mb-1">Имя техоперации</label>
                        <input type="text" name="processing_name" id="processingNameInput" readonly
                               class="form-control form-control-sm" style="border-radius:.4rem"
                               placeholder="Сформируется автоматически: 26-XX-УПАК-NN">
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
                        <i class="bi bi-save"></i> Сохранить упаковку
                    </button>
                    <button type="submit" name="close_packaging" value="1" class="btn btn-warning btn-sm flex-fill">
                        <i class="bi bi-check2-circle"></i> Сохранить + Закрыть упаковку
                    </button>
                </div>
            </div>

            <div class="col-12 col-lg-5 d-none d-lg-block">
                <div class="card shadow-sm">
                    <div class="card-header bg-white py-2">
                        <span class="small fw-semibold text-muted">Подсказка</span>
                    </div>
                    <div class="card-body small text-muted">
                        <ol class="ps-3 mb-0">
                            <li>Выберите упаковщика — склад подставится из настроек отдела.</li>
                            <li>Добавьте продукт упаковки и его количество (м²).</li>
                            <li>Выберите вариант упаковки (07-03-xx) и количество тары (шт).</li>
                            <li>Сохраните: создастся техоперация в МойСклад с префиксом УПАК.</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

{{-- Шаблон строки продукта --}}
<template id="pickerRowTemplate">
    @include('partials.product-picker-row', [
        'index'       => '__IDX__',
        'placeholder' => 'Введите название...',
        'unit'        => 'м²',
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
    const container   = document.getElementById('productsContainer');
    const addBtn      = document.getElementById('addProductBtn');
    const totalQtyEl  = document.getElementById('totalQty');
    const packerSelect = document.getElementById('packerSelect');
    const storeHidden  = document.getElementById('storeHidden');
    const storeName    = document.getElementById('storeNameInput');

    let rowIndex = 0;

    function updateTotal() {
        let sum = 0;
        container.querySelectorAll('.product-picker-qty').forEach(el => sum += parseFloat(el.value) || 0);
        totalQtyEl.textContent = sum.toFixed(2);
    }

    function addRow(productId = '', productLabel = '', quantity = '') {
        const tpl   = document.getElementById('pickerRowTemplate');
        const clone = tpl.content.cloneNode(true);

        clone.querySelectorAll('*').forEach(el => {
            ['id','name','for','data-hidden-id','data-search-id','data-modal'].forEach(attr => {
                if (el.hasAttribute(attr)) {
                    el.setAttribute(attr, el.getAttribute(attr).replace('__IDX__', rowIndex));
                }
            });
        });

        const search = clone.querySelector('.product-picker-search');
        const hidden = clone.querySelector('input[type="hidden"]');
        const qty    = clone.querySelector('.product-picker-qty');

        if (productLabel) search.value = productLabel;
        if (productId)    hidden.value = productId;
        if (quantity)     qty.value    = quantity;

        const row = clone.querySelector('.product-picker-row');
        container.appendChild(clone);
        if (window.ProductPicker) window.ProductPicker.initRow(row);

        rowIndex++;
        updateTotal();
    }

    addBtn.addEventListener('click', () => addRow());
    document.addEventListener('product-picker:removed', updateTotal);
    container.addEventListener('input', e => {
        if (e.target.classList.contains('product-picker-qty')) updateTotal();
    });

    addRow();

    // Подтягиваем склад при смене упаковщика
    function syncStoreFromPacker() {
        const opt = packerSelect.options[packerSelect.selectedIndex];
        if (!opt) return;
        const sid   = opt.dataset.storeId   || '';
        const sname = opt.dataset.storeName || 'Склад не задан в отделе';
        storeHidden.value  = sid;
        storeName.value    = sname;
    }
    packerSelect.addEventListener('change', syncStoreFromPacker);
    if (packerSelect.value) syncStoreFromPacker();

    // Тоггл блока МойСклад
    const msToggle  = document.getElementById('msToggle');
    const msBody    = document.getElementById('msBody');
    const msChevron = document.getElementById('msChevron');
    msToggle.addEventListener('click', () => {
        const open = msBody.style.display === 'none';
        msBody.style.display = open ? '' : 'none';
        msChevron.className  = open ? 'bi bi-chevron-up float-end' : 'bi bi-chevron-down float-end';
    });

    const manualCb = document.getElementById('manualProcessingName');
    const procName = document.getElementById('processingNameInput');
    manualCb?.addEventListener('change', () => {
        procName.readOnly = !manualCb.checked;
    });
})();
</script>
@endpush
