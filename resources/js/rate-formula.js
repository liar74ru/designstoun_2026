/**
 * Зеркало серверного расчёта себестоимости — App\Support\RateFormula,
 * App\Support\ModifierEngine и App\Models\DepartmentModifier.
 *
 * Ни одно число здесь не захардкожено: ставки, доля коэффициента и правила
 * отдела приходят из window.ProductionRates
 * (см. partials/production-rates-js.blade.php). Если превью в форме считать
 * по собственным константам, оно разойдётся с тем, что сохранит сервер.
 *
 * Модель расчёта: коэффициент продукта плюс сумма сработавших правил отдела.
 * Порядок правил на результат не влияет.
 *
 * Подключается глобально через app.js как window.RateFormula.
 */

/** Настройки с сервера; пустой объект — защита от подключения без партиала. */
function rates() {
    return window.ProductionRates ?? {};
}

/** Доля базовой ставки, на которую действует коэффициент продукта. */
export function coeffShare() {
    return Number(rates().coeffShare ?? 0);
}

/**
 * Базовая ставка пильщика отдела (₽/ед).
 * Отдел не задан или не имеет своей ставки → глобальная.
 */
export function pieceRate(departmentId = null) {
    const cfg = rates().pieceRate ?? {};
    const own = departmentId != null ? cfg.byDepartment?.[String(departmentId)] : undefined;
    return Number(own ?? cfg.default ?? 0);
}

/**
 * Все активные правила отдела. Зеркало ModifierEngine::allFor().
 * Отдел не задан или неизвестен на этой странице → правил нет.
 */
export function modifiersFor(departmentId) {
    if (departmentId == null || departmentId === '') return [];
    return rates().modifiers?.byDepartment?.[String(departmentId)] ?? [];
}

/** Действует ли правило в этой области. Зеркало DepartmentModifier::appliesToScope(). */
function appliesToScope(rule, scope) {
    return rule.applies_to === 'both' || rule.applies_to === scope;
}

/**
 * Совпадает ли строка с маской вида «04-07-*» или «*-*-30».
 * `*` заменяет один сегмент SKU целиком. Зеркало DepartmentModifier::matchesPattern().
 */
export function matchesPattern(value, pattern) {
    if (!value || !pattern) return false;

    const valueParts   = String(value).split('-');
    const patternParts = String(pattern).split('-');

    return patternParts.every((part, i) => {
        if (part === '*') return valueParts[i] !== undefined;
        return valueParts[i] === part;
    });
}

/**
 * Доступно ли ручное правило при данной партии сырья.
 * Пустое условие или неизвестный SKU партии — доступно.
 * Зеркало DepartmentModifier::availableForBatchSku().
 */
export function availableForBatchSku(rule, batchSku) {
    if (!rule.available_when_batch_sku || batchSku == null) return true;
    return matchesPattern(batchSku, rule.available_when_batch_sku);
}

/** Ручные правила области, доступные при этой партии — для чекбоксов формы. */
export function manualRules({ departmentId, scope, batchSku = null }) {
    return modifiersFor(departmentId).filter(
        (rule) => rule.trigger === 'manual'
            && appliesToScope(rule, scope)
            && availableForBatchSku(rule, batchSku),
    );
}

/**
 * Какие правила сработали. Зеркало ModifierEngine::resolve():
 * sku-правила — автоматически по маске, ручные — по отмеченным ключам.
 */
export function resolve({ departmentId, scope, sku = null, manualKeys = [], batchSku = null }) {
    return modifiersFor(departmentId).filter((rule) => {
        if (!appliesToScope(rule, scope)) return false;

        if (rule.trigger === 'sku') {
            return matchesPattern(sku, rule.sku_pattern);
        }

        return manualKeys.includes(rule.key) && availableForBatchSku(rule, batchSku);
    });
}

/**
 * Ступенчатая ставка за единицу продукции.
 * ОКРУГЛВНИЗ((ставка + ставка×доля×коэф) / 10) × 10
 */
export function stepped(rate, coeff) {
    return Math.floor((rate + rate * coeffShare() * coeff) / 10) * 10;
}

/** Итоговый коэффициент пильщика: база плюс сумма сработавших правил. */
export function effectiveCoeff({ baseCoeff, departmentId, scope, sku = null, manualKeys = [], batchSku = null }) {
    const applied = resolve({ departmentId, scope, sku, manualKeys, batchSku });

    return applied.reduce(
        (coeff, rule) => coeff + Number(rule.worker_coeff_delta ?? 0),
        Number(baseCoeff) || 0,
    );
}

/**
 * Коэффициент для показа: до 4 знаков, хвостовые нули отброшены.
 *
 * Значение правила задаёт админ, колонка — decimal(8,4). Округление до одного
 * знака показывало 1.75 как 1.8, а четыре знака давали нечитаемое «2.5000».
 */
export function formatCoeff(value) {
    return String(Number((Number(value) || 0).toFixed(4)));
}

/** Стоимость единицы продукции для пильщика по эффективному коэффициенту. */
export function prodCost(effCoeff, departmentId = null) {
    return stepped(pieceRate(departmentId), Number(effCoeff));
}

window.RateFormula = {
    coeffShare,
    pieceRate,
    modifiersFor,
    matchesPattern,
    availableForBatchSku,
    manualRules,
    resolve,
    stepped,
    effectiveCoeff,
    formatCoeff,
    prodCost,
};
