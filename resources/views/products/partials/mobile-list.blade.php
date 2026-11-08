{{--
    Мобильный каталог товаров (products.index): раскрывающееся дерево групп с карточками товаров.
    Логика — resources/js/product-catalog.js; адреса — data-атрибутами #catalogTree, относительные:
    абсолютный route() ведёт на APP_URL, а не на хост, с которого открыта страница.
--}}
<div class="d-md-none">
    <input type="search" id="catalogSearch" class="form-control form-control-sm mb-2"
           placeholder="Название, артикул..." autocomplete="off">
    <div class="d-flex gap-3 align-items-center mb-2">
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" id="catalogInStock">
            <label class="form-check-label small" for="catalogInStock">в наличии</label>
        </div>
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" id="catalogShowHidden" checked>
            <label class="form-check-label small" for="catalogShowHidden">скрытые</label>
        </div>
    </div>

    <div id="catalogTree"
         data-tree-url="{{ route('api.products.tree', [], false) }}"
         data-stocks-url="{{ route('api.products.stocks', [], false) }}"
         data-show-url="{{ route('products.show', '__ID__', false) }}">
        <div class="text-center text-muted py-4 small">
            <span class="spinner-border spinner-border-sm me-1"></span> Загрузка каталога…
        </div>
    </div>
</div>

@push('styles')
    <style>
        .catalog-group > .catalog-group-head {
            cursor: pointer;
            user-select: none;
        }
        .catalog-group > .catalog-group-head .bi-chevron-right {
            transition: transform .15s;
        }
        .catalog-group.open > .catalog-group-head .bi-chevron-right {
            transform: rotate(90deg);
        }
        .catalog-group-body {
            padding-left: .6rem;
            margin-left: .45rem;
            border-left: 1px dashed #dee2e6;
        }
    </style>
@endpush

@push('scripts')
    @vite(['resources/js/product-catalog.js'])
@endpush
