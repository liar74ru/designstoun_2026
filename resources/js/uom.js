/**
 * Единицы измерения товара — зеркало App\Support\Uom.
 *
 * Единица приходит из карточки товара (product.unit в данных выбора товара);
 * вписывать «м²/м³» в шаблоны и скрипты нельзя.
 *
 * Подключается глобально через app.js как window.Uom.
 */

const NORMALIZE = { 'м2': 'м²', 'м3': 'м³' };

/** Единица для показа; нет единицы → ''. */
export function label(uom) {
    const s = String(uom ?? '').trim();
    return NORMALIZE[s] ?? s;
}

/** Подпись ставки: «₽/м²»; без единицы — «₽/ед.». Зеркало Uom::rate(). */
export function rate(unit) {
    return `₽/${label(unit) || 'ед.'}`;
}

function number(value, decimals) {
    return Number(value || 0).toLocaleString('ru-RU', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
}

/** «12,500 м²»; без единицы — только число. */
export function format(qty, unit, decimals = 3) {
    const u = label(unit);
    return u ? `${number(qty, decimals)} ${u}` : number(qty, decimals);
}

/** Map единица → сумма → «12,500 м² · 3,000 шт»; пусто → «0». */
export function formatTotals(totals, decimals = 3) {
    const entries = totals instanceof Map ? [...totals] : Object.entries(totals ?? {});
    if (!entries.length) return number(0, decimals);
    return entries.map(([u, sum]) => format(sum, u, decimals)).join(' · ');
}

/**
 * Суммы по единицам по строкам выбора товара в контейнере.
 * Количество — из qtySelector строки, единица — из её .product-picker-unit.
 */
export function totalsFromRows(container, qtySelector = '.product-picker-qty') {
    const totals = new Map();
    if (!container) return totals;
    container.querySelectorAll('.product-picker-row').forEach(row => {
        const input = row.querySelector(qtySelector);
        if (!input) return;
        const qty = parseFloat(input.value);
        if (!qty) return;
        const unit = label(row.querySelector('.product-picker-unit')?.textContent);
        totals.set(unit, (totals.get(unit) ?? 0) + qty);
    });
    return totals;
}

window.Uom = { label, rate, format, formatTotals, totalsFromRows };
