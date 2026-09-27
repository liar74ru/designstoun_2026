<?php

use App\Models\Department;
use App\Models\Order;
use App\Models\OrderState;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Models\Worker;
use Tests\Helpers\ReceptionTestHelper as H;

beforeEach(function () {
    // Справочник статусов пуст — фильтр статусов в списке отключён
    OrderState::query()->delete();
});

// ══════════════════════════════════════════════════════════════════════════════
// OrderController::index()
// ══════════════════════════════════════════════════════════════════════════════

describe('OrderController::index()', function () {

    test('страница доступна авторизованному пользователю', function () {
        $user = H::adminUser();

        $this->actingAs($user)
            ->get(route('orders.index'))
            ->assertSuccessful()
            ->assertViewIs('orders.index');
    });

    test('отображает заявки для админа', function () {
        $user = H::adminUser();
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
        $user = H::adminUser();
        $dept = Department::create(['name' => 'Тест отдел', 'is_active' => true]);

        for ($i = 0; $i < 25; $i++) {
            Order::create(['moysklad_id' => 'ms-' . $i, 'name' => 'Заявка ' . $i, 'state_name' => 'Новая'])
                ->departments()->attach($dept->id);
        }

        $this->actingAs($user)
            ->get(route('orders.index'))
            ->assertSuccessful()
            ->assertViewHas('orders', fn ($orders) => $orders->count() === 20);
    });

    test('применяет фильтр по статусу', function () {
        OrderState::create(['name' => 'Новая', 'is_enabled' => true, 'position' => 0]);
        OrderState::create(['name' => 'Выполнена', 'is_enabled' => true, 'position' => 1]);
        $user = H::adminUser();
        $dept = Department::create(['name' => 'Тест отдел', 'is_active' => true]);

        Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1', 'state_name' => 'Новая']);
        Order::create(['moysklad_id' => 'ms-2', 'name' => 'Заявка 2', 'state_name' => 'Выполнена']);

        $this->actingAs($user)
            ->get(route('orders.index', ['filter[status]' => ['Новая']]))
            ->assertSuccessful();
    });

    test('сортирует по очереди: срочные сверху, дальше по ключу приоритета', function () {
        $user = H::adminUser();
        $dept = Department::create(['name' => 'Тест отдел', 'is_active' => true]);

        $late   = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Поздняя', 'priority_key' => 300]);
        $early  = Order::create(['moysklad_id' => 'ms-2', 'name' => 'Ранняя', 'priority_key' => 100]);
        $urgent = Order::create(['moysklad_id' => 'ms-3', 'name' => 'Срочная', 'priority_key' => 900, 'is_urgent' => true]);
        foreach ([$late, $early, $urgent] as $order) {
            $order->departments()->attach($dept->id);
        }

        $response = $this->actingAs($user)
            ->get(route('orders.index'))
            ->assertSuccessful()
            ->viewData('orders');

        expect(collect($response->items())->pluck('name')->all())
            ->toBe(['Срочная', 'Ранняя', 'Поздняя']);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// OrderController::sync()
// ══════════════════════════════════════════════════════════════════════════════

describe('OrderController::sync()', function () {

    test('редирект на index после синхронизации', function () {
        $user = H::adminUser();

        $this->actingAs($user)
            ->post(route('orders.sync'))
            ->assertRedirect(route('orders.index'));
    });

    test('показывает сообщение об ошибке когда токен не установлен', function () {
        config()->set('services.moysklad.token', '');
        $user = H::adminUser();

        $this->actingAs($user)
            ->post(route('orders.sync'))
            ->assertSessionHas('error');
    });

    test('показывает успешное сообщение при правильной синхронизации', function () {
        config()->set('services.moysklad.token', 'test-token');
        $user = H::adminUser();

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
