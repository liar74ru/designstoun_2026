@extends('layouts.app')

@php
    $isEdit = $order !== null;
    $title  = $isEdit ? 'Внутренний заказ ' . $order->name : 'Заказать полуфабрикат';
    $backUrl = $isEdit
        ? route('orders.show', $order->uuid)
        : route('orders.show', $parent->uuid);
    $fmt1 = fn ($v) => number_format((float) $v, 1, '.', '');
    $hasSuggestions = collect($suggestions)->isNotEmpty();
@endphp

@section('title', $title)

@section('content')
<div class="container py-3 py-md-4">

    <x-page-header
        title="🔁 {{ $title }}"
        :mobileTitle="$title"
        :backUrl="$backUrl"
        :backLabel="$isEdit ? 'К заказу' : 'К заявке'" />

    @include('partials.alerts')

    <div class="row g-3">

        {{-- ═══════════════════════ ФОРМА ═══════════════════════ --}}
        <div class="col-12 col-lg-7">
            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <style>
                        #internalOrderForm .form-control,
                        #internalOrderForm .form-select { border-radius: .4rem; }
                    </style>
                    <form method="POST" id="internalOrderForm" data-submit-guard
                          action="{{ $isEdit ? route('orders.internal.update', $order->uuid) : route('orders.internal.store', $parent->uuid) }}">
                        @csrf
                        @if($isEdit) @method('PUT') @endif

                        @if($errors->any())
                            <div class="alert alert-danger py-2 m-2">
                                @foreach($errors->all() as $error)
                                    <div class="small">{{ $error }}</div>
                                @endforeach
                            </div>
                        @endif

                        {{-- Блок 1: кто кому --}}
                        <div class="info-block">
                            <div class="info-block-header">
                                <span class="small fw-semibold text-muted">Заказ</span>
                            </div>
                            <div class="info-block-body">
                                <div class="row g-2">

                                    <div class="col-12 col-md-6">
                                        <label class="form-label small fw-semibold mb-1">Заказчик</label>
                                        @if($isEdit)
                                            <div class="form-control bg-light">{{ $order->customerDepartment?->name ?? '—' }}</div>
                                        @else
                                            <select name="customer_department_id" class="form-select" required>
                                                @foreach($customerDepartments as $dept)
                                                    <option value="{{ $dept->id }}" @selected((int) old('customer_department_id') === $dept->id)>
                                                        {{ $dept->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        @endif
                                    </div>

                                    <div class="col-12 col-md-6">
                                        <label class="form-label small fw-semibold mb-1">
                                            Исполнитель <span class="text-danger">*</span>
                                        </label>
                                        @php $executorId = (int) old('executor_department_id', $isEdit ? $order->departments->first()?->id : 0); @endphp
                                        <select name="executor_department_id" class="form-select" required>
                                            <option value="">— Выберите отдел —</option>
                                            @foreach($executorDepartments as $dept)
                                                <option value="{{ $dept->id }}" @selected($executorId === $dept->id)>{{ $dept->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    @unless($isEdit)
                                        <div class="col-12 col-md-6">
                                            <label class="form-label small fw-semibold mb-1">Статус</label>
                                            @php $stateId = old('state_id', $defaultStateId); @endphp
                                            <select name="state_id" class="form-select" required>
                                                @foreach($states as $state)
                                                    <option value="{{ $state->id }}" @selected($stateId === $state->id)>{{ $state->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @endunless

                                    <div class="col-12 col-md-6">
                                        <label class="form-label small fw-semibold mb-1">Срок</label>
                                        @php
                                            $deliverySource = $isEdit ? $order->delivery_planned_at : $parent?->delivery_planned_at;
                                        @endphp
                                        <input type="date" name="delivery_planned_at" class="form-control"
                                               value="{{ old('delivery_planned_at', $deliverySource?->format('Y-m-d')) }}">
                                    </div>

                                </div>
                                @if($isEdit)
                                    <div class="form-text small mt-2">
                                        <i class="bi bi-info-circle"></i>
                                        Заказ уже в работе и количество изменилось — он встанет в статус «Изменено»,
                                        пока исполнитель не нажмёт «Принято».
                                    </div>
                                @endif
                            </div>
                        </div>

                        {{-- Блок 2: полуфабрикаты --}}
                        <div class="info-block">
                            <div class="info-block-header d-flex justify-content-between align-items-center">
                                <span class="small fw-semibold text-muted">
                                    Полуфабрикаты <span class="text-danger">*</span>
                                </span>
                            </div>
                            <div class="info-block-body">
                                <div id="itemsContainer" style="margin-bottom:.25rem"></div>
                                <button type="button" class="btn btn-sm btn-outline-primary mt-1" id="addItemBtn">
                                    <i class="bi bi-plus-circle"></i> Добавить позицию
                                </button>
                            </div>
                        </div>

                        <div class="p-2 d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-send"></i> {{ $isEdit ? 'Сохранить' : 'Разместить заказ' }}
                            </button>
                            <a href="{{ $backUrl }}" class="btn btn-outline-secondary">Отмена</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- ═══════════════════════ ЗАЯВКА-ОСНОВАНИЕ ═══════════════════════ --}}
        <div class="col-12 col-lg-5">
            <div class="card shadow-sm">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center gap-2">
                    <span class="fw-semibold small">
                        <i class="bi bi-link-45deg me-1"></i>
                        @if($parent)
                            Заявка {{ $parent->name }}
                        @else
                            Заявка-основание {{ $order?->parent_order_name }} закрыта
                        @endif
                    </span>
                    @if($hasSuggestions)
                        <button type="button" class="btn btn-sm btn-outline-primary" id="applyAllPresetsBtn"
                                title="Подставить сырьё по первому шаблону каждой позиции">
                            <i class="bi bi-magic"></i> Заполнить по шаблонам
                        </button>
                    @endif
                </div>
                <div class="list-group list-group-flush">
                    @forelse($parentRows->filter(fn ($row) => $row['product']) as $row)
                        @php
                            $productId = $row['product']->id;
                            $bg = $row['color'] === '#FFFFFF' ? '' : 'background:' . $row['color'] . '18;';
                        @endphp
                        <div class="list-group-item px-2 py-2"
                             style="border-left:4px solid {{ $row['color'] }};{{ $bg }}">
                            <div class="small fw-semibold">{{ $row['name'] }}</div>
                            <div class="text-muted" style="font-size:.75rem">
                                заказ {{ $fmt1($row['ordered']) }}{{ $row['item']->uom_name ? ' ' . $row['item']->uom_name : '' }}
                                @if($row['short'] !== null)
                                    · не хватает <span class="{{ $row['short'] > 0 ? 'text-danger fw-semibold' : '' }}">{{ $fmt1($row['short']) }}</span>
                                @endif
                            </div>
                            @foreach($suggestions[$productId] ?? [] as $i => $suggestion)
                                <button type="button"
                                        class="btn btn-sm btn-outline-secondary mt-1 apply-preset-btn"
                                        style="font-size:.75rem"
                                        data-position="{{ $productId }}"
                                        data-first="{{ $i === 0 ? 1 : 0 }}"
                                        data-items="{{ json_encode($suggestion['items'], JSON_UNESCAPED_UNICODE) }}">
                                    <i class="bi bi-magic"></i> Из шаблона «{{ $suggestion['preset'] }}»
                                </button>
                            @endforeach
                        </div>
                    @empty
                        <div class="text-center py-4 text-muted small">Позиций нет</div>
                    @endforelse
                </div>
                @if($hasSuggestions)
                    <div class="card-footer bg-white small text-muted">
                        Шаблон подставит сырьё по норме на недостающее количество позиции.
                        Одинаковое сырьё разных позиций складывается в одну строку.
                    </div>
                @endif
            </div>
        </div>

    </div>
</div>

{{-- Шаблон строки полуфабриката --}}
<template id="tplItem">
    @include('partials.product-picker-row', [
        'name' => 'items', 'index' => '__IDX__',
        'placeholder' => 'Введите название полуфабриката...', 'unit' => 'м²',
        'dynamicUnit' => true, 'qtyWidth' => '140px', 'qtyMode' => 'simple', 'showRemove' => true,
    ])
</template>
@endsection

@push('scripts')
@vite(['resources/js/product-picker.js'])
<script>
(function () {
    const container = document.getElementById('itemsContainer');
    const initial   = @json($items);
    let rowIndex = 0;

    // init = false — строка добавлена до DOMContentLoaded: её инициализирует сам product-picker.js,
    // повторный initRow навесил бы обработчики дважды.
    function addItem({ product_id = '', label = '', quantity = '' } = {}, init = true) {
        const clone = document.getElementById('tplItem').content.cloneNode(true);

        clone.querySelectorAll('[data-tpl-index]').forEach(el => {
            ['id', 'name', 'for', 'data-hidden-id', 'data-search-id', 'data-modal'].forEach(attr => {
                if (el.hasAttribute(attr)) {
                    el.setAttribute(attr, el.getAttribute(attr).replace('__IDX__', rowIndex));
                }
            });
        });

        const row = clone.querySelector('.product-picker-row');
        row.querySelector('.product-picker-search').value = label;
        row.querySelector('input[type="hidden"]').value   = product_id;
        row.querySelector('.product-picker-qty').value    = quantity;

        container.appendChild(clone);
        if (init && window.ProductPicker) window.ProductPicker.initRow(row);

        rowIndex++;
    }

    const rows     = () => Array.from(container.querySelectorAll('.product-picker-row'));
    const pidOf    = row => row.querySelector('input[type="hidden"]').value;
    const qtyInput = row => row.querySelector('.product-picker-qty');

    // Сырьё из шаблона: такое уже есть в составе — прибавляем количество, иначе новая строка.
    // Пустые строки (без товара и количества) убираем, чтобы не мешали.
    function applyItems(items) {
        rows().forEach(row => { if (! pidOf(row) && ! qtyInput(row).value) row.remove(); });

        items.forEach(item => {
            const existing = rows().find(row => pidOf(row) === String(item.product_id));
            if (existing) {
                const sum = (parseFloat(qtyInput(existing).value) || 0) + item.quantity;
                qtyInput(existing).value = Math.round(sum * 1000) / 1000;
            } else {
                addItem(item);
            }
        });
    }

    // Шаблон позиции применяется один раз: повторный клик удвоил бы количество.
    function applyPreset(btn) {
        applyItems(JSON.parse(btn.dataset.items));
        document.querySelectorAll(`.apply-preset-btn[data-position="${btn.dataset.position}"]`)
            .forEach(b => { b.disabled = true; });
        btn.classList.replace('btn-outline-secondary', 'btn-success');
    }

    document.getElementById('addItemBtn').addEventListener('click', () => addItem());

    document.querySelectorAll('.apply-preset-btn').forEach(btn => {
        btn.addEventListener('click', () => applyPreset(btn));
    });

    document.getElementById('applyAllPresetsBtn')?.addEventListener('click', function () {
        document.querySelectorAll('.apply-preset-btn[data-first="1"]:not(:disabled)').forEach(applyPreset);
        this.disabled = true;
    });

    initial.forEach(item => addItem(item, false));
    if (! initial.length) addItem({}, false);
})();
</script>
@endpush
