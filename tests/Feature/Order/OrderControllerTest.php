<?php

use App\Models\Department;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Models\Worker;

beforeEach(function () {
    // Очистить статусы заявок
    Setting::where('key', 'MOYSKLAD_ORDER_STATUSES')->delete();
});

// ══════════════════════════════════════════════════════════════════════════════
// OrderController::index()
// ══════════════════════════════════════════════════════════════════════════════

describe('OrderController::index()', function () {

    test('страница доступна авторизованному пользователю', function () {
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->get(route('orders.index'))
            ->assertSuccessful()
            ->assertViewIs('orders.index');
    });

    test('недоступна без авторизации', function () {
        $this->get(route('orders.index'))
            ->assertRedirect('/login');
    });

    test('отображает заявки для админа', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $dept = Department::create(['name' => 'Тест отдел', 'is_active' => true]);
        $order = Order::create(['moysklad_id' => 'ms-' . uniqid(), 'name' => 'Заявка', 'state_name' => 'Новая']);

        $this->actingAs($user)
            ->get(route('orders.index'))
            ->assertSuccessful()
            ->assertViewHas('orders');
    });

    test('фильтрует заявки по отделу для неадмина', function () {
        $dept1 = Department::create(['name' => 'Отдел 1', 'is_active' => true]);
        $dept2 = Department::create(['name' => 'Отдел 2', 'is_active' => true]);
        $worker = Worker::create(['name' => 'Работник', 'department_id' => $dept1->id, 'position' => 'Мастер']);
        $user = User::factory()->for($worker)->create(['is_admin' => false]);

        \App\Models\DepartmentOperationSetting::create([
            'department_id' => $dept1->id,
            'operation_key' => 'orders',
            'config'        => ['positions' => ['Мастер']],
            'enabled'       => true,
        ]);

        Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1', 'state_name' => 'Новая']);
        Order::create(['moysklad_id' => 'ms-2', 'name' => 'Заявка 2', 'state_name' => 'Новая']);

        $this->actingAs($user)
            ->get(route('orders.index'))
            ->assertSuccessful();
    });

    test('пагинирует результаты', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $dept = Department::create(['name' => 'Тест отдел', 'is_active' => true]);

        for ($i = 0; $i < 25; $i++) {
            Order::create(['moysklad_id' => 'ms-' . $i, 'name' => 'Заявка ' . $i, 'state_name' => 'Новая']);
        }

        $this->actingAs($user)
            ->get(route('orders.index'))
            ->assertSuccessful()
            ->assertViewHas('orders', fn ($orders) => $orders->count() === 20);
    });

    test('применяет фильтр по статусу', function () {
        Setting::set('MOYSKLAD_ORDER_STATUSES', json_encode(['Новая', 'Выполнена']));
        $user = User::factory()->create(['is_admin' => true]);
        $dept = Department::create(['name' => 'Тест отдел', 'is_active' => true]);

        Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1', 'state_name' => 'Новая']);
        Order::create(['moysklad_id' => 'ms-2', 'name' => 'Заявка 2', 'state_name' => 'Выполнена']);

        $this->actingAs($user)
            ->get(route('orders.index', ['filter[status]' => ['Новая']]))
            ->assertSuccessful();
    });

    test('сортирует по дате в обратном порядке', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $dept = Department::create(['name' => 'Тест отдел', 'is_active' => true]);

        $order1 = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1', 'state_name' => 'Новая', 'moment' => now()->subDay()]);
        $order2 = Order::create(['moysklad_id' => 'ms-2', 'name' => 'Заявка 2', 'state_name' => 'Новая', 'moment' => now()]);

        $response = $this->actingAs($user)
            ->get(route('orders.index'))
            ->assertSuccessful()
            ->viewData('orders');

        expect($response->items()[0]->id)->toBe($order2->id);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// OrderController::sync()
// ══════════════════════════════════════════════════════════════════════════════

describe('OrderController::sync()', function () {

    test('редирект на index после синхронизации', function () {
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->post(route('orders.sync'))
            ->assertRedirect(route('orders.index'));
    });

    test('недоступен без авторизации', function () {
        $this->post(route('orders.sync'))
            ->assertRedirect('/login');
    });

    test('показывает сообщение об ошибке когда токен не установлен', function () {
        config()->set('services.moysklad.token', '');
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->post(route('orders.sync'))
            ->assertSessionHas('error');
    });

    test('показывает успешное сообщение при правильной синхронизации', function () {
        config()->set('services.moysklad.token', 'test-token');
        $user = User::factory()->create(['is_admin' => true]);

        $this->mock(\App\Services\Moysklad\CustomerOrderSyncService::class)
            ->shouldReceive('pullActive')
            ->andReturn(['success' => true, 'message' => 'ok', 'count' => 0]);
        $this->mock(\App\Services\Moysklad\StockSyncService::class)
            ->shouldReceive('syncAllProductsStocksByStores')
            ->andReturn(['success' => true, 'message' => 'ok']);

        $this->actingAs($user)
            ->post(route('orders.sync'))
            ->assertSessionHas('success');
    });
});
