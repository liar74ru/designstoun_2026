<?php

use App\Models\Product;
use App\Models\Setting;
use App\Models\StoneReceptionItem;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    Setting::updateOrCreate(['key' => 'UNDERCUT_PENALTY'], ['value' => '1.5']);
    Setting::updateOrCreate(['key' => 'EDGING_COEFF'],     ['value' => '-2.5']);
});

// ══════════════════════════════════════════════════════════════════════════════
// StoneReceptionItem::computeEffectiveCoeff() — комбинации флагов
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionItem::computeEffectiveCoeff()', function () {

    test('без флагов → возвращает baseCoeff', function () {
        expect(StoneReceptionItem::computeEffectiveCoeff(2.0, false, false))->toBe(2.0);
    });

    test('is_undercut=true → вычитает UNDERCUT_PENALTY (1.5)', function () {
        expect(StoneReceptionItem::computeEffectiveCoeff(2.0, true, false))->toBe(0.5);
    });

    test('is_edging=true → полная замена на EDGING_COEFF (-2.5)', function () {
        expect(StoneReceptionItem::computeEffectiveCoeff(2.0, false, true))->toBe(-2.5);
    });

    test('is_edging + is_undercut → EDGING_COEFF − UNDERCUT_PENALTY (-4.0)', function () {
        expect(StoneReceptionItem::computeEffectiveCoeff(2.0, true, true))->toBe(-4.0);
    });

    test('обратная совместимость: вызов без третьего параметра', function () {
        expect(StoneReceptionItem::computeEffectiveCoeff(3.0, true))->toBe(1.5);
        expect(StoneReceptionItem::computeEffectiveCoeff(3.0, false))->toBe(3.0);
    });

    test('читает свежие значения настроек', function () {
        Setting::set('EDGING_COEFF', '-3.0');
        Setting::set('UNDERCUT_PENALTY', '2.0');

        expect(StoneReceptionItem::computeEffectiveCoeff(5.0, true, true))->toBe(-5.0);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// StoneReceptionItem::getBaseCoeffAttribute() — чтение хранимой базы
//
// Раньше база восстанавливалась обратным счётом из effective_cost_coeff и не
// снимала бонус плитки-маски, из-за чего форма правки коэффициента задваивала
// его при каждом сохранении. Теперь база хранится в колонке base_cost_coeff.
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionItem::getBaseCoeffAttribute()', function () {

    test('возвращает хранимую базу, не пересчитывая её из effective', function () {
        $item = new StoneReceptionItem([
            'base_cost_coeff'      => 2.0,
            'effective_cost_coeff' => 0.5,
            'is_undercut'          => true,
            'is_edging'            => false,
        ]);

        expect($item->base_coeff)->toBe(2.0);
    });

    test('база не зависит от флагов позиции', function () {
        $item = new StoneReceptionItem([
            'base_cost_coeff'      => 3.0,
            'effective_cost_coeff' => -2.5,
            'is_undercut'          => false,
            'is_edging'            => true,
        ]);

        expect($item->base_coeff)->toBe(3.0);
    });

    test('бонус маски не задваивается: база остаётся исходной', function () {
        // Продукт с коэффициентом 5, SKU плитки-маски: effective = 5 + 2 = 7,
        // но база — по-прежнему 5, поэтому повторное сохранение ничего не сдвинет.
        $item = new StoneReceptionItem([
            'base_cost_coeff'      => 5.0,
            'effective_cost_coeff' => 7.0,
        ]);

        expect($item->base_coeff)->toBe(5.0);
    });

    test('без хранимой базы → фолбэк на prod_cost_coeff продукта', function () {
        $product = Product::factory()->create(['prod_cost_coeff' => 4.2]);
        $item    = new StoneReceptionItem([
            'effective_cost_coeff' => -2.5,
            'is_edging'            => true,
            'product_id'           => $product->id,
        ]);
        $item->setRelation('product', $product);

        expect($item->base_coeff)->toBe(4.2);
    });

    test('без хранимой базы и без продукта → 0', function () {
        $item = new StoneReceptionItem(['effective_cost_coeff' => -2.5]);

        expect($item->base_coeff)->toBe(0.0);
    });
});
