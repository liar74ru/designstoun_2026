<?php

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Store;
use App\Services\Moysklad\StockSyncService;

// ══════════════════════════════════════════════════════════════════════════════
// StockSyncService — syncAllProductsStocksByStores()
// ══════════════════════════════════════════════════════════════════════════════

describe('StockSyncService::syncAllProductsStocksByStores()', function () {

    test('возвращает ошибку когда токен пустой', function () {
        config()->set('services.moysklad.token', '');

        $service = new StockSyncService();
        $result = $service->syncAllProductsStocksByStores();

        expect($result['success'])->toBeFalse();
    });

    test('пропускает товар без local match', function () {
        config()->set('services.moysklad.token', 'test-token');

        Store::create(['id' => 'store-001', 'name' => 'Склад 1']);

        Http::fake([
            '*' => Http::response([
                'rows' => [
                    [
                        'meta' => [
                            'href' => 'https://api.moysklad.ru/entity/product/unknown-product',
                        ],
                        'stockByStore' => [
                            [
                                'meta' => [
                                    'href' => 'https://api.moysklad.ru/entity/store/store-001',
                                ],
                                'stock' => 100,
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = new StockSyncService();
        $result = $service->syncAllProductsStocksByStores();

        expect($result['success'])->toBeTrue();
        expect($result['updated'])->toBe(0);
    });

    test('пропускает склады без local match', function () {
        config()->set('services.moysklad.token', 'test-token');

        Store::create(['id' => 'store-001', 'name' => 'Склад 1']);
        $product = Product::create([
            'moysklad_id' => 'product-001',
            'name' => 'Товар',
            'is_active' => true,
        ]);

        Http::fake([
            '*' => Http::response([
                'rows' => [
                    [
                        'meta' => [
                            'href' => 'https://api.moysklad.ru/entity/product/product-001',
                        ],
                        'stockByStore' => [
                            [
                                'meta' => [
                                    'href' => 'https://api.moysklad.ru/entity/store/unknown-store',
                                ],
                                'stock' => 100,
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = new StockSyncService();
        $result = $service->syncAllProductsStocksByStores();

        expect($result['updated'])->toBe(0);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// StockSyncService — updateProductStocksByMoyskladId()
// ══════════════════════════════════════════════════════════════════════════════

describe('StockSyncService::updateProductStocksByMoyskladId()', function () {

    test('возвращает ошибку когда товар не найден', function () {
        config()->set('services.moysklad.token', 'test-token');

        Http::fake([
            '*' => Http::response(['rows' => []], 200),
        ]);

        $service = new StockSyncService();
        $result = $service->updateProductStocksByMoyskladId('unknown-product');

        expect($result['success'])->toBeFalse();
    });
});