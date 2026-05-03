@extends('layouts.app')
@section('title', 'Упаковка #' . $packaging->id)

@section('content')
<div class="container py-3 py-md-4" style="max-width:980px">

    <x-page-header title="Упаковка #{{ $packaging->id }}" :back-url="route('packagings.index')" mobileTitle="Упаковка" />

    @include('partials.alerts')

    <form method="POST" action="{{ route('packagings.update', $packaging) }}" id="packagingEditForm">
        @csrf
        @method('PUT')

        {{-- Блок: Участники и склад (свёрнутый) --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white py-2" role="button" id="peopleToggle">
                <span class="small fw-semibold text-muted"><i class="bi bi-people me-1"></i> Участники и склад</span>
                <i class="bi bi-chevron-down float-end" id="peopleChevron"></i>
            </div>
            <div class="card-body" id="peopleBody" style="display:none">
                <div class="row g-2">
                    <div class="col-12 col-sm-6">
                        <label class="form-label small fw-semibold mb-1">Упаковщик</label>
                        <input type="text" class="form-control form-control-sm" style="border-radius:.4rem" readonly
                               value="{{ $packaging->packer->name ?? '—' }}">
                        <input type="hidden" name="packer_id" value="{{ $packaging->packer_id }}">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label small fw-semibold mb-1">Приёмщик</label>
                        @if(auth()->user()->isAdmin())
                            <select name="receiver_id" class="form-select form-select-sm" style="border-radius:.4rem" required>
                                @foreach($masterWorkers as $worker)
                                    <option value="{{ $worker->id }}" {{ old('receiver_id', $packaging->receiver_id) == $worker->id ? 'selected' : '' }}>
                                        {{ $worker->name }}
                                    </option>
                                @endforeach
                            </select>
                        @else
                            <input type="text" class="form-control form-control-sm" style="border-radius:.4rem" readonly
                                   value="{{ $packaging->receiver->name ?? '—' }}">
                            <input type="hidden" name="receiver_id" value="{{ $packaging->receiver_id }}">
                        @endif
                    </div>
                </div>
                <div class="mt-2">
                    <label class="form-label small fw-semibold mb-1">Склад производства</label>
                    <input type="text" class="form-control form-control-sm" style="border-radius:.4rem" readonly
                           value="{{ $packaging->store->name ?? '—' }}">
                    <input type="hidden" name="store_id" value="{{ $packaging->store_id }}">
                </div>
            </div>
        </div>

        {{-- Блок: Упаковка продукта (множественный, с дельтами) --}}
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="small fw-semibold text-muted"><i class="bi bi-box me-1"></i> Упаковка продукта <span class="text-danger">*</span></span>
                </div>

                {{-- Существующие позиции --}}
                <div id="existingItems">
                    @foreach($packaging->items as $item)
                        @php $current = (float) $item->quantity; @endphp
                        <div class="existing-row d-flex gap-2 align-items-center mb-2 p-2 rounded border" data-current="{{ $current }}" data-product-id="{{ $item->product_id }}">
                            <div class="flex-grow-1">
                                <div class="fw-semibold" style="font-size:.85rem">{{ $item->product->name }}</div>
                                <small class="text-muted">{{ $item->product->sku }}</small>
                            </div>
                            <div class="d-flex align-items-center gap-1" style="font-size:.85rem">
                                <span>{{ number_format($current, 3, '.', '') }}</span>
                                <span>+</span>
                                <input type="number" class="form-control form-control-sm js-delta" style="width:90px;border-radius:.4rem"
                                       step="0.001" value="0" placeholder="0">
                                <span>=</span>
                                <span class="js-result fw-semibold">{{ number_format($current, 3, '.', '') }}</span>
                                <span class="text-muted">м²</span>
                            </div>
                            <input type="hidden" name="products[__P_{{ $loop->index }}__][product_id]" value="{{ $item->product_id }}" class="js-product-id">
                            <input type="hidden" name="products[__P_{{ $loop->index }}__][quantity]" value="{{ $current }}" class="js-final-quantity">
                            <input type="hidden" name="products[__P_{{ $loop->index }}__][is_undercut]" value="{{ $item->is_undercut ? 1 : 0 }}">
                        </div>
                    @endforeach
                </div>

                {{-- Новые позиции --}}
                <div id="newItems"></div>

                <button type="button" class="btn btn-sm btn-outline-primary mt-1" id="addProductBtn">
                    <i class="bi bi-plus-circle"></i> Добавить продукт
                </button>
            </div>
        </div>

        {{-- Блок: Упаковка (тара) — нельзя менять тип, можно менять количество --}}
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <span class="small fw-semibold text-muted d-block mb-2"><i class="bi bi-box-seam me-1"></i> Упаковка (тара)</span>

                <div class="row g-2 align-items-end">
                    <div class="col-12 col-sm-7">
                        <label class="form-label small text-muted mb-1">Вариант упаковки</label>
                        <input type="text" class="form-control form-control-sm" style="border-radius:.4rem" readonly
                               value="{{ $packaging->packageProduct->name ?? '—' }}">
                        <input type="hidden" name="package_product_id" value="{{ $packaging->package_product_id }}">
                    </div>
                    <div class="col-12 col-sm-5">
                        <label class="form-label small text-muted mb-1">Количество тары, шт</label>
                        <div class="d-flex align-items-center gap-1" style="font-size:.85rem">
                            <span>{{ number_format((float) $packaging->package_quantity, 0) }}</span>
                            <span>+</span>
                            <input type="number" name="package_quantity_delta" id="packageQtyDelta" step="0.001" value="0"
                                   class="form-control form-control-sm" style="width:90px;border-radius:.4rem">
                            <span>=</span>
                            <span id="packageQtyResult" class="fw-semibold">{{ number_format((float) $packaging->package_quantity, 0) }}</span>
                            <span class="text-muted">шт</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Блок: Примечание --}}
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <label class="form-label small fw-semibold mb-1">Примечание</label>
                <textarea name="notes" rows="2" class="form-control form-control-sm" style="border-radius:.4rem">{{ old('notes', $packaging->notes) }}</textarea>
            </div>
        </div>

        {{-- Блок: Дата создания (только для админа) --}}
        <x-admin-date-field :value="$packaging->created_at?->format('Y-m-d\TH:i')" />

        {{-- Кнопки --}}
        <div class="d-flex flex-column flex-sm-row gap-2 mt-3">
            <button type="submit" class="btn btn-primary btn-sm flex-fill">
                <i class="bi bi-save"></i> Сохранить
            </button>
            <button type="submit" name="close_packaging" value="1" class="btn btn-warning btn-sm flex-fill">
                <i class="bi bi-check2-circle"></i> Сохранить + Закрыть упаковку
            </button>
        </div>
    </form>
</div>

{{-- Шаблон новой строки --}}
<template id="newRowTemplate">
    <div class="product-picker-row d-flex gap-2 align-items-start mb-2 p-2 rounded border new-product-row">
        <div class="flex-grow-1 position-relative">
            <div class="input-group input-group-sm">
                <input type="text" class="form-control product-picker-search" style="border-radius:.4rem"
                       placeholder="Введите название..." autocomplete="off"
                       data-hidden-id="hidden___IDX__">
                <button type="button" class="btn btn-outline-secondary product-picker-tree-btn"
                        data-modal="modal___IDX__" data-hidden-id="hidden___IDX__" data-search-id="search___IDX__">
                    <i class="bi bi-diagram-3"></i>
                </button>
            </div>
            <div class="product-picker-dropdown list-group shadow-sm"
                 style="display:none;position:absolute;z-index:1050;width:100%;max-height:280px;overflow-y:auto"></div>
        </div>
        <input type="hidden" id="hidden___IDX__" name="products[__N_IDX__][product_id]" required>
        <div style="width:120px">
            <input type="number" name="products[__N_IDX__][quantity]" step="0.001" min="0.001" required
                   class="form-control form-control-sm product-picker-qty" style="border-radius:.4rem"
                   placeholder="м²">
        </div>
        <button type="button" class="btn btn-sm btn-outline-danger product-picker-remove">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
</template>
@endsection

@push('scripts')
@vite(['resources/js/product-picker.js'])
<script>
(function () {
    // Тоггл блока «Участники и склад»
    const pToggle = document.getElementById('peopleToggle');
    const pBody   = document.getElementById('peopleBody');
    const pChev   = document.getElementById('peopleChevron');
    pToggle.addEventListener('click', () => {
        const open = pBody.style.display === 'none';
        pBody.style.display = open ? '' : 'none';
        pChev.className     = open ? 'bi bi-chevron-up float-end' : 'bi bi-chevron-down float-end';
    });

    // Дельты существующих позиций
    document.querySelectorAll('.existing-row').forEach(row => {
        const current = parseFloat(row.dataset.current) || 0;
        const delta   = row.querySelector('.js-delta');
        const result  = row.querySelector('.js-result');
        const final   = row.querySelector('.js-final-quantity');

        function update() {
            const d = parseFloat(delta.value) || 0;
            const total = current + d;
            if (total < 0) {
                delta.classList.add('is-invalid');
            } else {
                delta.classList.remove('is-invalid');
            }
            result.textContent = total.toFixed(3);
            final.value = total.toFixed(3);
        }
        delta.addEventListener('input', update);
    });

    // Дельта тары
    const packageQty = parseFloat(@json((float) $packaging->package_quantity)) || 0;
    const packageDelta  = document.getElementById('packageQtyDelta');
    const packageResult = document.getElementById('packageQtyResult');
    packageDelta.addEventListener('input', () => {
        const d = parseFloat(packageDelta.value) || 0;
        const total = packageQty + d;
        if (total < 0) {
            packageDelta.classList.add('is-invalid');
        } else {
            packageDelta.classList.remove('is-invalid');
        }
        packageResult.textContent = total.toFixed(3).replace(/\.?0+$/, '');
    });

    // Добавление новых продуктов
    const newItems = document.getElementById('newItems');
    const addBtn   = document.getElementById('addProductBtn');
    let rowIndex = 0;

    addBtn.addEventListener('click', () => {
        const tpl   = document.getElementById('newRowTemplate');
        const clone = tpl.content.cloneNode(true);

        clone.querySelectorAll('*').forEach(el => {
            ['id','name','for','data-hidden-id','data-search-id','data-modal'].forEach(attr => {
                if (el.hasAttribute(attr)) {
                    el.setAttribute(attr, el.getAttribute(attr).replace(/__IDX__|__N_IDX__/g, 'new_' + rowIndex));
                }
            });
        });

        const row = clone.querySelector('.new-product-row');
        newItems.appendChild(clone);
        if (window.ProductPicker) window.ProductPicker.initRow(row);

        rowIndex++;
    });
})();
</script>
@endpush
