<?php

use App\Models\Counterparty;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Store;
use App\Services\Moysklad\MoySkladService;

// ══════════════════════════════════════════════════════════════════════════════
// MoySkladService — syncStores()
// ══════════════════════════════════════════════════════════════════════════════

describe('MoySkladService::syncStores()', function () {

    test('возвращает ошибку когда токен пустой', function () {
        config()->set('services.moysklad.token', '');

        $service = new MoySkladService();
        $result = $service->syncStores();

        expect($result['success'])->toBeFalse();
        expect($result['message'])->toContain('токен не установлен');
    });

    test('успешно синхронизирует склады из API', function () {
        config()->set('services.moysklad.token', 'test-token');

        Http::fake([
            '*' => Http::response([
                'rows' => [
                    [
                        'id' => 'store-001',
                        'name' => 'Склад Основной',
                        'code' => 'OS',
                        'archived' => false,
                    ],
                    [
                        'id' => 'store-002',
                        'name' => 'Склад Второй',
                        'code' => 'V2',
                        'archived' => true,
                    ],
                ],
            ], 200),
        ]);

        $service = new MoySkladService();
        $result = $service->syncStores();

        expect($result['success'])->toBeTrue();
        expect($result['synced'])->toBe(2);

        $store1 = Store::find('store-001');
        expect($store1)->not->toBeNull();
        expect($store1->name)->toBe('Склад Основной');
    });

    test('обновляет существующий склад', function () {
        config()->set('services.moysklad.token', 'test-token');

        Store::create([
            'id' => 'store-001',
            'name' => 'Старое имя',
            'code' => 'OLD',
        ]);

        Http::fake([
            '*' => Http::response([
                'rows' => [
                    [
                        'id' => 'store-001',
                        'name' => 'Новое имя',
                        'code' => 'NEW',
                    ],
                ],
            ], 200),
        ]);

        $service = new MoySkladService();
        $result = $service->syncStores();

        expect($result['success'])->toBeTrue();
        expect($result['updated'])->toBe(1);
    });

    test('правильно извлекает parent_id из meta', function () {
        config()->set('services.moysklad.token', 'test-token');

        Http::fake([
            '*' => Http::response([
                'rows' => [
                    [
                        'id' => 'store-001',
                        'name' => 'Дочерний склад',
                        'parent' => [
                            'meta' => [
                                'href' => 'https://api.moysklad.ru/entity/store/550e8400-e29b-41d4-a716-446655440000',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = new MoySkladService();
        $result = $service->syncStores();

        expect($result['success'])->toBeTrue();
        $store = Store::find('store-001');
        expect($store->parent_id)->toBe('550e8400-e29b-41d4-a716-446655440000');
    });

    test('игнорирует parent_id равный store', function () {
        config()->set('services.moysklad.token', 'test-token');

        Http::fake([
            '*' => Http::response([
                'rows' => [
                    [
                        'id' => 'store-001',
                        'name' => 'Тест',
                        'parent' => [
                            'meta' => [
                                'href' => 'https://api.moysklad.ru/entity/store/store',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = new MoySkladService();
        $result = $service->syncStores();

        $store = Store::find('store-001');
        expect($store->parent_id)->toBeNull();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// MoySkladService — syncGroups()
// ══════════════════════════════════════════════════════════════════════════════

describe('MoySkladService::syncGroups()', function () {

    test('возвращает ошибку когда токен пустой', function () {
        config()->set('services.moysklad.token', '');

        $service = new MoySkladService();
        $result = $service->syncGroups();

        expect($result['success'])->toBeFalse();
    });

    test('успешно синхронизирует группы товаров', function () {
        config()->set('services.moysklad.token', 'test-token');

        Http::fake([
            '*' => Http::response([
                'rows' => [
                    [
                        'id' => 'group-001',
                        'name' => 'Гранит',
                        'pathName' => 'Гранит',
                    ],
                    [
                        'id' => 'group-002',
                        'name' => 'Мрамор',
                        'pathName' => 'Мрамор',
                    ],
                ],
                'meta' => ['size' => 2],
            ], 200),
        ]);

        $service = new MoySkladService();
        $result = $service->syncGroups();

        expect($result['success'])->toBeTrue();
        expect($result['synced'])->toBe(2);

        $group = ProductGroup::where('moysklad_id', 'group-001')->first();
        expect($group)->not->toBeNull();
        expect($group->name)->toBe('Гранит');
    });

    test('удаляет группы отсутствующие в МойСклад', function () {
        config()->set('services.moysklad.token', 'test-token');

        ProductGroup::create(['moysklad_id' => 'old-group', 'name' => 'Удалённая']);

        Http::fake([
            '*' => Http::response([
                'rows' => [
                    ['id' => 'new-group', 'name' => 'Новая', 'pathName' => 'Новая'],
                ],
                'meta' => ['size' => 1],
            ], 200),
        ]);

        $service = new MoySkladService();
        $result = $service->syncGroups();

        expect($result['success'])->toBeTrue();
        expect($result['deleted'])->toBe(1);
        expect(ProductGroup::where('moysklad_id', 'old-group')->exists())->toBeFalse();
    });

    test('извлекает parent_id из productFolder', function () {
        config()->set('services.moysklad.token', 'test-token');

        Http::fake([
            '*' => Http::response([
                'rows' => [
                    [
                        'id' => 'group-child',
                        'name' => 'Дочерняя',
                        'productFolder' => [
                            'meta' => [
                                'href' => 'https://api.moysklad.ru/entity/productfolder/550e8400-e29b-41d4-a716-446655440000',
                            ],
                        ],
                    ],
                ],
                'meta' => ['size' => 1],
            ], 200),
        ]);

        $service = new MoySkladService();
        $result = $service->syncGroups();

        $group = ProductGroup::where('moysklad_id', 'group-child')->first();
        expect($group->parent_id)->toBe('550e8400-e29b-41d4-a716-446655440000');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// MoySkladService — syncProducts()
// ══════════════════════════════════════════════════════════════════════════════

describe('MoySkladService::syncProducts()', function () {

    test('возвращает ошибку когда токен пустой', function () {
        config()->set('services.moysklad.token', '');

        $service = new MoySkladService();
        $result = $service->syncProducts();

        expect($result['success'])->toBeFalse();
    });

    test('успешно синхронизирует товары', function () {
        config()->set('services.moysklad.token', 'test-token');

        ProductGroup::create(['moysklad_id' => 'group-001', 'name' => 'Тест группа']);

        Http::fake([
            '*' => Http::response([
                'rows' => [
                    [
                        'id' => 'product-001',
                        'name' => 'Плитка 300х300',
                        'article' => 'P300',
                        'salePrices' => [['value' => 150000]],
                        'stock' => 100,
                        'productFolder' => [
                            'meta' => [
                                'href' => 'https://api.moysklad.ru/entity/productfolder/group-001',
                            ],
                        ],
                    ],
                ],
                'meta' => ['size' => 1],
            ], 200),
        ]);

        $service = new MoySkladService();
        $result = $service->syncProducts();

        expect($result['success'])->toBeTrue();
        expect($result['synced'])->toBe(1);

        $product = Product::where('moysklad_id', 'product-001')->first();
        expect($product)->not->toBeNull();
        expect($product->name)->toBe('Плитка 300х300');
        expect($product->sku)->toBe('P300');
        expect((float) $product->price)->toBe(1500.00);
    });

    test('обновляет существующий товар', function () {
        config()->set('services.moysklad.token', 'test-token');

        Product::create([
            'moysklad_id' => 'product-001',
            'name' => 'Старое название',
            'price' => 1000,
            'is_active' => true,
        ]);

        Http::fake([
            '*' => Http::response([
                'rows' => [
                    [
                        'id' => 'product-001',
                        'name' => 'Новое название',
                        'salePrices' => [['value' => 200000]],
                    ],
                ],
                'meta' => ['size' => 1],
            ], 200),
        ]);

        $service = new MoySkladService();
        $result = $service->syncProducts();

        expect($result['updated'])->toBe(1);
    });

    test('извлекает buyPrice и minPrice', function () {
        config()->set('services.moysklad.token', 'test-token');

        Http::fake([
            '*' => Http::response([
                'rows' => [
                    [
                        'id' => 'product-001',
                        'name' => 'Товар',
                        'buyPrice' => ['value' => 50000],
                        'minPrice' => ['value' => 80000],
                    ],
                ],
                'meta' => ['size' => 1],
            ], 200),
        ]);

        $service = new MoySkladService();
        $result = $service->syncProducts();

        $product = Product::where('moysklad_id', 'product-001')->first();
        expect((float) $product->buy_price)->toBe(500.00);
        expect((float) $product->min_price)->toBe(800.00);
    });

    test('извлекает prodCostCoeff из атрибутов', function () {
        config()->set('services.moysklad.token', 'test-token');

        Http::fake([
            '*' => Http::response([
                'rows' => [
                    [
                        'id' => 'product-001',
                        'name' => 'Товар',
                        'attributes' => [
                            ['name' => 'prodCostCoeff', 'value' => 1.5],
                        ],
                    ],
                ],
                'meta' => ['size' => 1],
            ], 200),
        ]);

        $service = new MoySkladService();
        $result = $service->syncProducts();

        $product = Product::where('moysklad_id', 'product-001')->first();
        expect((float) $product->prod_cost_coeff)->toBe(1.5);
    });
});

// ══════════════════════════════════════════════════════════════════════════════════════
// MoySkladService — syncCounterparties()
// ══════════════════════════════════════════════════════════════════════════════

describe('MoySkladService::syncCounterparties()', function () {

    test('возвращает ошибку когда токен пустой', function () {
        config()->set('services.moysklad.token', '');

        $service = new MoySkladService();
        $result = $service->syncCounterparties();

        expect($result['success'])->toBeFalse();
    });

    test('успешно синхронизирует контрагентов', function () {
        config()->set('services.moysklad.token', 'test-token');

        Http::fake([
            '*' => Http::response([
                'rows' => [
                    ['id' => 'counter-001', 'name' => 'ООО Поставщик'],
                    ['id' => 'counter-002', 'name' => 'ИП Иванов'],
                ],
                'meta' => ['size' => 2],
            ], 200),
        ]);

        $service = new MoySkladService();
        $result = $service->syncCounterparties();

        expect($result['success'])->toBeTrue();
        expect($result['synced'])->toBe(2);

        $counter = Counterparty::where('moysklad_id', 'counter-001')->first();
        expect($counter)->not->toBeNull();
        expect($counter->name)->toBe('ООО Поставщик');
    });

    test('обновляет существующего контрагента', function () {
        config()->set('services.moysklad.token', 'test-token');

        Counterparty::create(['moysklad_id' => 'counter-001', 'name' => 'Старое']);

        Http::fake([
            '*' => Http::response([
                'rows' => [
                    ['id' => 'counter-001', 'name' => 'Новое'],
                ],
                'meta' => ['size' => 1],
            ], 200),
        ]);

        $service = new MoySkladService();
        $result = $service->syncCounterparties();

        expect($result['updated'])->toBe(1);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// MoySkladService — extractAttributePublic()
// ══════════════════════════════════════════════════════════════════════════════

describe('MoySkladService::extractAttributePublic()', function () {

    test('извлекает значение атрибута по имени', function () {
        config()->set('services.moysklad.token', 'test-token');

        $productData = [
            'attributes' => [
                ['name' => 'prodCostCoeff', 'value' => 2.0],
                ['name' => 'otherAttr', 'value' => 100],
            ],
        ];

        $service = new MoySkladService();
        $result = $service->extractAttributePublic($productData, 'prodCostCoeff');

        expect($result)->toBe(2.0);
    });

    test('возвращает null для несуществующего атрибута', function () {
        config()->set('services.moysklad.token', 'test-token');

        $productData = ['attributes' => []];

        $service = new MoySkladService();
        $result = $service->extractAttributePublic($productData, 'unknown');

        expect($result)->toBeNull();
    });
});