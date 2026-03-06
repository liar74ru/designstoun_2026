{{--
    Компонент выбора продукта с поиском и деревом групп.

    Использование:
        <x-product-picker name="products" :index="0" />
        <x-product-picker name="products" :index="1" :value="$item->product_id" :label="$item->product->name" />

    Props:
        name    — базовое имя поля, итоговое: name[index][product_id]
        index   — порядковый номер строки в списке продуктов
        value   — текущий product_id (для редактирования)
        label   — текущее название продукта (для редактирования)
        quantity — текущее количество
--}}
@props([
    'name'     => 'products',
    'index'    => 0,
    'value'    => '',
    'label'    => '',
    'quantity' => '',
])

@php
    $uid      = 'pp_' . $index . '_' . uniqid();
    $inputId  = 'search_' . $uid;
    $hiddenId = 'pid_' . $uid;
    $qtyId    = 'qty_' . $uid;
    $modalId  = 'modal_' . $uid;
@endphp

<div class="product-picker-row d-flex gap-2 align-items-start mb-2"
     data-index="{{ $index }}">

    {{-- Поле поиска --}}
    <div class="flex-grow-1 position-relative">
        <div class="input-group">
            <input type="text"
                   id="{{ $inputId }}"
                   class="form-control product-picker-search"
                   placeholder="Введите название продукта..."
                   value="{{ $label }}"
                   autocomplete="off"
                   data-hidden-id="{{ $hiddenId }}"
                   required>

            {{-- Кнопка открытия дерева --}}
            <button type="button"
                    class="btn btn-outline-secondary product-picker-tree-btn"
                    data-modal="{{ $modalId }}"
                    data-hidden-id="{{ $hiddenId }}"
                    data-search-id="{{ $inputId }}"
                    title="Выбрать из каталога">
                <i class="bi bi-diagram-3"></i>
            </button>
        </div>

        {{-- Выпадающий список результатов поиска --}}
        <div class="product-picker-dropdown list-group shadow-sm"
             id="drop_{{ $uid }}"
             style="display:none; position:absolute; z-index:1000; width:100%; max-height:280px; overflow-y:auto;">
        </div>
    </div>

    {{-- Количество --}}
    <input type="number"
           id="{{ $qtyId }}"
           name="{{ $name }}[{{ $index }}][quantity]"
           class="form-control product-picker-qty"
           style="width:100px"
           placeholder="м²"
           step="0.001"
           min="0.001"
           value="{{ $quantity }}"
           required>

    {{-- Скрытый product_id --}}
    <input type="hidden"
           id="{{ $hiddenId }}"
           name="{{ $name }}[{{ $index }}][product_id]"
           value="{{ $value }}">

    {{-- Удалить строку --}}
    <button type="button" class="btn btn-outline-danger product-picker-remove" title="Удалить">
        <i class="bi bi-x-lg"></i>
    </button>
</div>

{{-- Модальное окно с деревом --}}
<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Выбрать продукт из каталога</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="max-height:70vh; overflow-y:auto;">
                {{-- Поиск внутри дерева --}}
                <input type="text"
                       class="form-control mb-3 tree-search-input"
                       placeholder="Поиск по каталогу..."
                       data-modal="{{ $modalId }}">
                {{-- Сюда JS рендерит дерево --}}
                <div class="product-tree-container" data-modal="{{ $modalId }}">
                    <div class="text-center text-muted py-4">
                        <div class="spinner-border spinner-border-sm me-2"></div>
                        Загрузка каталога...
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
