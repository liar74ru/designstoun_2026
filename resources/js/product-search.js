// resources/js/product-search.js

// Функция для проверки, содержит ли строка все части запроса
function matchesParts(text, query) {
    const parts = query.toLowerCase().split(/\s+/).filter(p => p.length > 0);
    return parts.every(part => text.toLowerCase().includes(part));
}

// Функция инициализации одного компонента поиска
function initSingleProductSearch(element) {
    const searchInput = element.querySelector('.product-search-input');
    const hiddenInput = element.querySelector('input[type="hidden"]');
    const datalist = element.querySelector('datalist');

    if (!searchInput || !hiddenInput || !datalist) return;

    // Получаем данные продуктов из атрибута
    let allProducts = {};
    try {
        allProducts = JSON.parse(searchInput.dataset.products);
    } catch(e) {
        console.error('Ошибка парсинга товаров:', e);
        return;
    }

    const maxResults = parseInt(searchInput.dataset.maxResults) || 10;

    // Функция для обновления datalist с фильтрацией по частям
    function updateDatalist(filterQuery = '') {
        datalist.innerHTML = '';

        let count = 0;
        const filteredProducts = [];

        for (const [productName, id] of Object.entries(allProducts)) {
            if ((filterQuery === '' || matchesParts(productName, filterQuery)) && count < maxResults) {
                filteredProducts.push({ name: productName, id: id });
                count++;
            }
        }

        // Если остался только один вариант, не выводим список
        if (filteredProducts.length === 1) {
            return;
        }

        filteredProducts.forEach(product => {
            const option = document.createElement('option');
            option.value = product.name;
            option.setAttribute('data-id', product.id);
            datalist.appendChild(option);
        });
    }

    // Удаляем старые обработчики
    if (searchInput._inputHandler) {
        searchInput.removeEventListener('input', searchInput._inputHandler);
    }
    if (searchInput._changeHandler) {
        searchInput.removeEventListener('change', searchInput._changeHandler);
    }

    // Создаем новые обработчики
    searchInput._inputHandler = function() {
        updateDatalist(this.value);
    };

    searchInput._changeHandler = function() {
        hiddenInput.value = allProducts[this.value] || '';
    };

    // Добавляем обработчики
    searchInput.addEventListener('input', searchInput._inputHandler);
    searchInput.addEventListener('change', searchInput._changeHandler);

    // Инициализируем datalist
    updateDatalist();
}

// Основная функция инициализации всех компонентов
function initProductSearch() {
    document.querySelectorAll('.product-search-wrapper').forEach(wrapper => {
        initSingleProductSearch(wrapper);
    });
}

// Инициализируем при загрузке страницы
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initProductSearch);
} else {
    initProductSearch();
}

// Делаем функцию доступной глобально
window.initSingleProductSearch = initSingleProductSearch;
