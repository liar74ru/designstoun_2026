<?php

use App\Models\Department;
use App\Models\DepartmentOperationSetting;
use App\Models\Product;
use App\Models\User;
use App\Models\Worker;
use App\Support\OperationAccessor;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

function papiUserWith(string $position, ?Department $dept = null): User
{
    $worker = Worker::create([
        'name'          => $position . ' Тест',
        'position'      => $position,
        'department_id' => $dept?->id,
    ]);

    return User::factory()->create(['is_admin' => false, 'worker_id' => $worker->id]);
}

function papiAdminUser(): User
{
    return User::factory()->create(['is_admin' => true, 'worker_id' => null]);
}

function papiAllowFor(Department $dept, string $opKey, array $positions = ['Мастер']): void
{
    DepartmentOperationSetting::updateOrCreate(
        ['department_id' => $dept->id, 'operation_key' => $opKey],
        ['enabled' => true, 'config' => ['positions' => $positions]],
    );
    $dept->forgetOperationsCache();
}

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
        $admin   = papiAdminUser();

        foreach (papiUrls($product) as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    });

    test('Работник получает 403 на всех трёх эндпоинтах', function () {
        $dept    = Department::create(['name' => 'Цех', 'is_active' => true]);
        $product = Product::factory()->create();
        $user    = papiUserWith('Работник', $dept);

        foreach (papiUrls($product) as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    });

    test('Разнорабочий получает 403 на всех трёх эндпоинтах', function () {
        $dept    = Department::create(['name' => 'Цех', 'is_active' => true]);
        $product = Product::factory()->create();
        $user    = papiUserWith('Разнорабочий', $dept);

        foreach (papiUrls($product) as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    });

    test('мастер с одной лишь операцией «Приёмка» получает доступ (Товары выключены)', function () {
        $dept    = Department::create(['name' => 'Цех', 'is_active' => true]);
        $product = Product::factory()->create();
        $user    = papiUserWith('Мастер', $dept);

        papiAllowFor($dept, 'stone-receptions');

        expect(OperationAccessor::canSee($user, 'products'))->toBeFalse();

        foreach (papiUrls($product) as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
    });

    test('помощник мастера с операцией «Цех» получает доступ', function () {
        $dept    = Department::create(['name' => 'Цех', 'is_active' => true]);
        $product = Product::factory()->create();
        $user    = papiUserWith('Помощник мастера', $dept);

        papiAllowFor($dept, 'workshops', ['Помощник мастера']);

        foreach (papiUrls($product) as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
    });

    test('мастер без единой операции из списка получает 403', function () {
        $dept    = Department::create(['name' => 'Цех', 'is_active' => true]);
        $product = Product::factory()->create();
        $user    = papiUserWith('Мастер', $dept);

        // Разрешена операция, не входящая в PRODUCT_API_OPERATIONS.
        papiAllowFor($dept, 'orders');

        foreach (papiUrls($product) as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    });

    test('гость перенаправляется на логин, а не получает данные', function () {
        $product = Product::factory()->create();

        foreach (papiUrls($product) as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    });

});

describe('OperationAccessor::canSeeAny()', function () {

    test('пустой список ключей → false даже для админа', function () {
        expect(OperationAccessor::canSeeAny(papiAdminUser(), []))->toBeFalse();
    });

    test('null-пользователь → false', function () {
        expect(OperationAccessor::canSeeAny(null, OperationAccessor::PRODUCT_API_OPERATIONS))->toBeFalse();
    });

    test('true, если доступна хотя бы одна операция из списка', function () {
        $dept = Department::create(['name' => 'Цех', 'is_active' => true]);
        $user = papiUserWith('Мастер', $dept);

        expect(OperationAccessor::canSeeAny($user, OperationAccessor::PRODUCT_API_OPERATIONS))->toBeFalse();

        papiAllowFor($dept, 'raw-batches');

        expect(OperationAccessor::canSeeAny($user->fresh(), OperationAccessor::PRODUCT_API_OPERATIONS))->toBeTrue();
    });

});
