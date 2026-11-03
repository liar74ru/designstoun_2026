<?php

use App\Models\Order;
use App\Models\OrderState;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductStock;
use App\Models\Store;
use App\Models\User;
use App\Services\Moysklad\MoySkladService;
use App\Services\Moysklad\StockSyncService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\ReceptionTestHelper as H;

// ══════════════════════════════════════════════════════════════════════════════
// Helpers
// ══════════════════════════════════════════════════════════════════════════════

function makeProduct(array $attrs = []): Product
{
    return Product::factory()->create($attrs);
}

/** Заявка в статусе $stateId с одной позицией — товаром $product. */
function makeOrderWith(Product $product, string $stateId, array $attrs = [], array $itemAttrs = []): Order
{
    $order = Order::create(array_merge([
        'moysklad_id'       => (string) Str::uuid(),
        'name'              => 'Заявка',
        'state_moysklad_id' => $stateId,
    ], $attrs));
    $order->items()->create(array_merge([
        'product_id'   => $product->id,
        'product_name' => $product->name,
        'quantity'     => 10,
        'shipped'      => 0,
    ], $itemAttrs));

    return $order;
}

/** Товары дерева api.products.tree по id (одна корневая группа). */
function treeProducts($test): \Illuminate\Support\Collection
{
    return collect(
        $test->actingAs(H::adminUser())
            ->get(route('api.products.tree'))
            ->assertStatus(200)
            ->json('0.products')
    )->keyBy('id');
}

// ══════════════════════════════════════════════════════════════════════════════
// index()
// ══════════════════════════════════════════════════════════════════════════════

describe('ProductController index()', function () {

    test('список товаров доступен авторизованному', function () {
        $this->actingAs(H::adminUser())
            ->get(route('products.index'))
            ->assertStatus(200);
    });

    test('список содержит созданный товар', function () {
        makeProduct(['name' => 'Уникальный товар XYZ']);

        $this->actingAs(H::adminUser())
            ->get(route('products.index'))
            ->assertStatus(200)
            ->assertSee('Уникальный товар XYZ');
    });

    test('на странице нет ссылки на создание вручную', function () {
        $this->actingAs(H::adminUser())
            ->get(route('products.index'))
            ->assertStatus(200)
            ->assertDontSee('Добавить вручную');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// index() — фильтры
// ══════════════════════════════════════════════════════════════════════════════

describe('ProductController index() — фильтры search', function () {

    test('фильтр in_stock=1 показывает только товары в наличии', function () {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();

        $inStock = makeProduct(['name' => 'Есть на складе']);
        $noStock = makeProduct(['name' => 'Нет на складе']);

        ProductStock::create(['product_id' => $inStock->id, 'store_id' => $storeA->id, 'quantity' => 10]);
        ProductStock::create(['product_id' => $noStock->id, 'store_id' => $storeB->id, 'quantity' => 0]);

        $this->actingAs(H::adminUser())
            ->get(route('products.index', ['filter[in_stock]' => '1']))
            ->assertStatus(200)
            ->assertSee('Есть на складе')
            ->assertDontSee('Нет на складе');
    });

    test('фильтр in_stock=0 показывает только товары без остатка', function () {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();

        $inStock = makeProduct(['name' => 'Есть на складе']);
        $noStock = makeProduct(['name' => 'Нет на складе']);

        ProductStock::create(['product_id' => $inStock->id, 'store_id' => $storeA->id, 'quantity' => 5]);
        ProductStock::create(['product_id' => $noStock->id, 'store_id' => $storeB->id, 'quantity' => 0]);

        $this->actingAs(H::adminUser())
            ->get(route('products.index', ['filter[in_stock]' => '0']))
            ->assertStatus(200)
            ->assertDontSee('Есть на складе')
            ->assertSee('Нет на складе');
    });

    test('фильтр group_id фильтрует по группе', function () {
        $groupId = (string) Str::uuid();
        ProductGroup::create(['moysklad_id' => $groupId, 'name' => 'Моя группа']);

        makeProduct(['name' => 'Товар в группе',   'group_id' => $groupId]);
        makeProduct(['name' => 'Товар без группы', 'group_id' => null]);

        $this->actingAs(H::adminUser())
            ->get(route('products.index', ['filter[group_id]' => $groupId]))
            ->assertStatus(200)
            ->assertSee('Товар в группе')
            ->assertDontSee('Товар без группы');
    });

    test('фильтр hidden=1 показывает только скрытые товары', function () {
        makeProduct(['name' => 'Скрытый товар', 'is_hidden' => true]);
        makeProduct(['name' => 'Видимый товар', 'is_hidden' => false]);

        $this->actingAs(H::adminUser())
            ->get(route('products.index', ['filter[hidden]' => '1']))
            ->assertStatus(200)
            ->assertSee('Скрытый товар')
            ->assertDontSee('Видимый товар');
    });

    test('фильтр hidden=0 показывает только видимые товары', function () {
        makeProduct(['name' => 'Скрытый товар', 'is_hidden' => true]);
        makeProduct(['name' => 'Видимый товар', 'is_hidden' => false]);

        $this->actingAs(H::adminUser())
            ->get(route('products.index', ['filter[hidden]' => '0']))
            ->assertStatus(200)
            ->assertDontSee('Скрытый товар')
            ->assertSee('Видимый товар');
    });

    test('сортировка по имени не ломает страницу', function () {
        $this->actingAs(H::adminUser())
            ->get(route('products.index', ['sort' => 'name', 'direction' => 'asc']))
            ->assertStatus(200);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// show()
// ══════════════════════════════════════════════════════════════════════════════

describe('ProductController show()', function () {

    test('страница товара доступна по moysklad_id', function () {
        $product = makeProduct(['name' => 'Тестовый товар показа']);

        $this->actingAs(H::adminUser())
            ->get(route('products.show', $product->moysklad_id))
            ->assertStatus(200)
            ->assertSee('Тестовый товар показа');
    });

    test('возвращает 404 для несуществующего moysklad_id', function () {
        $this->actingAs(H::adminUser())
            ->get(route('products.show', Str::uuid()))
            ->assertStatus(404);
    });

    test('на странице нет кнопки удаления', function () {
        $product = makeProduct(['name' => 'Проверяемый товар']);

        $this->actingAs(H::adminUser())
            ->get(route('products.show', $product->moysklad_id))
            ->assertStatus(200)
            ->assertDontSee('Удалить из базы')
            ->assertDontSee('products.destroy');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// create / store / update / destroy — маршруты не существуют
// ══════════════════════════════════════════════════════════════════════════════

describe('Удалённые CRUD-маршруты недоступны', function () {

    test('GET /products/create возвращает 404', function () {
        $this->actingAs(H::adminUser())
            ->get('/products/create')
            ->assertStatus(404);
    });

    test('POST /products возвращает 405 (метод не разрешён)', function () {
        $this->actingAs(H::adminUser())
            ->post('/products', ['name' => 'Test', 'price' => 100])
            ->assertStatus(405);
    });

    test('PUT /products/{id} возвращает 405', function () {
        $product = makeProduct();

        $this->actingAs(H::adminUser())
            ->put('/products/'.$product->moysklad_id, ['name' => 'Test', 'price' => 100])
            ->assertStatus(405);
    });

    test('DELETE /products/{id} возвращает 405', function () {
        $product = makeProduct();

        $this->actingAs(H::adminUser())
            ->delete('/products/'.$product->moysklad_id)
            ->assertStatus(405);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// groups()
// ══════════════════════════════════════════════════════════════════════════════

describe('ProductController groups()', function () {

    test('страница групп доступна авторизованному', function () {
        $this->actingAs(H::adminUser())
            ->get(route('products.groups'))
            ->assertStatus(200);
    });

    test('страница групп показывает созданную группу', function () {
        ProductGroup::create([
            'moysklad_id' => (string) Str::uuid(),
            'name' => 'Тестовая группа',
        ]);

        $this->actingAs(H::adminUser())
            ->get(route('products.groups'))
            ->assertStatus(200)
            ->assertSee('Тестовая группа');
    });
});

describe('ProductController syncFromMoySklad()', function () {

    beforeEach(function () {
        Cache::flush();
    });

    test('редиректит с ошибкой если нет credentials', function () {
        $mock = Mockery::mock(MoySkladService::class);
        $mock->shouldReceive('hasCredentials')
            ->once()
            ->andReturn(false);

        app()->instance(MoySkladService::class, $mock);

        $this->actingAs(H::adminUser())
            ->post(route('products.sync'))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('error', 'Логин или пароль МойСклад не найдены в .env');
    });

    test('редиректит с ошибкой если syncProducts вернул success=false', function () {
        $mock = Mockery::mock(MoySkladService::class);
        $mock->shouldReceive('hasCredentials')
            ->once()
            ->andReturn(true);

        $mock->shouldReceive('syncGroups')
            ->once()
            ->andReturn(['success' => true, 'synced' => 5, 'message' => 'Группы синхронизированы']);

        $mock->shouldReceive('syncProducts')
            ->once()
            ->andReturn([
                'success' => false,
                'message' => 'Ошибка API МойСклад: превышен лимит запросов'
            ]);

        app()->instance(MoySkladService::class, $mock);

        $this->actingAs(H::adminUser())
            ->post(route('products.sync'))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('error', 'Ошибка API МойСклад: превышен лимит запросов');
    });

    test('редиректит с успехом при успешной синхронизации без групп', function () {
        $mock = Mockery::mock(MoySkladService::class);
        $mock->shouldReceive('hasCredentials')
            ->once()
            ->andReturn(true);

        $mock->shouldReceive('syncGroups')
            ->once()
            ->andReturn(['success' => true, 'synced' => 0, 'message' => '']);

        $mock->shouldReceive('syncProducts')
            ->once()
            ->andReturn([
                'success' => true,
                'message' => 'Синхронизировано товаров: 15'
            ]);

        app()->instance(MoySkladService::class, $mock);

        $this->actingAs(H::adminUser())
            ->post(route('products.sync'))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('success', 'Синхронизировано товаров: 15');
    });

    test('редиректит с успехом при успешной синхронизации c группами', function () {
        $mock = Mockery::mock(MoySkladService::class);
        $mock->shouldReceive('hasCredentials')
            ->once()
            ->andReturn(true);

        $mock->shouldReceive('syncGroups')
            ->once()
            ->andReturn([
                'success' => true,
                'synced' => 8,
                'message' => 'Синхронизировано групп: 8'
            ]);

        $mock->shouldReceive('syncProducts')
            ->once()
            ->andReturn([
                'success' => true,
                'message' => 'Синхронизировано товаров: 25'
            ]);

        app()->instance(MoySkladService::class, $mock);

        $this->actingAs(H::adminUser())
            ->post(route('products.sync'))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('success', 'Синхронизировано товаров: 25. Синхронизировано групп: 8');
    });

    test('очищает кэш после успешной синхронизации', function () {
        Cache::put('products_tree_json_v4', ['test' => 'data'], 3600);

        $mock = Mockery::mock(MoySkladService::class);
        $mock->shouldReceive('hasCredentials')
            ->once()
            ->andReturn(true);

        $mock->shouldReceive('syncGroups')
            ->once()
            ->andReturn(['success' => true, 'synced' => 0, 'message' => '']);

        $mock->shouldReceive('syncProducts')
            ->once()
            ->andReturn([
                'success' => true,
                'message' => 'Синхронизировано товаров: 10'
            ]);

        app()->instance(MoySkladService::class, $mock);

        expect(Cache::has('products_tree_json_v4'))->toBeTrue();

        $this->actingAs(H::adminUser())
            ->post(route('products.sync'))
            ->assertRedirect(route('products.index'));

        expect(Cache::has('products_tree_json_v4'))->toBeFalse();
    });

    test('обрабатывает ситуацию когда syncGroups вернул success=false', function () {
        $mock = Mockery::mock(MoySkladService::class);
        $mock->shouldReceive('hasCredentials')
            ->once()
            ->andReturn(true);

        $mock->shouldReceive('syncGroups')
            ->once()
            ->andReturn([
                'success' => false,
                'synced' => 0,
                'message' => 'Ошибка синхронизации групп'
            ]);

        $mock->shouldReceive('syncProducts')
            ->once()
            ->andReturn([
                'success' => true,
                'message' => 'Синхронизировано товаров: 20'
            ]);

        app()->instance(MoySkladService::class, $mock);

        $this->actingAs(H::adminUser())
            ->post(route('products.sync'))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('success', 'Синхронизировано товаров: 20');
    });

    test('доступен только для администратора', function () {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->post(route('products.sync'))
            ->assertForbidden();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// refresh()
// ══════════════════════════════════════════════════════════════════════════════

describe('ProductController refresh()', function () {

    test('редиректит с ошибкой если нет credentials', function () {
        $product = makeProduct();

        $mock = Mockery::mock(MoySkladService::class);
        $mock->shouldReceive('hasCredentials')
            ->once()
            ->andReturn(false);

        app()->instance(MoySkladService::class, $mock);

        $this->actingAs(H::adminUser())
            ->post(route('products.refresh', $product->moysklad_id))
            ->assertRedirect()
            ->assertSessionHas('error', 'Логин или пароль МойСклад не найдены в .env');
    });

    test('редиректит с ошибкой если fetchProduct вернул null', function () {
        $product = makeProduct();

        $mock = Mockery::mock(MoySkladService::class);
        $mock->shouldReceive('hasCredentials')
            ->once()
            ->andReturn(true);
        $mock->shouldReceive('fetchProduct')
            ->once()
            ->with($product->moysklad_id)
            ->andReturn(null);

        app()->instance(MoySkladService::class, $mock);

        $this->actingAs(H::adminUser())
            ->post(route('products.refresh', $product->moysklad_id))
            ->assertRedirect()
            ->assertSessionHas('error', 'Не удалось обновить товар');
    });

    test('создает новый товар если его нет в БД', function () {
        $moyskladId = 'new-product-id';

        $mock = Mockery::mock(MoySkladService::class);
        $mock->shouldReceive('hasCredentials')
            ->once()
            ->andReturn(true);
        $mock->shouldReceive('fetchProduct')
            ->once()
            ->with($moyskladId)
            ->andReturn([
                'id' => $moyskladId,
                'name' => 'Новый товар',
                'article' => 'NEW-PRODUCT',
                'description' => 'Описание',
                'salePrices' => [['value' => 300000]],
                'stock' => 100,
            ]);
        $mock->shouldReceive('extractAttributePublic')
            ->times(3) // prodCostCoeff + masterCostCoeff + hiddenByDefault
            ->andReturn(null);

        app()->instance(MoySkladService::class, $mock);

        expect(Product::where('moysklad_id', $moyskladId)->exists())->toBeFalse();

        $this->actingAs(H::adminUser())
            ->post(route('products.refresh', $moyskladId))
            ->assertRedirect(route('products.show', $moyskladId))
            ->assertSessionHas('success', 'Товар обновлен');

        expect(Product::where('moysklad_id', $moyskladId)->exists())->toBeTrue();
        $product = Product::where('moysklad_id', $moyskladId)->first();
        expect($product->name)->toBe('Новый товар');
    });

    test('переносит флажок hiddenByDefault в is_hidden', function () {
        $product = makeProduct(['moysklad_id' => 'hide-me', 'is_hidden' => false]);

        // Частичный мок: extractAttributePublic настоящий, чтобы проверить чтение флажка
        $mock = Mockery::mock(MoySkladService::class)->makePartial();
        $mock->shouldReceive('hasCredentials')->andReturn(true);
        $mock->shouldReceive('fetchProduct')
            ->once()
            ->with('hide-me')
            ->andReturn([
                'id' => 'hide-me',
                'name' => 'Технический товар',
                'attributes' => [
                    ['name' => 'hiddenByDefault', 'value' => true],
                ],
            ]);

        app()->instance(MoySkladService::class, $mock);

        $this->actingAs(H::adminUser())
            ->post(route('products.refresh', 'hide-me'))
            ->assertRedirect(route('products.show', 'hide-me'));

        expect($product->fresh()->is_hidden)->toBeTrue();
    });

    test('обрабатывает товар без salePrices - price = 0', function () {
        $product = makeProduct(['moysklad_id' => 'no-price-id']);

        $mock = Mockery::mock(MoySkladService::class);
        $mock->shouldReceive('hasCredentials')
            ->once()
            ->andReturn(true);
        $mock->shouldReceive('fetchProduct')
            ->once()
            ->with('no-price-id')
            ->andReturn([
                'id' => 'no-price-id',
                'name' => 'Товар без цены',
                'stock' => 0,
            ]);
        $mock->shouldReceive('extractAttributePublic')
            ->times(3) // prodCostCoeff + masterCostCoeff + hiddenByDefault
            ->andReturn(null);

        app()->instance(MoySkladService::class, $mock);

        $this->actingAs(H::adminUser())
            ->post(route('products.refresh', 'no-price-id'))
            ->assertRedirect();

        $product->refresh();
        expect((float) $product->price)->toBe(0.0);
        expect($product->old_price)->toBeNull();
    });

    test('очищает кэш после обновления товара', function () {
        $product = makeProduct();
        Cache::put('products_tree_json_v4', ['test' => 'data'], 3600);

        $mock = Mockery::mock(MoySkladService::class);
        $mock->shouldReceive('hasCredentials')
            ->once()
            ->andReturn(true);
        $mock->shouldReceive('fetchProduct')
            ->once()
            ->andReturn([
                'id' => $product->moysklad_id,
                'name' => 'Обновленный',
                'salePrices' => [['value' => 100000]],
            ]);
        $mock->shouldReceive('extractAttributePublic')
            ->times(3) // prodCostCoeff + masterCostCoeff + hiddenByDefault
            ->andReturn(null);

        app()->instance(MoySkladService::class, $mock);

        expect(Cache::has('products_tree_json_v4'))->toBeTrue();

        $this->actingAs(H::adminUser())
            ->post(route('products.refresh', $product->moysklad_id));

        expect(Cache::has('products_tree_json_v4'))->toBeFalse();
    });

});

// ══════════════════════════════════════════════════════════════════════════════
// syncStocks()
// ══════════════════════════════════════════════════════════════════════════════

describe('ProductController syncStocks()', function () {

    test('синхронизирует остатки для товара и редиректит с успехом', function () {
        $product = makeProduct();

        $mock = Mockery::mock(StockSyncService::class);
        $mock->shouldReceive('updateProductStocksByMoyskladId')
            ->once()
            ->with($product->moysklad_id)
            ->andReturn([
                'success' => true,
                'message' => 'Остатки обновлены успешно'
            ]);

        app()->instance(StockSyncService::class, $mock);

        $this->actingAs(H::adminUser())
            ->post(route('products.stocks.sync', $product->moysklad_id))
            ->assertRedirect(route('products.show', $product->moysklad_id))
            ->assertSessionHas('success', 'Остатки обновлены успешно');
    });

    test('редиректит с ошибкой при неудачной синхронизации', function () {
        $product = makeProduct();

        $mock = Mockery::mock(StockSyncService::class);
        $mock->shouldReceive('updateProductStocksByMoyskladId')
            ->once()
            ->with($product->moysklad_id)
            ->andReturn([
                'success' => false,
                'message' => 'Ошибка API МойСклад'
            ]);

        app()->instance(StockSyncService::class, $mock);

        $this->actingAs(H::adminUser())
            ->post(route('products.stocks.sync', $product->moysklad_id))
            ->assertRedirect(route('products.show', $product->moysklad_id))
            ->assertSessionHas('error', 'Ошибка API МойСклад');
    });

});

// ══════════════════════════════════════════════════════════════════════════════
// syncAllProductsStocks()
// ══════════════════════════════════════════════════════════════════════════════

describe('ProductController syncAllProductsStocks()', function () {

    test('синхронизирует остатки всех товаров с успехом', function () {
        $mock = Mockery::mock(StockSyncService::class);
        $mock->shouldReceive('syncAllProductsStocksByStores')
            ->once()
            ->andReturn([
                'success' => true,
                'message' => 'Все остатки синхронизированы'
            ]);

        app()->instance(StockSyncService::class, $mock);

        $this->actingAs(H::adminUser())
            ->post(route('products.stocks.sync-all-by-stores'))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('success', 'Все остатки синхронизированы');
    });

    test('редиректит с ошибкой при неудачной синхронизации', function () {
        $mock = Mockery::mock(StockSyncService::class);
        $mock->shouldReceive('syncAllProductsStocksByStores')
            ->once()
            ->andReturn([
                'success' => false,
                'message' => 'Ошибка при синхронизации'
            ]);

        app()->instance(StockSyncService::class, $mock);

        $this->actingAs(H::adminUser())
            ->post(route('products.stocks.sync-all-by-stores'))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('error', 'Ошибка при синхронизации');
    });

});

// ══════════════════════════════════════════════════════════════════════════════
// syncGroups()
// ══════════════════════════════════════════════════════════════════════════════

describe('ProductController syncGroups()', function () {

    test('редиректит с ошибкой если нет credentials', function () {
        $mock = Mockery::mock(MoySkladService::class);
        $mock->shouldReceive('hasCredentials')
            ->once()
            ->andReturn(false);

        app()->instance(MoySkladService::class, $mock);

        $this->actingAs(H::adminUser())
            ->post(route('products.groups.sync'))
            ->assertRedirect(route('products.groups'))
            ->assertSessionHas('error', 'Логин или пароль МойСклад не найдены в .env');
    });

    test('синхронизирует группы с успехом', function () {
        $mock = Mockery::mock(MoySkladService::class);
        $mock->shouldReceive('hasCredentials')
            ->once()
            ->andReturn(true);
        $mock->shouldReceive('syncGroups')
            ->once()
            ->andReturn([
                'success' => true,
                'message' => 'Синхронизировано 10 групп'
            ]);

        app()->instance(MoySkladService::class, $mock);

        $this->actingAs(H::adminUser())
            ->post(route('products.groups.sync'))
            ->assertRedirect(route('products.groups'))
            ->assertSessionHas('success', 'Синхронизировано 10 групп');
    });

    test('редиректит с ошибкой при неудачной синхронизации групп', function () {
        $mock = Mockery::mock(MoySkladService::class);
        $mock->shouldReceive('hasCredentials')
            ->once()
            ->andReturn(true);
        $mock->shouldReceive('syncGroups')
            ->once()
            ->andReturn([
                'success' => false,
                'message' => 'Ошибка синхронизации групп'
            ]);

        app()->instance(MoySkladService::class, $mock);

        $this->actingAs(H::adminUser())
            ->post(route('products.groups.sync'))
            ->assertRedirect(route('products.groups'))
            ->assertSessionHas('error', 'Ошибка синхронизации групп');
    });

});

// ══════════════════════════════════════════════════════════════════════════════
// groupsJson()
// ══════════════════════════════════════════════════════════════════════════════

describe('ProductController groupsJson()', function () {

    test('возвращает JSON с деревом групп', function () {
        // Создаем группу
        $groupId = (string) Str::uuid();
        ProductGroup::create([
            'moysklad_id' => $groupId,
            'name' => 'Тестовая группа',
            'parent_id' => null
        ]);

        // Создаем товар в группе
        $product = makeProduct(['group_id' => $groupId, 'name' => 'Тестовый товар', 'sku' => 'TEST-001']);

        $this->actingAs(H::adminUser())
            ->get(route('api.products.tree'))
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure([
                '*' => ['id', 'name', 'children', 'products']
            ]);
    });

    test('отдаёт признак hidden у каждого товара', function () {
        Cache::flush();

        $groupId = (string) Str::uuid();
        ProductGroup::create(['moysklad_id' => $groupId, 'name' => 'Группа', 'parent_id' => null]);

        $hidden  = makeProduct(['group_id' => $groupId, 'name' => 'Скрытый', 'is_hidden' => true]);
        $visible = makeProduct(['group_id' => $groupId, 'name' => 'Видимый', 'is_hidden' => false]);

        $products = collect(
            $this->actingAs(H::adminUser())
                ->get(route('api.products.tree'))
                ->assertStatus(200)
                ->json('0.products')
        )->keyBy('id');

        // Скрытые не вырезаются на сервере: под «весь каталог» их показывает пикер
        expect($products)->toHaveCount(2)
            ->and($products[$hidden->id]['hidden'])->toBeTrue()
            ->and($products[$visible->id]['hidden'])->toBeFalse();
    });

    test('скрытый товар из загруженной заявки виден и отмечен in_order', function () {
        Cache::flush();
        $groupId = (string) Str::uuid();
        ProductGroup::create(['moysklad_id' => $groupId, 'name' => 'Группа', 'parent_id' => null]);
        $state = OrderState::create(['id' => (string) Str::uuid(), 'name' => 'Новый', 'is_enabled' => true]);

        $ordered = makeProduct(['group_id' => $groupId, 'name' => 'Редкая плитка', 'is_hidden' => true]);
        $other   = makeProduct(['group_id' => $groupId, 'name' => 'Похожая плитка', 'is_hidden' => false]);
        makeOrderWith($ordered, $state->id);

        $products = treeProducts($this);

        expect($products[$ordered->id]['hidden'])->toBeFalse()
            ->and($products[$ordered->id]['in_order'])->toBeTrue()
            ->and($products[$other->id]['in_order'])->toBeFalse();
    });

    test('заявка в неиспользуемом статусе товар не открывает', function () {
        Cache::flush();
        $groupId = (string) Str::uuid();
        ProductGroup::create(['moysklad_id' => $groupId, 'name' => 'Группа', 'parent_id' => null]);
        $state = OrderState::create(['id' => (string) Str::uuid(), 'name' => 'Отгружен', 'is_enabled' => false]);

        $product = makeProduct(['group_id' => $groupId, 'is_hidden' => true]);
        makeOrderWith($product, $state->id);

        $products = treeProducts($this);

        expect($products[$product->id]['hidden'])->toBeTrue()
            ->and($products[$product->id]['in_order'])->toBeFalse();
    });

    test('позиция внутреннего заказа тоже открывает товар', function () {
        Cache::flush();
        $groupId = (string) Str::uuid();
        ProductGroup::create(['moysklad_id' => $groupId, 'name' => 'Группа', 'parent_id' => null]);
        $state = OrderState::create(['id' => (string) Str::uuid(), 'name' => 'В процессе', 'is_enabled' => true]);

        $product = makeProduct(['group_id' => $groupId, 'is_hidden' => true]);
        makeOrderWith($product, $state->id, ['kind' => Order::KIND_INTERNAL, 'moysklad_id' => null]);

        expect(treeProducts($this)[$product->id]['in_order'])->toBeTrue();
    });

    test('заявка в статусе «Вниз» товар не отмечает — и покупателя, и внутренний заказ', function () {
        Cache::flush();
        $groupId = (string) Str::uuid();
        ProductGroup::create(['moysklad_id' => $groupId, 'name' => 'Группа', 'parent_id' => null]);
        $state = OrderState::create([
            'id' => (string) Str::uuid(), 'name' => 'Собран', 'is_enabled' => true, 'is_list_bottom' => true,
        ]);

        $customer = makeProduct(['group_id' => $groupId, 'is_hidden' => true]);
        $internal = makeProduct(['group_id' => $groupId, 'is_hidden' => true]);
        makeOrderWith($customer, $state->id);
        makeOrderWith($internal, $state->id, ['kind' => Order::KIND_INTERNAL, 'moysklad_id' => null]);

        $products = treeProducts($this);

        expect($products[$customer->id]['in_order'])->toBeFalse()
            ->and($products[$customer->id]['hidden'])->toBeTrue()
            ->and($products[$internal->id]['in_order'])->toBeFalse()
            ->and($products[$internal->id]['hidden'])->toBeTrue();
    });

    test('отгруженная целиком позиция товар не отмечает, частично отгруженная — отмечает', function () {
        Cache::flush();
        $groupId = (string) Str::uuid();
        ProductGroup::create(['moysklad_id' => $groupId, 'name' => 'Группа', 'parent_id' => null]);
        $state = OrderState::create(['id' => (string) Str::uuid(), 'name' => 'В процессе', 'is_enabled' => true]);

        $shipped = makeProduct(['group_id' => $groupId, 'is_hidden' => true]);
        $partial = makeProduct(['group_id' => $groupId, 'is_hidden' => true]);
        makeOrderWith($shipped, $state->id, [], ['shipped' => 10]);
        makeOrderWith($partial, $state->id, [], ['shipped' => 4]);

        $products = treeProducts($this);

        expect($products[$shipped->id]['in_order'])->toBeFalse()
            ->and($products[$shipped->id]['hidden'])->toBeTrue()
            ->and($products[$partial->id]['in_order'])->toBeTrue();
    });

    test('товар отмечен, если хоть одна его позиция отгружена не целиком', function () {
        Cache::flush();
        $groupId = (string) Str::uuid();
        ProductGroup::create(['moysklad_id' => $groupId, 'name' => 'Группа', 'parent_id' => null]);
        $state = OrderState::create(['id' => (string) Str::uuid(), 'name' => 'В процессе', 'is_enabled' => true]);

        $product = makeProduct(['group_id' => $groupId, 'is_hidden' => true]);
        makeOrderWith($product, $state->id, [], ['shipped' => 10]);
        makeOrderWith($product, $state->id);

        expect(treeProducts($this)[$product->id]['in_order'])->toBeTrue();
    });

    test('смена статуса заявки видна сразу, без сброса кэша дерева', function () {
        Cache::flush();
        $groupId = (string) Str::uuid();
        ProductGroup::create(['moysklad_id' => $groupId, 'name' => 'Группа', 'parent_id' => null]);
        $used   = OrderState::create(['id' => (string) Str::uuid(), 'name' => 'Новый', 'is_enabled' => true]);
        $unused = OrderState::create(['id' => (string) Str::uuid(), 'name' => 'Отгружен', 'is_enabled' => false]);

        $product = makeProduct(['group_id' => $groupId, 'is_hidden' => true]);
        $order = makeOrderWith($product, $used->id);

        expect(treeProducts($this)[$product->id]['hidden'])->toBeFalse();

        $order->update(['state_moysklad_id' => $unused->id]);

        expect(Cache::has('products_tree_json_v4'))->toBeTrue()
            ->and(treeProducts($this)[$product->id]['hidden'])->toBeTrue();
    });

    test('кэширует ответ на 10 минут', function () {
        Cache::flush();

        // Создаем реальные данные в БД, а не мок
        $groupId = (string) Str::uuid();
        ProductGroup::create([
            'moysklad_id' => $groupId,
            'name' => 'Тестовая группа',
            'parent_id' => null
        ]);

        // Первый запрос - должен закэшироваться
        $response1 = $this->actingAs(H::adminUser())
            ->get(route('api.products.tree'))
            ->assertStatus(200);

        $cachedData = Cache::get('products_tree_json_v4');
        expect($cachedData)->not->toBeNull();

        // Второй запрос - должен вернуть те же данные из кэша
        $response2 = $this->actingAs(H::adminUser())
            ->get(route('api.products.tree'))
            ->assertStatus(200);

        expect($response1->getContent())->toBe($response2->getContent());
    });

});

// ══════════════════════════════════════════════════════════════════════════════
// stocksJson()
// ══════════════════════════════════════════════════════════════════════════════

describe('ProductController stocksJson()', function () {

    test('возвращает JSON с остатками товаров', function () {
        $product = makeProduct();
        $store = Store::factory()->create();

        ProductStock::create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 15.5
        ]);

        $this->actingAs(H::adminUser())
            ->get(route('api.products.stocks'))
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure([
                $product->id => ['total', 'stores']
            ]);
    });

    test('правильно считает общее количество по всем складам', function () {
        $product = makeProduct();
        $store1 = Store::factory()->create();
        $store2 = Store::factory()->create();

        ProductStock::create(['product_id' => $product->id, 'store_id' => $store1->id, 'quantity' => 10.5]);
        ProductStock::create(['product_id' => $product->id, 'store_id' => $store2->id, 'quantity' => 5.2]);

        $response = $this->actingAs(H::adminUser())
            ->get(route('api.products.stocks'))
            ->assertStatus(200);

        $data = $response->json();
        expect($data[$product->id]['total'])->toBe(15.7);
        expect($data[$product->id]['stores'][$store1->id])->toBe(10.5);
        expect($data[$product->id]['stores'][$store2->id])->toBe(5.2);
    });

});

// ══════════════════════════════════════════════════════════════════════════════
// getCoeff()
// ══════════════════════════════════════════════════════════════════════════════

describe('ProductController getCoeff()', function () {

    test('возвращает коэффициент продукта', function () {
        $product = makeProduct(['prod_cost_coeff' => 2.5]);

        $response = $this->actingAs(H::adminUser())
            ->get(route('api.products.coeff', $product))
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/json');

        expect($response->json('prod_cost_coeff'))->toBe(2.5);
    });

    test('возвращает 0 если коэффициент не установлен', function () {
        $product = makeProduct(['prod_cost_coeff' => null]);

        $response = $this->actingAs(H::adminUser())
            ->get(route('api.products.coeff', $product))
            ->assertStatus(200);

        $data = $response->json();
        expect((float) $data['prod_cost_coeff'])->toBe(0.0);
    });

    test('возвращает коэффициент ставки мастера', function () {
        $product = makeProduct(['prod_cost_coeff' => 2.5, 'master_cost_coeff' => 3]);

        $response = $this->actingAs(H::adminUser())
            ->get(route('api.products.coeff', $product))
            ->assertStatus(200);

        expect((float) $response->json('master_cost_coeff'))->toBe(3.0);
    });

});


// ══════════════════════════════════════════════════════════════════════════════
// Синхронизации — только POST: ссылка или картинка с чужой страницы их не запустит
// ══════════════════════════════════════════════════════════════════════════════

describe('Синхронизации товаров — только POST', function () {

    test('GET не запускает синхронизацию — 405', function (string $routeName) {
        $mock = Mockery::mock(MoySkladService::class);
        $mock->shouldNotReceive('syncProducts', 'syncProductGroups', 'refreshProduct');
        app()->instance(MoySkladService::class, $mock);

        $this->actingAs(H::adminUser())
            ->get(route($routeName, ['id' => 'ms-1']))
            ->assertStatus(405);
    })->with(['products.sync', 'products.refresh', 'products.groups.sync']);
});
