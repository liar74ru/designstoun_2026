<?php

use App\Models\Department;
use App\Models\Product;
use App\Support\OperationAccessor;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

beforeEach(fn () => Cache::flush());

/** Все три эндпоинта, закрытые gate'ом use-product-api. */
function papiUrls(Product $product): array
{
    return [
        '/api/products/tree',
        '/api/products/stocks',
        '/api/products/' . $product->id . '/coeff',
    ];
}

describe('Доступ к AJAX-эндпоинтам товаров', function () {

    test('админ получает все три эндпоинта', function () {
        $product = Product::factory()->create();
        $admin   = H::adminUser();

        foreach (papiUrls($product) as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    });

    test('Работник получает 403 на всех трёх эндпоинтах', function () {
        $dept    = Department::create(['name' => 'Цех', 'is_active' => true]);
        $product = Product::factory()->create();
        $user    = Access::userWithPosition('Работник', $dept);

        foreach (papiUrls($product) as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    });

    test('Разнорабочий получает 403 на всех трёх эндпоинтах', function () {
        $dept    = Department::create(['name' => 'Цех', 'is_active' => true]);
        $product = Product::factory()->create();
        $user    = Access::userWithPosition('Разнорабочий', $dept);

        foreach (papiUrls($product) as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    });

    test('мастер с одной лишь операцией «Приёмка» получает доступ (Товары выключены)', function () {
        $dept    = Department::create(['name' => 'Цех', 'is_active' => true]);
        $product = Product::factory()->create();
        $user    = Access::userWithPosition('Мастер', $dept);

        Access::allowOperation($dept, 'stone-receptions');

        expect(OperationAccessor::canSee($user, 'products'))->toBeFalse();

        foreach (papiUrls($product) as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
    });

    test('помощник мастера с операцией «Цех» получает доступ', function () {
        $dept    = Department::create(['name' => 'Цех', 'is_active' => true]);
        $product = Product::factory()->create();
        $user    = Access::userWithPosition('Помощник мастера', $dept);

        Access::allowOperation($dept, 'workshops', ['Помощник мастера']);

        foreach (papiUrls($product) as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
    });

    test('мастер без единой операции из списка получает 403', function () {
        $dept    = Department::create(['name' => 'Цех', 'is_active' => true]);
        $product = Product::factory()->create();
        $user    = Access::userWithPosition('Мастер', $dept);

        // Разрешена операция, не входящая в PRODUCT_API_OPERATIONS.
        Access::allowOperation($dept, 'workers');

        foreach (papiUrls($product) as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    });

});

describe('OperationAccessor::canSeeAny()', function () {

    test('пустой список ключей → false даже для админа', function () {
        expect(OperationAccessor::canSeeAny(H::adminUser(), []))->toBeFalse();
    });

    test('null-пользователь → false', function () {
        expect(OperationAccessor::canSeeAny(null, OperationAccessor::PRODUCT_API_OPERATIONS))->toBeFalse();
    });

    test('true, если доступна хотя бы одна операция из списка', function () {
        $dept = Department::create(['name' => 'Цех', 'is_active' => true]);
        $user = Access::userWithPosition('Мастер', $dept);

        expect(OperationAccessor::canSeeAny($user, OperationAccessor::PRODUCT_API_OPERATIONS))->toBeFalse();

        Access::allowOperation($dept, 'raw-batches');

        expect(OperationAccessor::canSeeAny($user->fresh(), OperationAccessor::PRODUCT_API_OPERATIONS))->toBeTrue();
    });

});
