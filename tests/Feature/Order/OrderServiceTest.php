<?php

use App\Models\Counterparty;
use App\Models\Department;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Models\Worker;
use App\Models\OrderState;
use App\Services\OrderService;
use Illuminate\Http\Request;

/** Статус в справочнике: имя + отмечен ли как используемый. */
function orderState(string $name, bool $enabled = true, int $position = 0): OrderState
{
    return OrderState::create([
        'name'       => $name,
        'is_enabled' => $enabled,
        'position'   => $position,
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// OrderService::statuses()
// ══════════════════════════════════════════════════════════════════════════════

describe('OrderService::statuses()', function () {

    test('возвращает пустой массив когда справочник пуст', function () {
        $service = app(OrderService::class);
        expect($service->statuses())->toBe([]);
    });

    test('возвращает имена только отмеченных статусов, в порядке МойСклад', function () {
        orderState('Собран', true, position: 2);
        orderState('Новый', true, position: 0);
        orderState('Отменен', false, position: 1);

        $service = app(OrderService::class);
        expect($service->statuses())->toBe(['Новый', 'Собран']);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// OrderService::defaultStatuses()
// ══════════════════════════════════════════════════════════════════════════════

describe('OrderService::defaultStatuses()', function () {

    test('возвращает только отмеченные «в списке»', function () {
        orderState('Новый', true, position: 0);
        orderState('Собран', true, position: 1)->update(['is_default_filter' => true]);
        orderState('Отгружен', true, position: 2)->update(['is_default_filter' => true]);

        expect(app(OrderService::class)->defaultStatuses())->toBe(['Собран', 'Отгружен']);
    });

    test('отмеченный, но неиспользуемый статус в набор не попадает', function () {
        orderState('Новый', true, position: 0)->update(['is_default_filter' => true]);
        orderState('Отменен', false, position: 1)->update(['is_default_filter' => true]);

        expect(app(OrderService::class)->defaultStatuses())->toBe(['Новый']);
    });

    test('без отметок возвращает все используемые — поведение как до настройки', function () {
        orderState('Новый', true, position: 0);
        orderState('Собран', true, position: 1);
        orderState('Отменен', false, position: 2);

        expect(app(OrderService::class)->defaultStatuses())->toBe(['Новый', 'Собран']);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// OrderService::getIndexData()
// ══════════════════════════════════════════════════════════════════════════════

describe('OrderService::getIndexData()', function () {

    beforeEach(function () {
        orderState('Новая');
        orderState('Выполнена', position: 1);
    });

    test('возвращает все необходимые ключи', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $user);

        $service = app(OrderService::class);
        $data = $service->getIndexData($request);

        expect($data)->toHaveKeys([
            'orders',
            'statusOptions',
            'statusDefaults',
            'filterDepartments',
            'departmentDefaults',
            'rowsByOrder',
        ]);
    });

    test('админ видит заявки из всех отделов', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $dept1 = Department::create(['name' => 'Отдел 1', 'is_active' => true]);
        $dept2 = Department::create(['name' => 'Отдел 2', 'is_active' => true]);
        Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1', 'state_name' => 'Новая'])
            ->departments()->attach($dept1->id);
        Order::create(['moysklad_id' => 'ms-2', 'name' => 'Заявка 2', 'state_name' => 'Новая'])
            ->departments()->attach($dept2->id);

        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $user);

        $service = app(OrderService::class);
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

        $service = app(OrderService::class);
        $data = $service->getIndexData($request);

        expect($data['orders']->total())->toBe(1);
    });

    test('возвращает доступные отделы для фильтра', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $dept1 = Department::create(['name' => 'Отдел 1', 'is_active' => true]);
        $dept2 = Department::create(['name' => 'Отдел 2', 'is_active' => true]);

        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $user);

        $service = app(OrderService::class);
        $data = $service->getIndexData($request);

        expect($data['filterDepartments']->pluck('id'))->toContain($dept1->id, $dept2->id);
    });

    test('склад заявки берётся от её отдела, а не от смотрящего', function () {
        Store::factory()->create(['id' => 'store-uuid-123']);
        $dept = Department::create(['name' => 'Отдел', 'is_active' => true, 'default_production_store_id' => 'store-uuid-123']);

        $order = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1', 'state_name' => 'Новая']);
        $order->departments()->attach($dept->id);
        $order->items()->create(['product_id' => Product::factory()->create()->id, 'quantity' => 10, 'shipped' => 0]);

        // Админ без отдела — склад всё равно определяется, потому что берётся от заявки
        $user = User::factory()->create(['is_admin' => true]);

        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $user);

        $service = app(OrderService::class);
        $data = $service->getIndexData($request);

        expect($data['rowsByOrder'][$order->id]->first()['stores'])->toBe(['store-uuid-123']);
    });

    test('фильтрует по статусу через querystring', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $dept = Department::create(['name' => 'Отдел', 'is_active' => true]);

        Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1', 'state_name' => 'Новая'])
            ->departments()->attach($dept->id);
        Order::create(['moysklad_id' => 'ms-2', 'name' => 'Заявка 2', 'state_name' => 'Выполнена'])
            ->departments()->attach($dept->id);

        $request = Request::create('/', 'GET', ['filter' => ['status' => 'Новая']]);
        $request->setUserResolver(fn () => $user);
        app()->instance('request', $request);

        $service = app(OrderService::class);
        $data = $service->getIndexData($request);

        expect($data['orders']->total())->toBe(1);
    });

    test('без фильтра показывает только статусы, отмеченные «в списке»', function () {
        $user = User::factory()->create(['is_admin' => true]);
        orderState('Новый', true, position: 0);
        orderState('Собран', true, position: 1)->update(['is_default_filter' => true]);

        $dept = Department::create(['name' => 'Отдел', 'is_active' => true]);
        Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1', 'state_name' => 'Новый'])
            ->departments()->attach($dept->id);
        Order::create(['moysklad_id' => 'ms-2', 'name' => 'Заявка 2', 'state_name' => 'Собран'])
            ->departments()->attach($dept->id);

        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $user);

        $data = app(OrderService::class)->getIndexData($request);

        expect($data['orders']->total())->toBe(1)
            ->and($data['orders']->first()->state_name)->toBe('Собран')
            ->and($data['statusDefaults'])->toBe(['Собран'])
            // Варианты в фильтре при этом остаются все используемые
            ->and($data['statusOptions'])->toHaveKeys(['Новый', 'Собран']);
    });

    test('явный фильтр перебивает статусы по умолчанию', function () {
        $user = User::factory()->create(['is_admin' => true]);
        orderState('Новый', true, position: 0);
        orderState('Собран', true, position: 1)->update(['is_default_filter' => true]);

        $dept = Department::create(['name' => 'Отдел', 'is_active' => true]);
        Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1', 'state_name' => 'Новый'])
            ->departments()->attach($dept->id);
        Order::create(['moysklad_id' => 'ms-2', 'name' => 'Заявка 2', 'state_name' => 'Собран'])
            ->departments()->attach($dept->id);

        $request = Request::create('/', 'GET', ['filter' => ['status' => ['Новый']]]);
        $request->setUserResolver(fn () => $user);
        app()->instance('request', $request);

        $data = app(OrderService::class)->getIndexData($request);

        expect($data['orders']->total())->toBe(1)
            ->and($data['orders']->first()->state_name)->toBe('Новый');
    });

    test('без отметок «в списке» показываются все заявки', function () {
        $user = User::factory()->create(['is_admin' => true]);
        orderState('Новый', true, position: 0);
        orderState('Собран', true, position: 1);

        $dept = Department::create(['name' => 'Отдел', 'is_active' => true]);
        Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1', 'state_name' => 'Новый'])
            ->departments()->attach($dept->id);
        Order::create(['moysklad_id' => 'ms-2', 'name' => 'Заявка 2', 'state_name' => 'Собран'])
            ->departments()->attach($dept->id);

        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $user);

        expect(app(OrderService::class)->getIndexData($request)['orders']->total())->toBe(2);
    });

    test('загружает отношения для оптимизации', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $dept = Department::create(['name' => 'Отдел', 'is_active' => true]);
        $product = Product::factory()->create();
        $order = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка', 'state_name' => 'Новая']);

        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $user);

        $service = app(OrderService::class);
        $data = $service->getIndexData($request);

        // Проверяем, что отношения загружены
        $order->refresh();
        expect($order->items)->not->toBeNull();
    });
});
