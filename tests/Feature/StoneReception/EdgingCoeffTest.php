<?php

use App\Models\Product;
use App\Models\StoneReceptionItem;

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
