<?php

use App\Support\RateFormula;

// ══════════════════════════════════════════════════════════════════════════════
// RateFormula — общая ступенчатая формула ставки за единицу продукции
// ══════════════════════════════════════════════════════════════════════════════

describe('RateFormula::stepped()', function () {

    test('коэффициент 0 возвращает базовую ставку без надбавки', function () {
        expect(RateFormula::stepped(100, 0))->toBe(100.0);
        expect(RateFormula::stepped(390, 0))->toBe(390.0);
    });

    test('округляет вниз до десятков', function () {
        // 100 + 100*0.17*1 = 117 → 110
        expect(RateFormula::stepped(100, 1))->toBe(110.0);
        // 390 + 390*0.17*1 = 456.3 → 450
        expect(RateFormula::stepped(390, 1))->toBe(450.0);
    });

    test('коэффициент 3 при базе 100 воспроизводит прежнюю надбавку за мелкую плитку (150 ₽/м²)', function () {
        expect(RateFormula::stepped(100, 3))->toBe(150.0);
    });

    test('отрицательный коэффициент уменьшает ставку', function () {
        // 390 + 66.3*(-2.5) = 224.25 → 220
        expect(RateFormula::stepped(390, -2.5))->toBe(220.0);
    });
});

describe('RateFormula::formatCoeff()', function () {

    /**
     * Значение правила задаёт админ, колонка decimal(8,4): округление до одного
     * знака показывало 1.75 как «1,8», четыре знака давали «2,5000».
     * Зеркало formatCoeff() из resources/js/rate-formula.js.
     */
    test('значащие знаки сохраняются, хвостовые нули отбрасываются', function () {
        expect(RateFormula::formatCoeff(1.75))->toBe('1,75')
            ->and(RateFormula::formatCoeff(2.5))->toBe('2,5')
            ->and(RateFormula::formatCoeff(2))->toBe('2')
            ->and(RateFormula::formatCoeff(-0.75))->toBe('-0,75')
            ->and(RateFormula::formatCoeff(-5.2))->toBe('-5,2');
    });

    test('пустое значение и ноль печатаются как 0', function () {
        expect(RateFormula::formatCoeff(null))->toBe('0')
            ->and(RateFormula::formatCoeff(0))->toBe('0');
    });

    test('строка из decimal-колонки читается как число', function () {
        expect(RateFormula::formatCoeff('1.7500'))->toBe('1,75');
    });

    test('дальше четвёртого знака значение округляется', function () {
        expect(RateFormula::formatCoeff(1.75000001))->toBe('1,75');
    });
});
