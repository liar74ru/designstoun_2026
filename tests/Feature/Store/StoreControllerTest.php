<?php

use App\Models\Store;
use App\Services\MoySkladService;
use App\Services\StockSyncService;
use Tests\Helpers\ReceptionTestHelper as H;

function makeStore(array $attrs = []): Store
{
    return Store::factory()->create(array_merge(['archived' => false], $attrs));
}

function mockMoySkladService(bool $success = true): void
{
    $mock = Mockery::mock(MoySkladService::class);
    $mock->shouldReceive('syncStores')->andReturn([
        'success' => $success,
        'message' => $success ? 'Синхронизировано' : 'Ошибка API',
    ]);
    app()->instance(MoySkladService::class, $mock);
}

function mockStockSyncService(bool $success = true): void
{
    $mock = Mockery::mock(StockSyncService::class);
    $mock->shouldReceive('syncAllStocksByStores')->andReturn([
        'success' => $success,
        'message' => $success ? 'Остатки синхронизированы' : 'Ошибка остатков',
    ]);
    $mock->shouldReceive('syncStocksByStore')->andReturn([
        'success' => $success,
        'message' => $success ? 'Склад синхронизирован' : 'Ошибка склада',
    ]);
    app()->instance(StockSyncService::class, $mock);
}

// ══════════════════════════════════════════════════════════════════════════════
// index()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoreController index()', function () {

    test('доступна авторизованному', function () {
        $this->actingAs(H::adminUser())
            ->get(route('stores.index'))
            ->assertStatus(200);
    });

    test('недоступна без авторизации', function () {
        $this->get(route('stores.index'))
            ->assertRedirect('/login');
    });

    test('показывает активные склады', function () {
        makeStore(['name' => 'Склад Активный']);
        makeStore(['name' => 'Склад Архивный', 'archived' => true]);

        $this->actingAs(H::adminUser())
            ->get(route('stores.index'))
            ->assertStatus(200)
            ->assertSee('Склад Активный')
            ->assertDontSee('Склад Архивный');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// show()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoreController show()', function () {

    test('показывает страницу склада', function () {
        $store = makeStore(['name' => 'Главный склад']);

        $this->actingAs(H::adminUser())
            ->get(route('stores.show', $store))
            ->assertStatus(200);
    });

    test('404 для несуществующего склада', function () {
        $this->actingAs(H::adminUser())
            ->get(route('stores.show', 99999))
            ->assertStatus(404);
    });

    test('недоступна без авторизации', function () {
        $store = makeStore();

        $this->get(route('stores.show', $store))
            ->assertRedirect('/login');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// sync()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoreController sync()', function () {

    test('успешная синхронизация редиректит с success', function () {
        mockMoySkladService(true);

        $this->actingAs(H::adminUser())
            ->post(route('stores.sync'))
            ->assertRedirect(route('stores.index'))
            ->assertSessionHas('success');
    });

    test('ошибка синхронизации редиректит с error', function () {
        mockMoySkladService(false);

        $this->actingAs(H::adminUser())
            ->post(route('stores.sync'))
            ->assertRedirect(route('stores.index'))
            ->assertSessionHas('error');
    });

    test('недоступна без авторизации', function () {
        $this->post(route('stores.sync'))
            ->assertRedirect('/login');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// syncAllStocks()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoreController syncAllStocks()', function () {

    test('успешная синхронизация всех остатков', function () {
        mockStockSyncService(true);

        $this->actingAs(H::adminUser())
            ->post(route('stores.stocks.sync-all'))
            ->assertRedirect(route('stores.index'))
            ->assertSessionHas('success');
    });

    test('ошибка синхронизации всех остатков', function () {
        mockStockSyncService(false);

        $this->actingAs(H::adminUser())
            ->post(route('stores.stocks.sync-all'))
            ->assertRedirect(route('stores.index'))
            ->assertSessionHas('error');
    });

    test('недоступна без авторизации', function () {
        $this->post(route('stores.stocks.sync-all'))
            ->assertRedirect('/login');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// syncStoreStocks()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoreController syncStoreStocks()', function () {

    test('успешная синхронизация остатков конкретного склада', function () {
        mockStockSyncService(true);
        $store = makeStore(['name' => 'Тестовый склад']);

        $this->actingAs(H::adminUser())
            ->post(route('stores.stocks.sync', $store))
            ->assertRedirect(route('stores.index'))
            ->assertSessionHas('success');
    });

    test('ошибка синхронизации остатков конкретного склада', function () {
        mockStockSyncService(false);
        $store = makeStore();

        $this->actingAs(H::adminUser())
            ->post(route('stores.stocks.sync', $store))
            ->assertRedirect(route('stores.index'))
            ->assertSessionHas('error');
    });

    test('недоступна без авторизации', function () {
        $store = makeStore();

        $this->post(route('stores.stocks.sync', $store))
            ->assertRedirect('/login');
    });
});
