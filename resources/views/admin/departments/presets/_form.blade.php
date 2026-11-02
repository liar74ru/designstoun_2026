{{--
    Общая форма пресета цеха (create/edit).
    Параметры: $action, $method ('POST'|'PATCH'), $department, $preset (null при создании),
    $groups — группы текущего отдела, $allDepartments — активные отделы,
    $prefillItems — [{role, product_id, product_label, quantity}] (edit или old-инпут).
--}}
<form method="POST" action="{{ $action }}" id="presetForm">
    @csrf
    @if($method !== 'POST')
        @method($method)
    @endif

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <label for="presetName" class="form-label fw-semibold">
                Название <span class="text-danger">*</span>
            </label>
            <input type="text"
                   id="presetName" name="name"
                   value="{{ old('name', $preset?->name) }}"
                   class="form-control @error('name') is-invalid @enderror"
                   style="border-radius:.4rem"
                   maxlength="100" required>
            @error('name')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
            <div class="form-text" style="font-size:.72rem">
                Количества ниже — норма на 1 единицу готовой продукции. В форме цеха они
                умножаются на введённое количество.
            </div>

            @php
                $checkedGroupIds = array_map('intval', old('group_ids',
                    $preset?->groups->pluck('id')->all() ?? array_filter([$defaultGroupId ?? null])));
            @endphp
            <div class="form-label fw-semibold mt-3 mb-1">Группы</div>
            <div class="d-flex flex-wrap gap-1">
                @foreach($groups as $group)
                    <input type="checkbox" class="btn-check" name="group_ids[]" value="{{ $group->id }}"
                           id="presetGroup{{ $group->id }}" autocomplete="off"
                           @checked(in_array($group->id, $checkedGroupIds, true))>
                    <label class="btn btn-sm btn-outline-primary rounded-pill" for="presetGroup{{ $group->id }}">
                        <i class="bi {{ $group->icon ?: 'bi-folder' }}"></i> {{ $group->name }}
                    </label>
                @endforeach
            </div>
            @error('group_ids.*')
                <div class="text-danger small mt-1">{{ $message }}</div>
            @enderror
            @if($groups->isNotEmpty())
                <div class="form-text" style="font-size:.72rem">
                    Можно отметить несколько групп — пресет покажется в каждой. Ни одной — «Без группы».
                </div>
            @endif
            @if($groups->isEmpty())
                <div class="form-text" style="font-size:.72rem">
                    Групп у отдела пока нет — их заводят на вкладке «Пресеты» карточки отдела.
                </div>
            @endif

            {{-- Отделы пресета: текущий и основной сервер добавит сам — их не снять. --}}
            @php
                $lockedDeptIds  = array_values(array_filter([$department->id, $preset?->department_id]));
                $checkedDeptIds = array_map('intval', old('department_ids',
                    $preset?->departments->pluck('id')->all() ?? []));
            @endphp
            <div class="form-label fw-semibold mt-3 mb-1">Отделы</div>
            <div class="d-flex flex-wrap gap-1">
                @foreach($allDepartments as $dept)
                    @php $locked = in_array($dept->id, $lockedDeptIds, true); @endphp
                    <input type="checkbox" class="btn-check" name="department_ids[]" value="{{ $dept->id }}"
                           id="presetDept{{ $dept->id }}" autocomplete="off"
                           @checked($locked || in_array($dept->id, $checkedDeptIds, true)) @disabled($locked)>
                    <label class="btn btn-sm btn-outline-secondary rounded-pill" for="presetDept{{ $dept->id }}"
                           @if($dept->id === $preset?->department_id) title="Основной отдел" @endif>
                        @if($dept->id === $preset?->department_id)<i class="bi bi-house-door"></i>@endif
                        {{ $dept->name }}
                    </label>
                @endforeach
            </div>
            @error('department_ids.*')
                <div class="text-danger small mt-1">{{ $message }}</div>
            @enderror
            <div class="form-text" style="font-size:.72rem">
                Пресет — один на все отмеченные отделы: правка видна в каждом. В другом отделе его
                раскладывают по своим группам. Нужна независимая копия — кнопка «Копировать».
            </div>
        </div>
    </div>

    @if($preset && $preset->departments->count() > 1)
        <div class="alert alert-warning py-2 small">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Пресет используется в отделах: {{ $preset->departments->pluck('name')->implode(', ') }}.
            Правка изменит его везде.
        </div>
    @endif

    {{-- ① Сырьё --}}
    <div class="card shadow-sm mb-3">
        <div class="card-header fw-semibold py-2">
            <i class="bi bi-box me-1"></i> Сырьё <span class="text-danger">*</span>
            <span class="text-muted fw-normal small">— на 1 ед. готовой продукции</span>
        </div>
        <div class="card-body">
            @error('raw_materials')
                <div class="text-danger small mb-2">{{ $message }}</div>
            @enderror
            <div id="rawContainer"></div>
            <button type="button" class="btn btn-sm btn-outline-primary mt-1" data-add="raw">
                <i class="bi bi-plus-circle"></i> Добавить сырьё
            </button>
        </div>
    </div>

    {{-- ② Тара / упаковка --}}
    <div class="card shadow-sm mb-3">
        <div class="card-header fw-semibold py-2">
            <i class="bi bi-box-seam me-1"></i> Тара / упаковка
            <span class="text-muted fw-normal text-lowercase small">(опционально)</span>
        </div>
        <div class="card-body">
            <div id="packagesContainer"></div>
            <button type="button" class="btn btn-sm btn-outline-warning mt-1" data-add="package">
                <i class="bi bi-plus-circle"></i> Добавить упаковку
            </button>
        </div>
    </div>

    {{-- ③ Продукт --}}
    <div class="card shadow-sm mb-3">
        <div class="card-header fw-semibold py-2">
            <i class="bi bi-check2-circle me-1"></i> Продукт <span class="text-danger">*</span>
            <span class="text-muted fw-normal small">— выход на 1 ед.</span>
        </div>
        <div class="card-body">
            @error('products')
                <div class="text-danger small mb-2">{{ $message }}</div>
            @enderror
            <div id="productsContainer"></div>
            <button type="button" class="btn btn-sm btn-outline-success mt-1" data-add="product">
                <i class="bi bi-plus-circle"></i> Добавить продукт
            </button>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mb-3">
        <a href="{{ route('admin.departments.show', $department) }}#workshop" class="btn btn-outline-secondary" data-back>Отмена</a>
        <button type="submit" class="btn btn-primary px-4">
            <i class="bi bi-check-lg"></i> Сохранить
        </button>
    </div>
</form>

{{-- Шаблоны строк (как в форме цеха) --}}
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

@push('scripts')
@vite(['resources/js/product-picker.js'])
<script>
(function () {
    const blocks = {
        raw:     { container: document.getElementById('rawContainer'),      tpl: 'tplRaw' },
        package: { container: document.getElementById('packagesContainer'), tpl: 'tplPackage' },
        product: { container: document.getElementById('productsContainer'), tpl: 'tplProduct' },
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
        block.container.appendChild(clone);
        if (window.ProductPicker) window.ProductPicker.initRow(row);

        rowIndex++;
    }

    document.querySelectorAll('[data-add]').forEach(btn => {
        btn.addEventListener('click', () => addRow(btn.dataset.add));
    });

    // Префилл: строки пресета (edit) либо восстановление после ошибки валидации.
    const prefill = @json($prefillItems ?? []);
    if (prefill.length) {
        prefill.forEach(it => addRow(it.role, {
            productId: it.product_id,
            label:     it.product_label,
            quantity:  it.quantity,
        }));
    } else {
        addRow('raw');
        addRow('product');
    }

    // Валидация перед отправкой — как в форме цеха.
    document.getElementById('presetForm').addEventListener('submit', function (e) {
        const hasRows = c => Array.from(c.querySelectorAll('.product-picker-row')).some(row =>
            row.querySelector('input[type="hidden"]')?.value &&
            parseFloat(row.querySelector('.product-picker-qty')?.value) > 0);

        if (!hasRows(blocks.raw.container)) {
            alert('Добавьте хотя бы одну позицию сырья'); e.preventDefault(); return;
        }
        if (!hasRows(blocks.product.container)) {
            alert('Добавьте хотя бы один продукт на выходе'); e.preventDefault(); return;
        }
    });
})();
</script>
@endpush
