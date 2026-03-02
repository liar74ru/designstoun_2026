@props([
    'products' => [],
    'name' => 'product_id',
    'label' => 'Продукт',
    'placeholder' => 'Начните вводить название или артикул...',
    'required' => true,
    'value' => null,
    'error' => null,
    'maxResults' => 10
])

<div class="mb-3">
    <label class="form-label">
        {{ $label }}
        @if($required)
            <span class="text-danger">*</span>
        @endif
    </label>

    <input type="text"
           class="form-control product-search-input @if($error) is-invalid @endif"
           list="products-list-{{ $name }}"
           placeholder="{{ $placeholder }}"
           id="productInput-{{ $name }}"
           data-max-results="{{ $maxResults }}"
           data-products="{{ json_encode($products->pluck('name', 'id')->mapWithKeys(function($name, $id) use ($products) {
               $sku = $products->find($id)->sku;
               return ["$name ($sku)" => $id];
           })) }}"
        {{ $required ? 'required' : '' }}>

    <datalist id="products-list-{{ $name }}"></datalist>

    <input type="hidden"
           name="{{ $name }}"
           id="productIdHidden-{{ $name }}"
           value="{{ $value }}"
        {{ $required ? 'required' : '' }}>

    @if($error)
        <div class="invalid-feedback d-block">
            {{ $error }}
        </div>
    @endif
</div>

@once
    <style>
        .product-search-input:invalid {
            border-color: #dc3545;
        }
    </style>

    <script>
        // Инициализация всех компонентов поиска товаров
        function initProductSearch() {
            document.querySelectorAll('.product-search-input').forEach(input => {
                const name = input.id.replace('productInput-', '');
                const hiddenInput = document.getElementById(`productIdHidden-${name}`);
                const datalist = document.getElementById(`products-list-${name}`);
                const maxResults = parseInt(input.dataset.maxResults) || 10;

                // Получаем товары из атрибута data
                let allProducts = {};
                try {
                    allProducts = JSON.parse(input.dataset.products);
                } catch(e) {
                    console.error('Ошибка парсинга товаров:', e);
                    return;
                }

                // Функция для проверки, содержит ли строка все части запроса
                function matchesParts(text, query) {
                    const parts = query.toLowerCase().split(/\s+/).filter(p => p.length > 0);
                    return parts.every(part => text.toLowerCase().includes(part));
                }

                // Функция для обновления datalist
                function updateDatalist(filterQuery = '') {
                    // Очищаем datalist
                    datalist.innerHTML = '';

                    // Фильтруем товары
                    let count = 0;
                    for (const [productName, id] of Object.entries(allProducts)) {
                        if ((filterQuery === '' || matchesParts(productName, filterQuery)) && count < maxResults) {
                            const option = document.createElement('option');
                            option.value = productName;
                            option.setAttribute('data-id', id);
                            datalist.appendChild(option);
                            count++;
                        }
                    }
                }

                // При вводе текста фильтруем товары
                input.addEventListener('input', function() {
                    updateDatalist(this.value);
                });

                // При выборе товара из списка устанавливаем ID
                input.addEventListener('change', function() {
                    hiddenInput.value = allProducts[this.value] || '';
                });

                // Инициализация при загрузке - показываем первые N товаров
                updateDatalist();
            });
        }

        // Инициализируем при загрузке страницы
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initProductSearch);
        } else {
            initProductSearch();
        }
    </script>
@endonce
