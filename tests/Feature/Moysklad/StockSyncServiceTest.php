<?php

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Store;
use App\Services\Moysklad\StockSyncService;
use Illuminate\Console\Scheduling\Schedule;

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
            'moysklad_id' => 'aaaa0000-0000-0000-0000-000000000001',
            'name' => 'Товар',
            'is_active' => true,
        ]);

        Http::fake([
            '*' => Http::response([
                'rows' => [
                    [
                        'meta' => [
                            'href' => 'https://api.moysklad.ru/entity/product/aaaa0000-0000-0000-0000-000000000001',
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

// ══════════════════════════════════════════════════════════════════════════════
// StockSyncService — нулевые остатки (stockMode=all)
// ══════════════════════════════════════════════════════════════════════════════

/** Строка отчёта /report/stock/bystore: товар и остатки по складам [storeId => stock]. */
function stockRow(string $productId, array $stores): array
{
    return [
        'meta' => ['href' => "https://api.moysklad.ru/api/remap/1.2/entity/product/{$productId}"],
        'stockByStore' => array_map(fn ($storeId, $qty) => [
            'meta'      => ['href' => "https://api.moysklad.ru/api/remap/1.2/entity/store/{$storeId}"],
            'stock'     => $qty,
            'reserve'   => 0,
            'inTransit' => 0,
        ], array_keys($stores), $stores),
    ];
}

describe('StockSyncService — нулевые остатки', function () {

    beforeEach(function () {
        config()->set('services.moysklad.token', 'test-token');

        Store::create(['id' => 'store-001', 'name' => 'Склад 1']);
        $this->product = Product::create([
            'moysklad_id' => 'aaaa0000-0000-0000-0000-000000000001',
            'name'        => 'Сырьё',
            'is_active'   => true,
        ]);
    });

    test('остатки товара запрашиваются со stockMode=all', function () {
        Http::fake(['*' => Http::response(['rows' => [stockRow('aaaa0000-0000-0000-0000-000000000001', ['store-001' => 5])]], 200)]);

        (new StockSyncService())->updateProductStocksByMoyskladId('aaaa0000-0000-0000-0000-000000000001');

        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'stockMode=all'));
    });

    test('полная синхронизация запрашивается со stockMode=all', function () {
        Http::fake(['*' => Http::response(['rows' => []], 200)]);

        (new StockSyncService())->syncAllProductsStocksByStores();

        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'stockMode=all'));
    });

    test('нулевой склад обнуляет существующую строку', function () {
        ProductStock::create([
            'product_id' => $this->product->id,
            'store_id'   => 'store-001',
            'quantity'   => 7,
            'reserved'   => 2,
        ]);
        Http::fake(['*' => Http::response(['rows' => [stockRow('aaaa0000-0000-0000-0000-000000000001', ['store-001' => 0])]], 200)]);

        (new StockSyncService())->updateProductStocksByMoyskladId('aaaa0000-0000-0000-0000-000000000001');

        $stock = ProductStock::where('product_id', $this->product->id)->first();
        expect((float) $stock->quantity)->toBe(0.0)
            ->and((float) $stock->reserved)->toBe(0.0)
            ->and((float) $stock->available)->toBe(0.0);
    });

    test('нулевой склад не создаёт новую строку', function () {
        Http::fake(['*' => Http::response(['rows' => [stockRow('aaaa0000-0000-0000-0000-000000000001', ['store-001' => 0])]], 200)]);

        (new StockSyncService())->updateProductStocksByMoyskladId('aaaa0000-0000-0000-0000-000000000001');

        expect(ProductStock::where('product_id', $this->product->id)->exists())->toBeFalse();
    });

    test('пустой ответ не стирает остатки', function () {
        ProductStock::create(['product_id' => $this->product->id, 'store_id' => 'store-001', 'quantity' => 3]);
        Http::fake(['*' => Http::response(['rows' => []], 200)]);

        $result = (new StockSyncService())->updateProductStocksByMoyskladId('aaaa0000-0000-0000-0000-000000000001');

        expect($result['success'])->toBeFalse()
            ->and((float) ProductStock::where('product_id', $this->product->id)->value('quantity'))->toBe(3.0);
    });

    test('ошибка API не трогает остатки', function () {
        ProductStock::create(['product_id' => $this->product->id, 'store_id' => 'store-001', 'quantity' => 7]);
        Http::fake(['*' => Http::response(['errors' => [['error' => 'Сбой']]], 500)]);

        $result = (new StockSyncService())->updateProductStocksByMoyskladId('aaaa0000-0000-0000-0000-000000000001');

        expect($result['success'])->toBeFalse()
            ->and((float) ProductStock::where('product_id', $this->product->id)->value('quantity'))->toBe(7.0);
    });

    test('refreshProducts не дублирует запросы и пропускает пустые id', function () {
        Http::fake(['*' => Http::response(['rows' => []], 200)]);

        (new StockSyncService())->refreshProducts(['aaaa0000-0000-0000-0000-000000000001', 'aaaa0000-0000-0000-0000-000000000001', null, 'aaaa0000-0000-0000-0000-000000000002']);

        Http::assertSentCount(2);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Команда moysklad:sync-stocks и расписание
// ══════════════════════════════════════════════════════════════════════════════

describe('Команда moysklad:sync-stocks', function () {

    test('запланирована ежедневно на 06:00 по Москве', function () {
        app(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command ?? '', 'moysklad:sync-stocks'));

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('0 6 * * *')
            ->and($event->timezone)->toBe('Europe/Moscow');
    });

    test('запускает полную синхронизацию остатков', function () {
        config()->set('services.moysklad.token', 'test-token');
        Http::fake(['*' => Http::response(['rows' => []], 200)]);

        $this->artisan('moysklad:sync-stocks')->assertExitCode(0);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'report/stock/bystore'));
    });
});