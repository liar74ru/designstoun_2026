<?php

use App\Models\Product;
use App\Models\Setting;

// ══════════════════════════════════════════════════════════════════════════════
// Product::pieceRate() и prodCost()
// ══════════════════════════════════════════════════════════════════════════════

describe('Product::pieceRate()', function () {

    test('возвращает 390.0 по умолчанию если настройка отсутствует', function () {
        expect(Product::pieceRate())->toBe(390.0);
    });

    test('возвращает значение из таблицы settings', function () {
        Setting::create(['key' => 'PIECE_RATE', 'value' => '500']);

        expect(Product::pieceRate())->toBe(500.0);
    });

});

describe('Product::prodCost()', function () {

    beforeEach(function () {
        Setting::create(['key' => 'PIECE_RATE', 'value' => '390']);
    });

    test('при коэффициенте 0 возвращает базовую ставку округлённую вниз до 10', function () {
        $product = new Product(['prod_cost_coeff' => 0]);

        // floor(390 / 10) * 10 = 390
        expect($product->prodCost())->toBe(390.0);
    });

    test('при коэффициенте 1 применяет формулу', function () {
        $product = new Product(['prod_cost_coeff' => 1]);

        // floor((390 + 390*0.17*1) / 10) * 10 = floor(456.3 / 10) * 10 = 450
        expect($product->prodCost())->toBe(450.0);
    });

    test('изменение PIECE_RATE влияет на prodCost', function () {
        Setting::where('key', 'PIECE_RATE')->update(['value' => '500']);
        \Illuminate\Support\Facades\Cache::forget('setting.PIECE_RATE');

        $product = new Product(['prod_cost_coeff' => 0]);

        // floor(500 / 10) * 10 = 500
        expect($product->prodCost())->toBe(500.0);
    });

});
