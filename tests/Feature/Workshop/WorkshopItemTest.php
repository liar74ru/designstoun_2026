<?php

use App\Models\Product;
use App\Models\Setting;
use App\Models\WorkshopItem;

// ══════════════════════════════════════════════════════════════════════════════
// WorkshopItem::effectiveProdCost()
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkshopItem::effectiveProdCost()', function () {

    test('возвращает зафиксированный worker_cost_per_m2 как число', function () {
        $item = new WorkshopItem(['worker_cost_per_m2' => 123.45]);

        expect($item->effectiveProdCost())->toBe(123.45);
    });

    test('при null worker_cost_per_m2 считает по prodCost продукта (как в приёмке)', function () {
        Setting::set('PIECE_RATE', 390);

        $product = Product::factory()->create(['sku' => '04-01-10', 'prod_cost_coeff' => 1.0]);
        $item    = new WorkshopItem(['effective_cost_coeff' => 1.0]);
        $item->setRelation('product', $product);

        // prodCost(1.0): floor((390 + 390×0.17×1.0) / 10) × 10 = floor(45.63) × 10 = 450
        expect($item->effectiveProdCost())->toBe(450.0);
    });
});
