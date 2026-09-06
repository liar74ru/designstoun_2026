/**
 * Зеркало серверного расчёта себестоимости — App\Support\RateFormula и
 * StoneReceptionItem::computeEffectiveCoeff().
 *
 * Ни одно число здесь не захардкожено: ставки, доля коэффициента и
 * коэффициенты-модификаторы приходят из window.ProductionRates
 * (см. partials/production-rates-js.blade.php). Если превью в форме считать
 * по собственным константам, оно разойдётся с тем, что сохранит сервер.
 *
 * Подключается глобально через app.js как window.RateFormula.
 */

/** Настройки с сервера; пустой объект — защита от подключения без партиала. */
function rates() {
    return window.ProductionRates ?? {};
}

function globalRate(key) {
    return Number(rates().global?.[key] ?? 0);
}

/** Доля базовой ставки, на которую действует коэффициент продукта. */
export function coeffShare() {
    return Number(rates().coeffShare ?? 0);
}

/** Штраф коэффициента за флаг «подкол > 80%». */
export function undercutPenalty() {
    return globalRate('UNDERCUT_PENALTY');
}

/** Коэффициент «Торцовка» — полностью заменяет коэффициент продукта. */
export function edgingCoeff() {
    return globalRate('EDGING_COEFF');
}

/** Бонус коэффициента для SKU плитки-маски (04-07-XX). */
export function maskTileBonus() {
    return globalRate('MASK_TILE_COEFF_BONUS');
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

/** Плитка-маска: SKU вида 04-07-XX. Зеркало StoneReceptionItem::skuIsMaskTile(). */
export function skuIsMaskTile(sku) {
    if (!sku) return false;
    const parts = String(sku).split('-');
    return parts[0] === '04' && parts[1] === '07';
}

/**
 * Ступенчатая ставка за единицу продукции.
 * ОКРУГЛВНИЗ((ставка + ставка×доля×коэф) / 10) × 10
 */
export function stepped(rate, coeff) {
    return Math.floor((rate + rate * coeffShare() * coeff) / 10) * 10;
}

/**
 * Итоговый коэффициент из базового и набора флагов.
 * Порядок повторяет сервер: торцовка заменяет базу целиком (и тогда бонус
 * маски не применяется), подкол вычитается поверх в обоих случаях.
 */
export function effectiveCoeff({ baseCoeff, isUndercut = false, isEdging = false, sku = null }) {
    let coeff = isEdging
        ? edgingCoeff()
        : Number(baseCoeff) + (skuIsMaskTile(sku) ? maskTileBonus() : 0);

    if (isUndercut) {
        coeff -= undercutPenalty();
    }

    return coeff;
}

/** Стоимость единицы продукции для пильщика по эффективному коэффициенту. */
export function prodCost(effCoeff, departmentId = null) {
    return stepped(pieceRate(departmentId), Number(effCoeff));
}

window.RateFormula = {
    coeffShare,
    undercutPenalty,
    edgingCoeff,
    maskTileBonus,
    pieceRate,
    skuIsMaskTile,
    stepped,
    effectiveCoeff,
    prodCost,
};
