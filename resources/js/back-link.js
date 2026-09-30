/**
 * Кнопка «Назад»: ведёт на страницу, с которой пришли в этой вкладке, — с её фильтрами
 * и страницей списка. Пришли с дашборда — вернёт на дашборд.
 *
 * Ссылка помечается `data-back`, в href — родитель по умолчанию (прямой заход по ссылке,
 * закладка, новая вкладка). Без JS ссылка просто ведёт на родителя.
 *
 * История вкладки — стек в sessionStorage (у каждой вкладки свой):
 * - страница, которая уже есть в стеке, обрезает его до себя: вернулись в карточку после
 *   сохранения формы или со страницы товара — всё, что открывали из неё, забывается;
 * - одна страница — один путь: список с другими фильтрами заменяет прежнюю запись;
 * - формы (meta nav-transient, App\Support\BackNavigation) в цель не попадают:
 *   после сохранения на форму не возвращаются.
 *
 * Подпись: `[data-back-text]` внутри ссылки. Ведёт на родителя — исходная подпись
 * («К списку»), на другую страницу — «Назад»; куда именно — в подсказке.
 */
const KEY = 'nav_stack';
const MAX = 30;

function load() {
    try {
        return JSON.parse(sessionStorage.getItem(KEY)) || [];
    } catch {
        return [];
    }
}

function save(stack) {
    try {
        sessionStorage.setItem(KEY, JSON.stringify(stack.slice(-MAX)));
    } catch {
        // sessionStorage недоступен — кнопка ведёт на родителя по умолчанию
    }
}

function record() {
    const entry = {
        path: window.location.pathname,
        url: window.location.pathname + window.location.search,
        title: document.title,
        transient: document.querySelector('meta[name="nav-transient"]') !== null,
    };

    const stack = load();
    const index = stack.findIndex(e => e.path === entry.path);
    const next = (index >= 0 ? stack.slice(0, index) : stack).concat(entry);

    save(next);

    return next;
}

/** Последняя страница перед текущей, кроме форм. */
function target(stack) {
    for (let i = stack.length - 2; i >= 0; i--) {
        if (!stack[i].transient) return stack[i];
    }

    return null;
}

function pathOf(href) {
    try {
        return new URL(href, window.location.origin).pathname;
    } catch {
        return null;
    }
}

function apply() {
    const back = target(record());

    document.querySelectorAll('a[data-back]').forEach(link => {
        // Исходные значения — один раз: при возврате из bfcache страница пересчитывается.
        if (link.dataset.backFallback === undefined) {
            link.dataset.backFallback = link.getAttribute('href');
        }

        const fallback = link.dataset.backFallback;
        const text = link.querySelector('[data-back-text]');
        if (text && text.dataset.backLabel === undefined) {
            text.dataset.backLabel = text.textContent;
        }

        link.setAttribute('href', back ? back.url : fallback);
        if (back) link.title = back.title;
        else link.removeAttribute('title');

        if (text) {
            text.textContent = !back || back.path === pathOf(fallback) ? text.dataset.backLabel : 'Назад';
        }
    });
}

// pageshow, а не DOMContentLoaded: срабатывает и при возврате кнопкой браузера из bfcache —
// иначе стек остался бы со страницами, с которых ушли назад.
window.addEventListener('pageshow', apply);
