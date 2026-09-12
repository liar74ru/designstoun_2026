/**
 * Отрисовка правил себестоимости отдела в строке формы.
 *
 * Правила задаются на уровне отдела, а отдел выбирается прямо на странице
 * (формы приёмки и цеха), поэтому список рисуется здесь, а не в Blade: иначе
 * ту же разметку пришлось бы держать в двух местах и синхронизировать.
 *
 * Ручные правила — чекбоксы `<name>[<index>][modifiers][]` со значением ключа
 * правила. Сработавшие по SKU — плашки без чекбокса: пользователь их не
 * выбирает, но должен видеть, откуда взялся коэффициент.
 *
 * Данные — window.ProductionRates.modifiers (partials/production-rates-js).
 * Подключается глобально через app.js как window.ModifierPicker.
 */

/** Контейнер описывает себя данными: отдел, область, партия, отмеченные ключи. */
function readContext(container) {
    return {
        scope: container.dataset.scope || 'reception',
        departmentId: container.dataset.departmentId || null,
        batchSku: container.dataset.batchSku || null,
        inputName: container.dataset.inputName || '',
        activeKeys: (container.dataset.activeKeys || '')
            .split(',')
            .map((key) => key.trim())
            .filter(Boolean),
    };
}

/** Ключи правил, отмеченных пользователем в этой строке. */
export function checkedKeys(container) {
    return Array.from(container.querySelectorAll('input[type="checkbox"]:checked'))
        .map((input) => input.value);
}

/** Счётчик id: строки клонируются из <template>, индекс в разметке может повторяться. */
let checkboxSeq = 0;

/** Цвет плашки, когда правилу его не задали. Зеркало DepartmentModifier::COLOR_FALLBACK. */
const COLOR_FALLBACK = '#6C757D';

/**
 * Цвет текста на плашке: на жёлтом белые буквы не читаются.
 * Зеркало DepartmentModifier::textColorFor().
 */
function textColorOn(background) {
    const hex = String(background || COLOR_FALLBACK).replace('#', '');
    if (hex.length !== 6) return '#FFFFFF';

    const [r, g, b] = [0, 2, 4].map((i) => parseInt(hex.slice(i, i + 2), 16));

    return (0.299 * r + 0.587 * g + 0.114 * b) > 140 ? '#212529' : '#FFFFFF';
}

/** Иконка правила; null — правилу её не задали. */
function ruleIcon(rule, extraClass = '') {
    if (!rule.icon) return null;

    const icon = document.createElement('i');
    icon.className = `bi ${rule.icon} ${extraClass}`.trim();

    return icon;
}

function checkbox(rule, { inputName, checked }) {
    const id = `mod_${++checkboxSeq}_${rule.key}`;
    const wrapper = document.createElement('div');
    wrapper.className = 'form-check mb-0 flex-shrink-0';

    const input = document.createElement('input');
    input.className = 'form-check-input modifier-checkbox';
    input.type = 'checkbox';
    input.id = id;
    input.name = `${inputName}[modifiers][]`;
    input.value = rule.key;
    input.checked = checked;

    const label = document.createElement('label');
    label.className = 'form-check-label small fw-semibold';
    label.setAttribute('for', id);
    const labelIcon = ruleIcon(rule, 'me-1');
    if (labelIcon) label.append(labelIcon);
    label.append(document.createTextNode(rule.name));
    if (rule.color) label.style.color = rule.color;

    wrapper.append(input, label);
    return wrapper;
}

function badge(rule) {
    const span = document.createElement('span');
    span.className = 'badge flex-shrink-0 modifier-badge';
    span.style.fontSize = '.65rem';
    span.style.background = rule.color || COLOR_FALLBACK;
    span.style.color = textColorOn(rule.color);
    const icon = ruleIcon(rule, 'me-1');
    if (icon) span.append(icon);
    span.append(document.createTextNode(rule.name));
    span.title = 'Применяется автоматически по SKU товара';
    return span;
}

/**
 * Перерисовать список правил контейнера.
 * Отметки пользователя сохраняются: уже отрисованные чекбоксы важнее
 * data-active-keys, иначе смена партии или отдела сбрасывала бы выбор.
 */
export function render(container, { sku = null } = {}) {
    const rates = window.RateFormula;
    if (!rates) return;

    const ctx = readContext(container);
    const wasChecked = container.dataset.rendered
        ? checkedKeys(container)
        : ctx.activeKeys;

    container.innerHTML = '';

    rates.manualRules({
        departmentId: ctx.departmentId,
        scope: ctx.scope,
        batchSku: ctx.batchSku,
    }).forEach((rule) => {
        container.append(checkbox(rule, {
            inputName: ctx.inputName,
            checked: wasChecked.includes(rule.key),
        }));
    });

    const productSku = sku ?? container.dataset.sku ?? null;
    if (productSku) {
        rates.resolve({
            departmentId: ctx.departmentId,
            scope: ctx.scope,
            sku: productSku,
        })
            .filter((rule) => rule.trigger === 'sku')
            .forEach((rule) => container.append(badge(rule)));
    }

    container.dataset.rendered = '1';
}

/** Перерисовать все контейнеры внутри корня (страница, строка, шаблон). */
export function renderAll(root = document) {
    root.querySelectorAll('.modifier-picker').forEach((container) => render(container));
}

/** Сменить отдел у всех контейнеров и перерисовать. */
export function setDepartment(departmentId, root = document) {
    root.querySelectorAll('.modifier-picker').forEach((container) => {
        container.dataset.departmentId = departmentId ?? '';
        render(container);
    });
}

/** Сменить SKU партии сырья (от него зависит доступность ручных правил). */
export function setBatchSku(batchSku, root = document) {
    root.querySelectorAll('.modifier-picker').forEach((container) => {
        container.dataset.batchSku = batchSku ?? '';
        render(container);
    });
}

/** SKU выбранного товара строки — от него зависят автоматические плашки. */
export function setProductSku(container, sku) {
    container.dataset.sku = sku ?? '';
    render(container);
}

window.ModifierPicker = {
    render,
    renderAll,
    setDepartment,
    setBatchSku,
    setProductSku,
    checkedKeys,
};

document.addEventListener('DOMContentLoaded', () => renderAll());
