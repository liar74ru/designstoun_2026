<?php

use App\Models\PackagingItem;
use App\Models\Setting;

// ══════════════════════════════════════════════════════════════════════════════
// PackagingItem::computePackerCost()
// ══════════════════════════════════════════════════════════════════════════════

describe('PackagingItem::computePackerCost()', function () {

    test('считает по формуле: PACKAGING_PROD_COST × productCoeff + PACKAGING_COST × packageCoeff', function () {
        Setting::set('PACKAGING_PROD_COST', 100);
        Setting::set('PACKAGING_COST', 30);

        // 100 × 1.5 + 30 × 2.0 = 150 + 60 = 210
        expect(PackagingItem::computePackerCost(1.5, 2.0))->toBe(210.0);
    });

    test('возвращает 0 когда оба коэффициента null', function () {
        Setting::set('PACKAGING_PROD_COST', 100);
        Setting::set('PACKAGING_COST', 30);

        expect(PackagingItem::computePackerCost(null, null))->toBe(0.0);
    });

    test('null коэффициент трактуется как 0', function () {
        Setting::set('PACKAGING_PROD_COST', 100);
        Setting::set('PACKAGING_COST', 30);

        // 100 × 0 + 30 × 2.0 = 60
        expect(PackagingItem::computePackerCost(null, 2.0))->toBe(60.0);
        // 100 × 1.5 + 30 × 0 = 150
        expect(PackagingItem::computePackerCost(1.5, null))->toBe(150.0);
    });

    test('PACKAGING_PROD_COST по умолчанию = 0 (только тара участвует)', function () {
        Setting::where('key', 'PACKAGING_PROD_COST')->delete();
        Setting::set('PACKAGING_COST', 30);

        // 0 × 1.5 + 30 × 2.0 = 60
        expect(PackagingItem::computePackerCost(1.5, 2.0))->toBe(60.0);
    });

    test('PACKAGING_COST по умолчанию = 0 когда настройка отсутствует', function () {
        Setting::set('PACKAGING_PROD_COST', 100);
        Setting::where('key', 'PACKAGING_COST')->delete();

        // 100 × 1.5 + 0 × 2.0 = 150
        expect(PackagingItem::computePackerCost(1.5, 2.0))->toBe(150.0);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// PackagingItem::effectiveProdCost()
// ══════════════════════════════════════════════════════════════════════════════

describe('PackagingItem::effectiveProdCost()', function () {

    test('возвращает worker_cost_per_m2 как число', function () {
        $item = new PackagingItem(['worker_cost_per_m2' => 123.45]);

        expect($item->effectiveProdCost())->toBe(123.45);
    });

    test('null worker_cost_per_m2 возвращается как 0.0', function () {
        $item = new PackagingItem();

        expect($item->effectiveProdCost())->toBe(0.0);
    });
});
