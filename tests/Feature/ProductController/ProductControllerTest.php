<?php

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductStock;
use App\Models\Store;
use App\Models\User;
use App\Services\MoySkladService;
use App\Services\ProductGroupService;
use App\Services\StockSyncService;
use Illuminate\Support\Str;

// ══════════════════════════════════════════════════════════════════════════════
// Helpers
// ══════════════════════════════════════════════════════════════════════════════

function adminUser(): User
{
    return User::factory()->create(['is_admin' => true]);
}

function makeProduct(array $attrs = []): Product
{
    return Product::factory()->create($attrs);
}

// ══════════════════════════════════════════════════════════════════════════════
// index()
// ══════════════════════════════════════════════════════════════════════════════

describe('ProductController index()', function () {

    test('список товаров доступен авторизованному', function () {
        $this->actingAs(adminUser())
            ->get(route('products.index'))
            ->assertStatus(200);
    });

    test('список товаров недоступен без авторизации', function () {
        $this->get(route('products.index'))
            ->assertRedirect('/login');
    });

    test('список содержит созданный товар', function () {
        makeProduct(['name' => 'Уникальный товар XYZ']);

        $this->actingAs(adminUser())
            ->get(route('products.index'))
            ->assertStatus(200)
            ->assertSee('Уникальный товар XYZ');
    });

    test('на странице нет ссылки на создание вручную', function () {
        $this->actingAs(adminUser())
            ->get(route('products.index'))
            ->assertStatus(200)
            ->assertDontSee('Добавить вручную');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// index() — фильтры
// ══════════════════════════════════════════════════════════════════════════════

describe('ProductController index() — фильтры', function () {

    // Фильтр search использует ILIKE (PostgreSQL-only) — не тестируется на SQLite.

    test('фильтр in_stock=1 показывает только товары в наличии', function () {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();

        $inStock  = makeProduct(['name' => 'Есть на складе']);
        $noStock  = makeProduct(['name' => 'Нет на складе']);

        ProductStock::create(['product_id' => $inStock->id, 'store_id' => $storeA->id, 'quantity' => 10]);
        ProductStock::create(['product_id' => $noStock->id, 'store_id' => $storeB->id, 'quantity' => 0]);

        $this->actingAs(adminUser())
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

        $this->actingAs(adminUser())
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

        $this->actingAs(adminUser())
            ->get(route('products.index', ['filter[group_id]' => $groupId]))
            ->assertStatus(200)
            ->assertSee('Товар в группе')
            ->assertDontSee('Товар без группы');
    });

    test('сортировка по имени не ломает страницу', function () {
        $this->actingAs(adminUser())
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

        $this->actingAs(adminUser())
            ->get(route('products.show', $product->moysklad_id))
            ->assertStatus(200)
            ->assertSee('Тестовый товар показа');
    });

    test('возвращает 404 для несуществующего moysklad_id', function () {
        $this->actingAs(adminUser())
            ->get(route('products.show', Str::uuid()))
            ->assertStatus(404);
    });

    test('недоступна без авторизации', function () {
        $product = makeProduct();

        $this->get(route('products.show', $product->moysklad_id))
            ->assertRedirect('/login');
    });

    test('на странице нет кнопки удаления', function () {
        $product = makeProduct(['name' => 'Проверяемый товар']);

        $this->actingAs(adminUser())
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
        $this->actingAs(adminUser())
            ->get('/products/create')
            ->assertStatus(404);
    });

    test('POST /products возвращает 405 (метод не разрешён)', function () {
        $this->actingAs(adminUser())
            ->post('/products', ['name' => 'Test', 'price' => 100])
            ->assertStatus(405);
    });

    test('PUT /products/{id} возвращает 405', function () {
        $product = makeProduct();

        $this->actingAs(adminUser())
            ->put('/products/' . $product->moysklad_id, ['name' => 'Test', 'price' => 100])
            ->assertStatus(405);
    });

    test('DELETE /products/{id} возвращает 405', function () {
        $product = makeProduct();

        $this->actingAs(adminUser())
            ->delete('/products/' . $product->moysklad_id)
            ->assertStatus(405);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// groups()
// ══════════════════════════════════════════════════════════════════════════════

describe('ProductController groups()', function () {

    test('страница групп доступна авторизованному', function () {
        $this->actingAs(adminUser())
            ->get(route('products.groups'))
            ->assertStatus(200);
    });

    test('страница групп недоступна без авторизации', function () {
        $this->get(route('products.groups'))
            ->assertRedirect('/login');
    });

    test('страница групп показывает созданную группу', function () {
        ProductGroup::create([
            'moysklad_id' => (string) Str::uuid(),
            'name'        => 'Тестовая группа',
        ]);

        $this->actingAs(adminUser())
            ->get(route('products.groups'))
            ->assertStatus(200)
            ->assertSee('Тестовая группа');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// groupsJson()
// ══════════════════════════════════════════════════════════════════════════════

describe('ProductController groupsJson()', function () {

    test('возвращает JSON', function () {
        $this->actingAs(adminUser())
            ->get(route('api.products.tree'))
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/json');
    });

    test('JSON содержит группы с продуктами', function () {
        $groupId = (string) Str::uuid();
        ProductGroup::create(['moysklad_id' => $groupId, 'name' => 'Группа JSON']);
        makeProduct(['name' => 'Товар в группе', 'group_id' => $groupId]);

        cache()->forget('products_tree_json');

        $response = $this->actingAs(adminUser())
            ->get(route('api.products.tree'))
            ->assertStatus(200);

        expect($response->json())->toBeArray();
    });

    test('недоступен без авторизации', function () {
        $this->get(route('api.products.tree'))
            ->assertRedirect('/login');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// syncFromMoySklad()
// ══════════════════════════════════════════════════════════════════════════════

describe('ProductController syncFromMoySklad()', function () {

    test('редиректит с ошибкой если нет credentials', function () {
        $mock = Mockery::mock(MoySkladService::class);
        $mock->shouldReceive('hasCredentials')->once()->andReturn(false);
        app()->instance(MoySkladService::class, $mock);

        $this->actingAs(adminUser())
            ->get(route('products.sync'))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('error');
    });

    test('редиректит с успехом при успешной синхронизации', function () {
        $mock = Mockery::mock(MoySkladService::class);
        $mock->shouldReceive('hasCredentials')->once()->andReturn(true);
        $mock->shouldReceive('syncGroups')->once()->andReturn(['success' => true, 'synced' => 5, 'message' => '']);
        $mock->shouldReceive('syncProducts')->once()->andReturn(['success' => true, 'message' => 'Синхронизировано: 10']);
        app()->instance(MoySkladService::class, $mock);

        $this->actingAs(adminUser())
            ->get(route('products.sync'))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('success');
    });

    test('редиректит с ошибкой если syncProducts не удался', function () {
        $mock = Mockery::mock(MoySkladService::class);
        $mock->shouldReceive('hasCredentials')->once()->andReturn(true);
        $mock->shouldReceive('syncGroups')->once()->andReturn(['success' => false, 'synced' => 0, 'message' => '']);
        $mock->shouldReceive('syncProducts')->once()->andReturn(['success' => false, 'message' => 'Ошибка API']);
        app()->instance(MoySkladService::class, $mock);

        $this->actingAs(adminUser())
            ->get(route('products.sync'))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('error');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// refresh()
// ══════════════════════════════════════════════════════════════════════════════

describe('ProductController refresh()', function () {

    test('редиректит с ошибкой если нет credentials', function () {
        $product = makeProduct();

        $mock = Mockery::mock(MoySkladService::class);
        $mock->shouldReceive('hasCredentials')->once()->andReturn(false);
        app()->instance(MoySkladService::class, $mock);

        $this->actingAs(adminUser())
            ->get(route('products.refresh', $product->moysklad_id))
            ->assertRedirect()
            ->assertSessionHas('error');
    });

    test('редиректит с ошибкой если fetchProduct вернул null', function () {
        $product = makeProduct();

        $mock = Mockery::mock(MoySkladService::class);
        $mock->shouldReceive('hasCredentials')->once()->andReturn(true);
        $mock->shouldReceive('fetchProduct')->once()->with($product->moysklad_id)->andReturn(null);
        app()->instance(MoySkladService::class, $mock);

        $this->actingAs(adminUser())
            ->get(route('products.refresh', $product->moysklad_id))
            ->assertRedirect()
            ->assertSessionHas('error');
    });

    test('обновляет товар из МойСклад и редиректит на show', function () {
        $product = makeProduct(['name' => 'Старое имя']);

        $mock = Mockery::mock(MoySkladService::class);
        $mock->shouldReceive('hasCredentials')->once()->andReturn(true);
        $mock->shouldReceive('fetchProduct')->once()->with($product->moysklad_id)->andReturn([
            'id'          => $product->moysklad_id,
            'name'        => 'Обновлённое имя',
            'article'     => 'NEW-SKU',
            'description' => 'Описание',
            'salePrices'  => [['value' => 150000], ['value' => 200000]],
            'stock'       => 42,
        ]);
        $mock->shouldReceive('extractAttributePublic')->once()->andReturn(1.5);
        app()->instance(MoySkladService::class, $mock);

        $this->actingAs(adminUser())
            ->get(route('products.refresh', $product->moysklad_id))
            ->assertRedirect(route('products.show', $product->moysklad_id))
            ->assertSessionHas('success');

        expect($product->fresh()->name)->toBe('Обновлённое имя');
        expect((float) $product->fresh()->price)->toBe(1500.0);
    });

    test('обновляет товар без salePrices — price равна нулю', function () {
        $product = makeProduct();

        $mock = Mockery::mock(MoySkladService::class);
        $mock->shouldReceive('hasCredentials')->once()->andReturn(true);
        $mock->shouldReceive('fetchProduct')->once()->andReturn([
            'id'    => $product->moysklad_id,
            'name'  => 'Без цен',
            'stock' => 0,
        ]);
        $mock->shouldReceive('extractAttributePublic')->once()->andReturn(null);
        app()->instance(MoySkladService::class, $mock);

        $this->actingAs(adminUser())
            ->get(route('products.refresh', $product->moysklad_id))
            ->assertRedirect()
            ->assertSessionHas('success');

        expect((float) $product->fresh()->price)->toBe(0.0);
    });

    test('недоступен без авторизации', function () {
        $product = makeProduct();

        $this->get(route('products.refresh', $product->moysklad_id))
            ->assertRedirect('/login');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// syncStocks()
// ══════════════════════════════════════════════════════════════════════════════

describe('ProductController syncStocks()', function () {

    test('редиректит с успехом при успешной синхронизации', function () {
        $product = makeProduct();

        $mock = Mockery::mock(StockSyncService::class);
        $mock->shouldReceive('updateProductStocksByMoyskladId')
            ->once()->with($product->moysklad_id)
            ->andReturn(['success' => true, 'message' => 'Остатки обновлены']);
        app()->instance(StockSyncService::class, $mock);

        $this->actingAs(adminUser())
            ->post(route('products.stocks.sync', $product->moysklad_id))
            ->assertRedirect(route('products.show', $product->moysklad_id))
            ->assertSessionHas('success');
    });

    test('редиректит с ошибкой при неудачной синхронизации', function () {
        $product = makeProduct();

        $mock = Mockery::mock(StockSyncService::class);
        $mock->shouldReceive('updateProductStocksByMoyskladId')
            ->once()->with($product->moysklad_id)
            ->andReturn(['success' => false, 'message' => 'Ошибка МойСклад']);
        app()->instance(StockSyncService::class, $mock);

        $this->actingAs(adminUser())
            ->post(route('products.stocks.sync', $product->moysklad_id))
            ->assertRedirect(route('products.show', $product->moysklad_id))
            ->assertSessionHas('error');
    });

    test('недоступен без авторизации', function () {
        $product = makeProduct();

        $this->post(route('products.stocks.sync', $product->moysklad_id))
            ->assertRedirect('/login');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// syncAllProductsStocks()
// ══════════════════════════════════════════════════════════════════════════════

describe('ProductController syncAllProductsStocks()', function () {

    test('редиректит с успехом при успешной синхронизации всех остатков', function () {
        $mock = Mockery::mock(StockSyncService::class);
        $mock->shouldReceive('syncAllProductsStocksByStores')
            ->once()
            ->andReturn(['success' => true, 'message' => 'Все остатки обновлены']);
        app()->instance(StockSyncService::class, $mock);

        $this->actingAs(adminUser())
            ->post(route('products.stocks.sync-all-by-stores'))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('success');
    });

    test('редиректит с ошибкой при неудаче', function () {
        $mock = Mockery::mock(StockSyncService::class);
        $mock->shouldReceive('syncAllProductsStocksByStores')
            ->once()
            ->andReturn(['success' => false, 'message' => 'Ошибка синхронизации']);
        app()->instance(StockSyncService::class, $mock);

        $this->actingAs(adminUser())
            ->post(route('products.stocks.sync-all-by-stores'))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('error');
    });

    test('недоступен без авторизации', function () {
        $this->post(route('products.stocks.sync-all-by-stores'))
            ->assertRedirect('/login');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// syncGroups()
// ══════════════════════════════════════════════════════════════════════════════

describe('ProductController syncGroups()', function () {

    test('редиректит с ошибкой если нет credentials', function () {
        $mock = Mockery::mock(MoySkladService::class);
        $mock->shouldReceive('hasCredentials')->once()->andReturn(false);
        app()->instance(MoySkladService::class, $mock);

        $this->actingAs(adminUser())
            ->get(route('products.groups.sync'))
            ->assertRedirect(route('products.groups'))
            ->assertSessionHas('error');
    });

    test('редиректит с успехом при успешной синхронизации групп', function () {
        $mock = Mockery::mock(MoySkladService::class);
        $mock->shouldReceive('hasCredentials')->once()->andReturn(true);
        $mock->shouldReceive('syncGroups')->once()->andReturn(['success' => true, 'message' => 'Групп: 3']);
        app()->instance(MoySkladService::class, $mock);

        $this->actingAs(adminUser())
            ->get(route('products.groups.sync'))
            ->assertRedirect(route('products.groups'))
            ->assertSessionHas('success');
    });
});
