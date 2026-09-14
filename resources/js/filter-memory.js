/**
 * Запоминание фильтров списка на время (TTL).
 *
 * Форма фильтров помечается `data-filter-memory`. Если страница открыта
 * с query-параметрами — они сохраняются в localStorage для текущего пути.
 * Если страница открыта без параметров (переход из меню, со страницы поиска и т.п.)
 * и сохранённое состояние не старше TTL — выполняется переход на URL с этими параметрами.
 *
 * Ссылки/кнопки с `data-filter-reset` очищают сохранённое состояние.
 */
const TTL_MS = 5 * 60 * 1000;
const KEY = 'filter_state_' + window.location.pathname;

function read() {
    try {
        const raw = localStorage.getItem(KEY);
        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

function write(value) {
    try {
        if (value === null) localStorage.removeItem(KEY);
        else localStorage.setItem(KEY, JSON.stringify(value));
    } catch {
        // localStorage недоступен — работаем без запоминания
    }
}

function init() {
    if (!document.querySelector('form[data-filter-memory]')) return;

    document.addEventListener('click', (e) => {
        if (e.target.closest('[data-filter-reset]')) write(null);
    });

    const query = window.location.search;
    if (query && query !== '?') {
        write({ query, ts: Date.now() });
        return;
    }

    const saved = read();
    if (!saved) return;
    if (Date.now() - saved.ts > TTL_MS) {
        write(null);
        return;
    }
    window.location.replace(window.location.pathname + saved.query + window.location.hash);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
