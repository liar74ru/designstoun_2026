/**
 * product-picker.js
 */

// ─── Кэш дерева ───────────────────────────────────────────────────────────────
let _treeCache = null;
let _treePending = null;

async function fetchTree() {
    if (_treeCache) return _treeCache;
    if (_treePending) return _treePending;

    _treePending = fetch('/api/products/tree', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(r => r.json())
        .then(data => { _treeCache = data; _treePending = null; return data; });

    return _treePending;
}

function flattenProducts(groups, result = []) {
    for (const g of groups) {
        for (const p of (g.products || [])) result.push(p);
        if (g.children?.length) flattenProducts(g.children, result);
    }
    return result;
}

// ─── Нечёткий поиск ───────────────────────────────────────────────────────────

// Разбивает строку на слова, спецсимволы → пробелы
// "Плитка из Кварцит 50хL" → ["плитка", "из", "кварцит", "50хl"]
function tokenize(str) {
    return str
        .toLowerCase()
        .replace(/[^a-zа-яё\d]/gi, ' ')
        .split(/\s+/)
        .filter(Boolean);
}

// Каждое слово запроса должно быть НАЧАЛОМ хотя бы одного слова в названии.
// "плит квар 50 l" найдёт "Плитка из Кварцит 50хL":
//   плит → Плитка ✓
//   квар → Кварцит ✓
//   50   → 50хL ✓
//   l    → 50хl ✓  (после toLowerCase)
function fuzzyMatch(productLabel, query) {
    if (!query) return true;
    const productTokens = tokenize(productLabel);
    const queryTokens   = tokenize(query);
    return queryTokens.every(qToken =>
        productTokens.some(pToken => pToken.includes(qToken))
    );
}


function initSearch(row) {
    const searchInput = row.querySelector('.product-picker-search');
    const hiddenInput = row.querySelector('input[type="hidden"]');
    const dropEl      = row.querySelector('.product-picker-dropdown');

    if (!searchInput) return;

    let allProducts = [];
    fetchTree().then(tree => { allProducts = flattenProducts(tree); });

    function showDrop(items) {
        dropEl.innerHTML = '';
        if (!items.length) {
            dropEl.innerHTML = '<div class="list-group-item text-muted small">Ничего не найдено</div>';
        }
        items.slice(0, 15).forEach(p => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'list-group-item list-group-item-action py-1 small';
            btn.textContent = p.label;
            btn.addEventListener('mousedown', e => {
                e.preventDefault();
                selectProduct(searchInput, hiddenInput, p);
                hideDrop();
            });
            dropEl.appendChild(btn);
        });
        dropEl.style.display = 'block';
    }

    function hideDrop() { dropEl.style.display = 'none'; }

    searchInput.addEventListener('input', () => {
        const q = searchInput.value.trim();
        if (q.length < 1) { hideDrop(); hiddenInput.value = ''; return; }
        const matched = allProducts.filter(p => fuzzyMatch(p.label, q));
        showDrop(matched);
    });

    searchInput.addEventListener('blur', () => setTimeout(hideDrop, 150));
    searchInput.addEventListener('focus', () => {
        if (searchInput.value.trim().length >= 1) searchInput.dispatchEvent(new Event('input'));
    });
}

function selectProduct(searchInput, hiddenInput, product) {
    searchInput.value = product.label;
    hiddenInput.value = product.id;
    document.dispatchEvent(new CustomEvent('product-picker:selected'));
}

// ─── Дерево в модале ──────────────────────────────────────────────────────────
function renderTree(groups, filter = '') {
    const ul = document.createElement('ul');
    ul.className = 'list-unstyled ms-2';

    for (const group of groups) {
        const matchedProducts = (group.products || []).filter(p =>
            !filter || fuzzyMatch(p.label, filter)
        );
        const hasMatchInChildren = filter ? groupHasMatch(group.children || [], filter) : true;

        if (filter && !matchedProducts.length && !hasMatchInChildren) continue;

        const li = document.createElement('li');
        li.className = 'mb-1';

        const groupEl = document.createElement('div');
        groupEl.className = 'd-flex align-items-center gap-1 py-1';

        const hasChildren = (group.children?.length > 0) || matchedProducts.length > 0;
        if (hasChildren) {
            const toggler = document.createElement('button');
            toggler.type = 'button';
            toggler.className = 'btn btn-sm p-0 border-0 text-muted tree-toggler';
            toggler.innerHTML = '<i class="bi bi-chevron-right" style="font-size:.7rem"></i>';
            toggler.addEventListener('click', () => {
                const sub = li.querySelector('.tree-sub');
                const icon = toggler.querySelector('i');
                const isOpen = sub.style.display !== 'none';
                sub.style.display = isOpen ? 'none' : 'block';
                icon.className = isOpen ? 'bi bi-chevron-right' : 'bi bi-chevron-down';
                icon.style.fontSize = '.7rem';
            });
            groupEl.appendChild(toggler);
        } else {
            const spacer = document.createElement('span');
            spacer.style.width = '20px';
            groupEl.appendChild(spacer);
        }

        const folderIcon = document.createElement('i');
        folderIcon.className = 'bi bi-folder text-warning';
        groupEl.appendChild(folderIcon);

        const groupName = document.createElement('span');
        groupName.className = 'fw-semibold small text-muted';
        groupName.textContent = group.name;
        groupEl.appendChild(groupName);
        li.appendChild(groupEl);

        const sub = document.createElement('div');
        sub.className = 'tree-sub ms-3';
        sub.style.display = filter ? 'block' : 'none';

        matchedProducts.forEach(product => {
            const pBtn = document.createElement('button');
            pBtn.type = 'button';
            pBtn.className = 'btn btn-sm btn-light text-start w-100 py-1 mb-1 small';
            pBtn.innerHTML = `<i class="bi bi-gem text-secondary me-1"></i>${product.label}`;
            pBtn.addEventListener('click', () => {
                const modal = pBtn.closest('.modal');
                const modalId = modal.id;
                const btn = document.querySelector(`.product-picker-tree-btn[data-modal="${modalId}"]`);
                if (btn) {
                    const hidden = document.getElementById(btn.dataset.hiddenId);
                    const search = document.getElementById(btn.dataset.searchId);
                    if (hidden && search) selectProduct(search, hidden, product);
                }
                // Закрываем модал через data-атрибут Bootstrap
                const closeBtn = modal.querySelector('[data-bs-dismiss="modal"]');
                closeBtn?.click();
            });
            sub.appendChild(pBtn);
        });

        if (group.children?.length) {
            sub.appendChild(renderTree(group.children, filter));
        }

        li.appendChild(sub);
        ul.appendChild(li);
    }

    return ul;
}

function groupHasMatch(groups, filter) {
    for (const g of groups) {
        if ((g.products || []).some(p => fuzzyMatch(p.label, filter))) return true;
        if (g.children?.length && groupHasMatch(g.children, filter)) return true;
    }
    return false;
}

async function openTreeModal(btn) {
    const modalId     = btn.dataset.modal;
    const modalEl     = document.getElementById(modalId);
    const container   = modalEl.querySelector('.product-tree-container');
    const searchInput = modalEl.querySelector('.tree-search-input');

    // Показываем модал через встроенный Bootstrap API data-атрибутов —
    // не требует window.bootstrap
    modalEl.classList.add('show');
    modalEl.style.display = 'block';
    modalEl.removeAttribute('aria-hidden');
    document.body.classList.add('modal-open');

    // Backdrop
    let backdrop = document.getElementById('picker-backdrop');
    if (!backdrop) {
        backdrop = document.createElement('div');
        backdrop.id = 'picker-backdrop';
        backdrop.className = 'modal-backdrop fade show';
        document.body.appendChild(backdrop);
    }

    // Закрытие по крестику или backdrop
    function closeModal() {
        modalEl.classList.remove('show');
        modalEl.style.display = 'none';
        modalEl.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
        backdrop?.remove();
    }

    modalEl.querySelector('[data-bs-dismiss="modal"]').onclick = closeModal;
    backdrop.onclick = closeModal;

    // Загружаем дерево
    const tree = await fetchTree();

    function render(filter = '') {
        container.innerHTML = '';
        container.appendChild(renderTree(tree, filter));
    }

    render();
    searchInput.oninput = () => render(searchInput.value.trim());
}

// ─── Инициализация строки ─────────────────────────────────────────────────────
function initRow(row) {
    initSearch(row);

    const treeBtn = row.querySelector('.product-picker-tree-btn');
    if (treeBtn) {
        treeBtn.addEventListener('click', () => openTreeModal(treeBtn));
    }

    const removeBtn = row.querySelector('.product-picker-remove');
    if (removeBtn) {
        removeBtn.addEventListener('click', () => {
            row.remove();
            document.dispatchEvent(new CustomEvent('product-picker:removed'));
        });
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.product-picker-row').forEach(initRow);
});

window.ProductPicker = { initRow, fetchTree };
