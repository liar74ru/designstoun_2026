/**
 * Превью в формах по общей фикстуре tests/fixtures/rate-formula.json (A6).
 *
 * Те же примеры проверяет tests/Feature/Modifier/RateFormulaFixtureTest.php для
 * сервера: поправили формулу в одной копии и забыли в другой — упадёт одна из сторон.
 */
import { readFileSync } from 'node:fs';
import { beforeAll, describe, expect, test, vi } from 'vitest';

const fixture = JSON.parse(
    readFileSync(new URL('../fixtures/rate-formula.json', import.meta.url), 'utf8'),
);

const DEPARTMENT_ID = 1;

let RateFormula;

beforeAll(async () => {
    // Модуль читает window.ProductionRates (partials/production-rates-js.blade.php)
    // и сам вешается на window — подменяем window до импорта.
    vi.stubGlobal('window', {
        ProductionRates: {
            coeffShare: fixture.coeffShare,
            modifiers: { byDepartment: { [DEPARTMENT_ID]: fixture.rules } },
        },
    });

    RateFormula = await import('../../resources/js/rate-formula.js');
});

describe('stepped()', () => {
    test.each(fixture.stepped)('$name', ({ rate, coeff, step, expected }) => {
        expect(RateFormula.stepped(rate, coeff, step)).toBe(expected);
    });
});

describe('formatCoeff()', () => {
    test.each(fixture.formatCoeff)('$value', ({ value, expected }) => {
        expect(RateFormula.formatCoeff(value)).toBe(expected);
    });
});

describe('matchesPattern()', () => {
    test.each(fixture.matchesPattern)('$value ~ $pattern', ({ value, pattern, expected }) => {
        expect(RateFormula.matchesPattern(value, pattern)).toBe(expected);
    });
});

describe('правила отдела: какие сработали и итоговые коэффициенты', () => {
    test.each(fixture.resolve)('$name', (c) => {
        const args = {
            departmentId: c.noDepartment ? null : DEPARTMENT_ID,
            scope:        c.scope,
            sku:          c.sku ?? null,
            manualKeys:   c.manualKeys ?? [],
            batchSku:     c.batchSku ?? null,
            disabledKeys: c.disabledKeys ?? [],
        };

        expect(RateFormula.resolve(args).map((rule) => rule.key)).toEqual(c.expected.keys);
        // toBeCloseTo не нужен: в фикстуре только точно представимые в float слагаемые
        expect(RateFormula.effectiveCoeff({ ...args, baseCoeff: c.baseCoeff })).toBe(c.expected.workerCoeff);
        expect(RateFormula.masterEffectiveCoeff({ ...args, baseCoeff: c.masterBaseCoeff })).toBe(c.expected.masterCoeff);
    });
});
