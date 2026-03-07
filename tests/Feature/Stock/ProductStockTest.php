<?php

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Store;
use App\Models\User;

// ──────────────────────────────────────────────────────────────────────────────
// total_quantity — суммирование остатков (тесты с БД, поэтому здесь в Feature)
// ──────────────────────────────────────────────────────────────────────────────

test('total_quantity суммирует остатки по всем складам', function () {
    $product = Product::factory()->create();
    $store1  = Store::factory()->create();
    $store2  = Store::factory()->create();

    ProductStock::create(['product_id' => $product->id, 'store_id' => $store1->id, 'quantity' => 10.5]);
    ProductStock::create(['product_id' => $product->id, 'store_id' => $store2->id, 'quantity' => 5.5]);

    $product->load('stocks');
    expect($product->total_quantity)->toBe(16.0);
});

test('total_quantity равен 0 если нет остатков', function () {
    $product = Product::factory()->create();
    $product->load('stocks');
    expect($product->total_quantity)->toBe(0.0);
});

test('in_stock возвращает true если total_quantity больше 0', function () {
    $product = Product::factory()->create();
    $store   = Store::factory()->create();
    ProductStock::create(['product_id' => $product->id, 'store_id' => $store->id, 'quantity' => 1]);
    $product->load('stocks');
    expect($product->in_stock)->toBeTrue();
});

test('in_stock возвращает false если остатков нет', function () {
    $product = Product::factory()->create();
    $product->load('stocks');
    expect($product->in_stock)->toBeFalse();
});

// ──────────────────────────────────────────────────────────────────────────────
// ProductStock — автоматический расчёт available
// ──────────────────────────────────────────────────────────────────────────────

test('available считается автоматически при сохранении', function () {
    $product = Product::factory()->create();
    $store   = Store::factory()->create();

    $stock = ProductStock::create([
        'product_id' => $product->id,
        'store_id'   => $store->id,
        'quantity'   => 100,
        'reserved'   => 30,
    ]);

    expect($stock->available)->toBe(70.0);
});

test('available обновляется при изменении reserved', function () {
    $product = Product::factory()->create();
    $store   = Store::factory()->create();

    $stock = ProductStock::create([
        'product_id' => $product->id,
        'store_id'   => $store->id,
        'quantity'   => 50,
        'reserved'   => 10,
    ]);

    $stock->update(['reserved' => 25]);
    $stock->refresh();

    expect($stock->available)->toBe(25.0);
});

test('нельзя создать два остатка для одного товара на одном складе', function () {
    $product = Product::factory()->create();
    $store   = Store::factory()->create();

    ProductStock::create(['product_id' => $product->id, 'store_id' => $store->id, 'quantity' => 10]);

    expect(fn() => ProductStock::create([
        'product_id' => $product->id,
        'store_id'   => $store->id,
        'quantity'   => 5,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('total_quantity через withSum работает без N+1', function () {
    $product = Product::factory()->create();
    $store1  = Store::factory()->create();
    $store2  = Store::factory()->create();

    ProductStock::create(['product_id' => $product->id, 'store_id' => $store1->id, 'quantity' => 7]);
    ProductStock::create(['product_id' => $product->id, 'store_id' => $store2->id, 'quantity' => 3]);

    $loaded = Product::withSum('stocks', 'quantity')->find($product->id);
    expect($loaded->total_quantity)->toBe(10.0);
});

// ──────────────────────────────────────────────────────────────────────────────
// Доступ к списку товаров
// ──────────────────────────────────────────────────────────────────────────────

test('список товаров доступен авторизованному пользователю', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $this->actingAs($user)->get('/products')->assertStatus(200);
});

test('создание товара недоступно без авторизации', function () {
    // /products — публичный, зато POST /products требует auth
    $this->post('/products', ['name' => 'test'])->assertRedirect('/login');
});
