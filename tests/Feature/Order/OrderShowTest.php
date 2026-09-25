<?php

use App\Models\Counterparty;
use App\Models\Department;
use App\Models\DepartmentOperationSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Worker;
use App\Services\OrderService;
use Illuminate\Http\Request;

/**
 * Мастер отдела с включённой операцией «Заявки» — иначе не пройдёт can:see-orders.
 */
function orderShowMaster(Department $dept): User
{
    DepartmentOperationSetting::create([
        'department_id' => $dept->id,
        'operation_key' => 'orders',
        'config'        => ['positions' => ['Мастер']],
        'enabled'       => true,
    ]);

    $worker = Worker::create([
        'name'          => 'Мастер',
        'department_id' => $dept->id,
        'position'      => 'Мастер',
    ]);

    return User::factory()->for($worker)->create(['is_admin' => false]);
}

function orderShowRequest(User $user, string $moyskladId): Request
{
    $request = Request::create('/orders/' . $moyskladId, 'GET');
    $request->setUserResolver(fn () => $user);

    return $request;
}

// ══════════════════════════════════════════════════════════════════════════════
// OrderController::show()
// ══════════════════════════════════════════════════════════════════════════════

describe('OrderController::show()', function () {

    test('страница доступна админу', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $order = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1', 'state_name' => 'Новая']);

        $this->actingAs($user)
            ->get(route('orders.show', $order->moysklad_id))
            ->assertSuccessful()
            ->assertViewIs('orders.show')
            ->assertViewHas('order', fn ($viewOrder) => $viewOrder->is($order))
            ->assertSee('Заявка 1');
    });

    test('недоступна без авторизации', function () {
        $order = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);

        $this->get(route('orders.show', $order->moysklad_id))
            ->assertRedirect('/login');
    });

    test('неизвестный moysklad_id — 404', function () {
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->get(route('orders.show', 'ms-которого-нет'))
            ->assertNotFound();
    });

    test('заявка ищется по moysklad_id, а не по локальному id', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $order = Order::create(['moysklad_id' => 'ms-42', 'name' => 'Заявка 42']);

        $this->actingAs($user)
            ->get(route('orders.show', $order->id))
            ->assertNotFound();
    });

    test('мастер видит заявку своего отдела', function () {
        $dept = Department::create(['name' => 'Отдел 1', 'is_active' => true]);
        $user = orderShowMaster($dept);

        $order = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);
        $order->departments()->attach($dept->id);

        $this->actingAs($user)
            ->get(route('orders.show', $order->moysklad_id))
            ->assertSuccessful();
    });

    test('мастер не видит заявку чужого отдела', function () {
        $own = Department::create(['name' => 'Отдел 1', 'is_active' => true]);
        $other = Department::create(['name' => 'Отдел 2', 'is_active' => true]);
        $user = orderShowMaster($own);

        $order = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);
        $order->departments()->attach($other->id);

        $this->actingAs($user)
            ->get(route('orders.show', $order->moysklad_id))
            ->assertForbidden();
    });

    test('заявка без отделов доступна неадмину — ей назначают отдел', function () {
        $dept = Department::create(['name' => 'Отдел 1', 'is_active' => true]);
        $user = orderShowMaster($dept);

        $order = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);

        $this->actingAs($user)
            ->get(route('orders.show', $order->moysklad_id))
            ->assertSuccessful();
    });

    test('показывает позиции, контрагента и дефицит по производственному складу', function () {
        $store = Store::factory()->create();
        $dept = Department::create([
            'name'                         => 'Отдел 1',
            'is_active'                    => true,
            'default_production_store_id'  => $store->id,
        ]);
        $user = orderShowMaster($dept);

        $product = Product::factory()->create(['name' => 'Плитняк Кварцит 20-40']);
        ProductStock::create(['product_id' => $product->id, 'store_id' => $store->id, 'quantity' => 30]);

        $counterparty = Counterparty::create(['moysklad_id' => 'cp-1', 'name' => 'ООО Покупатель']);

        $order = Order::create([
            'moysklad_id'     => 'ms-1',
            'name'            => 'Заявка 1',
            'state_name'      => 'В процессе',
            'counterparty_id' => $counterparty->id,
        ]);
        $order->departments()->attach($dept->id);
        $order->items()->create([
            'product_id' => $product->id,
            'quantity'   => 100,
            'shipped'    => 0,
            'uom_name'   => 'м2',
        ]);

        $this->actingAs($user)
            ->get(route('orders.show', $order->moysklad_id))
            ->assertSuccessful()
            ->assertSee('Плитняк Кварцит 20-40')
            ->assertSee('ООО Покупатель')
            ->assertSee('В процессе')
            // на складе 30 из нужных 100 — не хватает 70
            ->assertSee('не хватает 70.0');
    });

    test('полностью отгруженная позиция не считается дефицитом', function () {
        $store = Store::factory()->create();
        $dept = Department::create([
            'name'                        => 'Отдел 1',
            'is_active'                   => true,
            'default_production_store_id' => $store->id,
        ]);
        $user = orderShowMaster($dept);

        $product = Product::factory()->create();
        ProductStock::create(['product_id' => $product->id, 'store_id' => $store->id, 'quantity' => 0]);

        $order = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);
        $order->departments()->attach($dept->id);
        $order->items()->create(['product_id' => $product->id, 'quantity' => 50, 'shipped' => 50]);

        $this->actingAs($user)
            ->get(route('orders.show', $order->moysklad_id))
            ->assertSuccessful()
            ->assertSee('отгружено')
            ->assertDontSee('не хватает');
    });

    test('заявка без позиций открывается с пустым состоянием', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $order = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);

        $this->actingAs($user)
            ->get(route('orders.show', $order->moysklad_id))
            ->assertSuccessful()
            ->assertSee('Нет позиций');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// OrderService::getShowData()
// ══════════════════════════════════════════════════════════════════════════════

describe('OrderService::getShowData()', function () {

    test('возвращает все необходимые ключи', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $order = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);

        $data = app(OrderService::class)->getShowData(orderShowRequest($user, 'ms-1'), 'ms-1');

        expect($data)->toHaveKeys(['order', 'attributes', 'rows', 'stores', 'defaultStoreId', 'backUrl'])
            ->and($data['order']->is($order))->toBeTrue()
            ->and($data['backUrl'])->toBe(route('orders.index'));
    });

    test('склад по умолчанию берётся из отдела заявки', function () {
        $store = Store::factory()->create();
        $dept = Department::create([
            'name'                        => 'Отдел 1',
            'is_active'                   => true,
            'default_production_store_id' => $store->id,
        ]);
        $user = orderShowMaster($dept);

        $order = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);
        $order->departments()->attach($dept->id);

        $data = app(OrderService::class)->getShowData(orderShowRequest($user, 'ms-1'), 'ms-1');

        expect($data['defaultStoreId'])->toBe($store->id);
    });

    test('булевы реквизиты отброшены — они уже разобраны в отделы', function () {
        $user = User::factory()->create(['is_admin' => true]);
        Order::create([
            'moysklad_id' => 'ms-1',
            'name'        => 'Заявка 1',
            'attributes'  => [
                ['name' => 'Цех', 'type' => 'boolean', 'value' => true],
                ['name' => 'Карьер', 'type' => 'boolean', 'value' => false],
                ['name' => 'Менеджер', 'type' => 'string', 'value' => 'Смирнов А. В.'],
            ],
        ]);

        $data = app(OrderService::class)->getShowData(orderShowRequest($user, 'ms-1'), 'ms-1');

        expect($data['attributes'])->toBe(['Менеджер' => 'Смирнов А. В.']);
    });

    test('пустые и безымянные реквизиты отброшены', function () {
        $user = User::factory()->create(['is_admin' => true]);
        Order::create([
            'moysklad_id' => 'ms-1',
            'name'        => 'Заявка 1',
            'attributes'  => [
                ['name' => 'Комментарий', 'type' => 'text', 'value' => ''],
                ['name' => 'Адрес', 'type' => 'string', 'value' => null],
                ['type' => 'string', 'value' => 'без имени'],
                ['name' => 'Способ доставки', 'type' => 'string', 'value' => 'Самовывоз'],
            ],
        ]);

        $data = app(OrderService::class)->getShowData(orderShowRequest($user, 'ms-1'), 'ms-1');

        expect($data['attributes'])->toBe(['Способ доставки' => 'Самовывоз']);
    });

    test('значение-справочник берётся по name, дата форматируется', function () {
        $user = User::factory()->create(['is_admin' => true]);
        Order::create([
            'moysklad_id' => 'ms-1',
            'name'        => 'Заявка 1',
            'attributes'  => [
                ['name' => 'Тип доставки', 'type' => 'customentity', 'value' => ['name' => 'Транспортная компания']],
                ['name' => 'Плановая отгрузка', 'type' => 'time', 'value' => '2026-09-25 10:30:00.000'],
            ],
        ]);

        $data = app(OrderService::class)->getShowData(orderShowRequest($user, 'ms-1'), 'ms-1');

        expect($data['attributes'])->toBe([
            'Тип доставки'      => 'Транспортная компания',
            'Плановая отгрузка' => '25.09.2026',
        ]);
    });

    test('заявка без реквизитов даёт пустой массив', function () {
        $user = User::factory()->create(['is_admin' => true]);
        Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);

        $data = app(OrderService::class)->getShowData(orderShowRequest($user, 'ms-1'), 'ms-1');

        expect($data['attributes'])->toBe([]);
    });
});
