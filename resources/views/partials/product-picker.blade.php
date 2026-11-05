{{--
    Универсальный одиночный элемент выбора продукта.
    Параметры:
      $id          — string: уникальный префикс ID (обязателен)
      $name        — string: name атрибут hidden input, напр. 'filter[product_id]'
      $value       — string|null: текущее значение (ID продукта)
      $label       — string|null: текущая метка (имя продукта)
      $placeholder — string: placeholder поля (default: 'Введите название...')
      $skuPrefix   — string|null: data-sku-prefix для SKU-фильтрации (default: null)
      $allowedIds  — array|null: массив разрешённых ID продуктов (default: null = весь каталог)
      $showTree    — bool: показывать кнопку дерева каталога (default: true)
      $showClear   — bool: показывать кнопку сброса (default: false)
      $required    — bool: required на hidden input (default: false)
      $searchName  — string|null: name второго hidden для поиска по части названия, напр.
                     'filter[product_search]'. Задан — в выпадашке первым идёт пункт
                     «Все, где есть «…»» (только для фильтров списков; default: null)
      $searchValue — string|null: текущий текст поиска (default: '')
      $pickerProducts — Collection|null: готовый список товаров вместо /api/products/tree — для
                     страниц, где у пользователя нет use-product-api (дашборд работника)
--}}
@php
    $value       = $value ?? '';
    $label       = $label ?? '';
    $placeholder = $placeholder ?? 'Введите название...';
    $skuPrefix   = $skuPrefix ?? null;
    $allowedIds  = $allowedIds ?? null;
    $showTree    = $showTree ?? true;
    $showClear   = $showClear ?? false;
    $required    = $required ?? false;
    $searchName  = $searchName ?? null;
    $searchValue = $searchName ? ($searchValue ?? '') : '';
    // Не $products: @include видит переменные родителя, а $products отдают формы (весь каталог)
    $pickerProducts = $pickerProducts ?? null;
@endphp

<div class="product-picker-row"
     @if($skuPrefix) data-sku-prefix="{{ $skuPrefix }}" @endif
     @if($allowedIds !== null) data-allowed-ids="{{ json_encode(array_values($allowedIds)) }}" @endif
     @if($pickerProducts !== null) data-products="{{ $pickerProducts->map(fn ($p) => ['id' => $p->id, 'label' => $p->name])->values()->toJson() }}" @endif>
    <div class="flex-grow-1 position-relative">
        <div class="input-group input-group-sm">
            <input type="text"
                   id="{{ $id }}_search"
                   class="form-control product-picker-search"
                   placeholder="{{ $placeholder }}"
                   autocomplete="off"
                   data-hidden-id="{{ $id }}_hidden"
                   value="{{ $searchValue !== '' ? $searchValue : $label }}">
            @if($showTree)
            <button type="button"
                    class="btn btn-outline-secondary product-picker-tree-btn"
                    data-modal="modal_{{ $id }}"
                    data-hidden-id="{{ $id }}_hidden"
                    data-search-id="{{ $id }}_search"
                    title="Выбрать из каталога">
                <i class="bi bi-diagram-3"></i>
            </button>
            @endif
            @if($showClear && ($value || $searchValue !== ''))
            <button type="button"
                    class="btn btn-outline-secondary product-picker-clear"
                    title="Сбросить">
                <i class="bi bi-x"></i>
            </button>
            @endif
        </div>
        <div class="product-picker-dropdown list-group shadow-sm"
             style="display:none;position:absolute;z-index:1000;width:100%;max-height:280px;overflow-y:auto">
        </div>
    </div>
    <input type="hidden"
           id="{{ $id }}_hidden"
           name="{{ $name }}"
           value="{{ $value }}"
           @if($required) required @endif>
    @if($searchName)
    <input type="hidden"
           class="product-picker-search-value"
           name="{{ $searchName }}"
           value="{{ $searchValue }}">
    @endif
</div>

@if($showTree)
<div class="modal fade" id="modal_{{ $id }}" tabindex="-1">
    <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Выбрать из каталога</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body product-tree-modal-body">
                <input type="text" class="form-control mb-2 tree-search-input"
                       placeholder="Поиск по каталогу...">
                <label class="form-check small text-muted mb-3">
                    <input type="checkbox" class="form-check-input tree-show-hidden">
                    <span class="form-check-label">Показать скрытые</span>
                </label>
                <div class="product-tree-container"></div>
            </div>
        </div>
    </div>
</div>
@endif
