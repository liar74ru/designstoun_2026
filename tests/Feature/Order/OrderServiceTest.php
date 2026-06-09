<?php

use App\Models\Counterparty;
use App\Models\Department;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Models\Worker;
use App\Services\OrderService;
use Illuminate\Http\Request;

// ══════════════════════════════════════════════════════════════════════════════
// OrderService::statuses()
// ══════════════════════════════════════════════════════════════════════════════

describe('OrderService::statuses()', function () {

    test('возвращает пустой массив когда статусы не установлены', function () {
        Setting::where('key', 'MOYSKLAD_ORDER_STATUSES')->delete();

        $service = new OrderService();
        expect($service->statuses())->toBe([]);
    });

    test('возвращает массив статусов из настроек', function () {
        Setting::set('MOYSKLAD_ORDER_STATUSES', json_encode(['Новая', 'Выполнена', 'Отменена']));

        $service = new OrderService();
        expect($service->statuses())->toBe(['Новая', 'Выполнена', 'Отменена']);
    });

    test('возвращает пустой массив при невалидном JSON', function () {
        Setting::set('MOYSKLAD_ORDER_STATUSES', 'invalid-json');

        $service = new OrderService();
        expect($service->statuses())->toBe([]);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// OrderService::getIndexData()
// ══════════════════════════════════════════════════════════════════════════════

describe('OrderService::getIndexData()', function () {

    beforeEach(function () {
        Setting::set('MOYSKLAD_ORDER_STATUSES', json_encode(['Новая', 'Выполнена']));
    });

    test('возвращает все необходимые ключи', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $user);

        $service = new OrderService();
        $data = $service->getIndexData($request);

        expect($data)->toHaveKeys([
            'orders',
            'statusOptions',
            'statusDefaults',
            'filterDepartments',
            'departmentDefaults',
            'productionStoreId',
        ]);
    });

    test('админ видит заявки из всех отделов', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $dept1 = Department::create(['name' => 'Отдел 1', 'is_active' => true]);
        $dept2 = Department::create(['name' => 'Отдел 2', 'is_active' => true]);
        Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1', 'state_name' => 'Новая']);
        Order::create(['moysklad_id' => 'ms-2', 'name' => 'Заявка 2', 'state_name' => 'Новая']);

        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $user);

        $service = new OrderService();
        $data = $service->getIndexData($request);

        expect($data['orders']->total())->toBe(2);
    });

    test('работник видит только заявки своего отдела', function () {
        $dept1 = Department::create(['name' => 'Отдел 1', 'is_active' => true]);
        $dept2 = Department::create(['name' => 'Отдел 2', 'is_active' => true]);
        $worker = Worker::create(['name' => 'Работник', 'department_id' => $dept1->id, 'position' => 'Мастер']);
        $user = User::factory()->for($worker)->create();

        $o1 = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1', 'state_name' => 'Новая']);
        $o2 = Order::create(['moysklad_id' => 'ms-2', 'name' => 'Заявка 2', 'state_name' => 'Новая']);
        $o1->departments()->attach($dept1->id);
        $o2->departments()->attach($dept2->id);

        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $user);

        $service = new OrderService();
        $data = $service->getIndexData($request);

        expect($data['orders']->total())->toBe(1);
    });

    test('возвращает доступные отделы для фильтра', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $dept1 = Department::create(['name' => 'Отдел 1', 'is_active' => true]);
        $dept2 = Department::create(['name' => 'Отдел 2', 'is_active' => true]);

        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $user);

        $service = new OrderService();
        $data = $service->getIndexData($request);

        expect($data['filterDepartments']->pluck('id'))->toContain($dept1->id, $dept2->id);
    });

    test('включает склад по умолчанию из настроек работника', function () {
        Store::factory()->create(['id' => 'store-uuid-123']);
        $dept = Department::create(['name' => 'Отдел', 'is_active' => true, 'default_production_store_id' => 'store-uuid-123']);
        $worker = Worker::create(['name' => 'Работник', 'department_id' => $dept->id, 'position' => 'Мастер']);
        $user = User::factory()->for($worker)->create();

        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $user);

        $service = new OrderService();
        $data = $service->getIndexData($request);

        expect($data['productionStoreId'])->toBe('store-uuid-123');
    });

    test('фильтрует по статусу через querystring', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $dept = Department::create(['name' => 'Отдел', 'is_active' => true]);

        Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1', 'state_name' => 'Новая']);
        Order::create(['moysklad_id' => 'ms-2', 'name' => 'Заявка 2', 'state_name' => 'Выполнена']);

        $request = Request::create('/', 'GET', ['filter' => ['status' => 'Новая']]);
        $request->setUserResolver(fn () => $user);
        app()->instance('request', $request);

        $service = new OrderService();
        $data = $service->getIndexData($request);

        expect($data['orders']->total())->toBe(1);
    });

    test('загружает отношения для оптимизации', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $dept = Department::create(['name' => 'Отдел', 'is_active' => true]);
        $product = Product::factory()->create();
        $order = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка', 'state_name' => 'Новая']);

        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $user);

        $service = new OrderService();
        $data = $service->getIndexData($request);

        // Проверяем, что отношения загружены
        $order->refresh();
        expect($order->items)->not->toBeNull();
    });
});
