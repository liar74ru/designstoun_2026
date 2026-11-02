{{--
    Мобильный каталог товаров (products.index): раскрывающееся дерево групп с карточками товаров.
    Данные — api.products.tree (то же дерево, что у ProductPicker) и api.products.stocks.
    Поиск и «в наличии» фильтруют дерево на месте; серверные фильтры и пагинация — только на десктопе.
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

    <div id="catalogTree">
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
    <script>
        // DOMContentLoaded: app.js — модуль и выполняется после inline-скриптов, window.escapeHtml появляется позже
        document.addEventListener('DOMContentLoaded', function () {
            const root = document.getElementById('catalogTree');
            if (!root || getComputedStyle(root.parentElement).display === 'none') return;

            const SKU_COLORS = @json(\App\Models\SkuColor::map());
            const SHOW_URL   = @json(route('products.show', '__ID__', false));
            const OPEN_KEY   = 'products.catalogOpen';
            const search     = document.getElementById('catalogSearch');
            const inStock    = document.getElementById('catalogInStock');
            const showHidden = document.getElementById('catalogShowHidden');
            const esc        = window.escapeHtml;
            const qtyFmt     = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 3 });

            let tree = [], stocks = {}, open;
            try { open = new Set(JSON.parse(localStorage.getItem(OPEN_KEY) || '[]')); } catch (e) { open = new Set(); }

            // Зеркало Product::getColorBySku(): сначала «01-05», затем группа «01», иначе белый
            function colorBySku(sku) {
                if (!sku) return '#FFFFFF';
                const parts = sku.split('-');
                if (parts.length >= 2 && SKU_COLORS[parts[0] + '-' + parts[1]]) return SKU_COLORS[parts[0] + '-' + parts[1]];
                return SKU_COLORS[parts[0]] ?? '#FFFFFF';
            }

            function qtyOf(p) {
                return stocks[p.id]?.total ?? 0;
            }

            // Отбор товаров по поиску и наличию; группа без подходящих товаров не показывается
            function filterTree(groups, words, onlyInStock, withHidden) {
                const result = [];
                for (const g of groups) {
                    const products = (g.products || []).filter(p => {
                        if (onlyInStock && qtyOf(p) <= 0) return false;
                        if (!withHidden && p.hidden) return false;
                        const hay = (p.label + ' ' + p.sku).toLowerCase();
                        return words.every(w => hay.includes(w));
                    });
                    const children = filterTree(g.children || [], words, onlyInStock, withHidden);
                    const count = products.length + children.reduce((s, c) => s + c.count, 0);
                    if (count > 0) result.push({ id: g.id, name: g.name, products, children, count });
                }
                return result;
            }

            function productCard(p) {
                const color = colorBySku(p.sku);
                const qty   = qtyOf(p);
                const tag   = p.ms_id ? 'a' : 'div';
                const href  = p.ms_id ? ` href="${esc(SHOW_URL.replace('__ID__', encodeURIComponent(p.ms_id)))}"` : '';
                return `
                    <${tag}${href} class="card mb-2 shadow-sm text-decoration-none text-body"
                       style="border-left:4px solid ${esc(color)}; background:${esc(color)}18">
                        <div class="card-body py-2 px-3 d-flex gap-2 align-items-center">
                            <div class="flex-grow-1" style="min-width:0">
                                <div class="fw-semibold lh-sm" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
                                    ${p.hidden ? '<i class="bi bi-eye-slash text-muted" title="Скрыт в выборе товара"></i>' : ''}
                                    ${esc(p.label)}
                                </div>
                                <div class="small text-muted font-monospace text-truncate">${esc(p.sku || '—')}</div>
                            </div>
                            <div class="flex-shrink-0">
                                ${qty > 0
                                    ? `<span class="badge bg-success">${qtyFmt.format(qty)} ${esc(p.unit || 'шт.')}</span>`
                                    : '<span class="badge bg-danger">Нет</span>'}
                            </div>
                        </div>
                    </${tag}>`;
            }

            function groupNode(g, expanded) {
                const isOpen = expanded || open.has(g.id);
                const el = document.createElement('div');
                el.className = 'catalog-group mb-1' + (isOpen ? ' open' : '');
                el.innerHTML = `
                    <div class="catalog-group-head d-flex align-items-center gap-2 py-2 px-1">
                        <i class="bi bi-chevron-right text-muted small"></i>
                        <i class="bi bi-folder${isOpen ? '2-open' : ''} text-warning"></i>
                        <span class="fw-semibold small flex-grow-1 text-truncate" style="min-width:0">${esc(g.name)}</span>
                        <span class="badge rounded-pill text-bg-light border">${g.count}</span>
                    </div>
                    <div class="catalog-group-body"></div>`;
                const body = el.querySelector('.catalog-group-body');

                // Содержимое строится при первом раскрытии: дерево целиком — почти тысяча карточек
                const fill = () => {
                    if (body.dataset.filled) return;
                    body.dataset.filled = '1';
                    g.children.forEach(c => body.appendChild(groupNode(c, expanded)));
                    body.insertAdjacentHTML('beforeend', g.products.map(productCard).join(''));
                };
                if (isOpen) fill(); else body.style.display = 'none';

                el.querySelector('.catalog-group-head').addEventListener('click', () => {
                    const nowOpen = !el.classList.contains('open');
                    el.classList.toggle('open', nowOpen);
                    el.querySelector('.bi-folder, .bi-folder2-open').className = 'bi bi-folder' + (nowOpen ? '2-open' : '') + ' text-warning';
                    if (nowOpen) fill();
                    body.style.display = nowOpen ? '' : 'none';
                    // Запоминаем раскрытые группы только в обычном режиме, не в результатах поиска
                    if (!expanded) {
                        nowOpen ? open.add(g.id) : open.delete(g.id);
                        try { localStorage.setItem(OPEN_KEY, JSON.stringify([...open])); } catch (e) {}
                    }
                });
                return el;
            }

            function render() {
                const words = search.value.toLowerCase().split(/\s+/).filter(Boolean);
                const filtered = filterTree(tree, words, inStock.checked, showHidden.checked);
                // При поиске раскрываем всё найденное
                const expanded = words.length > 0;
                root.innerHTML = '';
                if (!filtered.length) {
                    root.innerHTML = '<div class="text-center text-muted py-4 small">Ничего не найдено</div>';
                    return;
                }
                filtered.forEach(g => root.appendChild(groupNode(g, expanded)));
            }

            let timer;
            search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(render, 200); });
            inStock.addEventListener('change', render);
            showHidden.addEventListener('change', render);

            const headers = { 'X-Requested-With': 'XMLHttpRequest' };
            Promise.all([
                // Относительные адреса: абсолютный route() ведёт на APP_URL, а не на хост, с которого открыта страница
                fetch(@json(route('api.products.tree', [], false)), { headers }).then(r => r.ok ? r.json() : Promise.reject(r.status)),
                fetch(@json(route('api.products.stocks', [], false)), { headers }).then(r => r.ok ? r.json() : Promise.reject(r.status)),
            ]).then(([t, s]) => {
                tree = t; stocks = s;
                render();
            }).catch((err) => {
                console.error('Каталог товаров:', err);
                root.innerHTML = '<div class="alert alert-danger py-2 small">Не удалось загрузить каталог</div>';
            });
        });
    </script>
@endpush
