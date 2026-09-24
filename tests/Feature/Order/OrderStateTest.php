<?php

use App\Models\Department;
use App\Models\DepartmentOperationSetting;
use App\Models\Order;
use App\Models\OrderState;
use App\Models\Setting;
use App\Models\User;
use App\Models\Worker;
use App\Services\Moysklad\OrderStateSyncService;
use Illuminate\Support\Facades\Http;

/** Ответ /entity/customerorder/metadata с заданными статусами. */
function statesMetadata(array $states): array
{
    return ['states' => $states];
}

function stateRow(string $id, string $name, int $color = 15280409): array
{
    return ['id' => $id, 'name' => $name, 'color' => $color, 'stateType' => 'Regular'];
}

/** Мастер с включённой операцией «Заказы» в своём отделе. */
function orderStateMaster(Department $dept): User
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

const ST_A = '11111111-1111-1111-1111-111111111111';
const ST_B = '22222222-2222-2222-2222-222222222222';

beforeEach(function () {
    config()->set('services.moysklad.token', 'test-token');
    Order::forgetStateCache();
});

// ══════════════════════════════════════════════════════════════════════════════
// OrderStateSyncService::sync()
// ══════════════════════════════════════════════════════════════════════════════

describe('OrderStateSyncService::sync()', function () {

    test('создаёт статусы с именем, цветом, типом и порядком из МойСклад', function () {
        Http::fake(['*' => Http::response(statesMetadata([
            stateRow(ST_A, 'Новый', 15106326),
            stateRow(ST_B, 'Собран', 8767198),
        ]), 200)]);

        $result = (new OrderStateSyncService())->sync();

        expect($result['success'])->toBeTrue()
            ->and($result['synced'])->toBe(2);

        $first = OrderState::find(ST_A);
        expect($first->name)->toBe('Новый')
            ->and($first->color)->toBe(15106326)
            ->and($first->state_type)->toBe('Regular')
            ->and($first->position)->toBe(0)
            ->and($first->hex_color)->toBe('#E68116');

        expect(OrderState::find(ST_B)->position)->toBe(1);
    });

    test('повторная синхронизация обновляет по id: переименование без дубля', function () {
        // fakeSequence, а не два Http::fake(): повторный fake со стабом '*' не
        // перебивает первый — выигрывает раньше зарегистрированный.
        Http::fakeSequence()
            ->push(statesMetadata([stateRow(ST_A, 'Новый')]), 200)
            ->push(statesMetadata([stateRow(ST_A, 'Новая заявка')]), 200);

        (new OrderStateSyncService())->sync();
        $result = (new OrderStateSyncService())->sync();

        expect(OrderState::count())->toBe(1)
            ->and(OrderState::find(ST_A)->name)->toBe('Новая заявка')
            ->and($result['updated'])->toBe(1)
            ->and($result['synced'])->toBe(0);
    });

    test('пропавший статус помечается archived, а не удаляется', function () {
        Http::fakeSequence()
            ->push(statesMetadata([stateRow(ST_A, 'Новый'), stateRow(ST_B, 'Собран')]), 200)
            ->push(statesMetadata([stateRow(ST_A, 'Новый')]), 200);

        (new OrderStateSyncService())->sync();
        $result = (new OrderStateSyncService())->sync();

        expect(OrderState::count())->toBe(2)
            ->and(OrderState::find(ST_B)->archived)->toBeTrue()
            ->and(OrderState::find(ST_A)->archived)->toBeFalse()
            ->and($result['archived'])->toBe(1);
    });

    test('вернувшийся статус снимает пометку archived', function () {
        OrderState::create(['id' => ST_A, 'name' => 'Новый', 'archived' => true]);

        Http::fake(['*' => Http::response(statesMetadata([stateRow(ST_A, 'Новый')]), 200)]);
        (new OrderStateSyncService())->sync();

        expect(OrderState::find(ST_A)->archived)->toBeFalse();
    });

    test('первое наполнение включает статусы из старой настройки по именам', function () {
        Setting::set('MOYSKLAD_ORDER_STATUSES', json_encode(['Собран']));

        Http::fake(['*' => Http::response(statesMetadata([
            stateRow(ST_A, 'Новый'),
            stateRow(ST_B, 'Собран'),
        ]), 200)]);

        (new OrderStateSyncService())->sync();

        expect(OrderState::find(ST_B)->is_enabled)->toBeTrue()
            ->and(OrderState::find(ST_A)->is_enabled)->toBeFalse();
    });

    test('повторная синхронизация не трогает расставленные галочки', function () {
        Http::fakeSequence()
            ->push(statesMetadata([stateRow(ST_A, 'Новый')]), 200)
            ->push(statesMetadata([stateRow(ST_A, 'Новый')]), 200);

        (new OrderStateSyncService())->sync();

        OrderState::find(ST_A)->update(['is_enabled' => true]);
        (new OrderStateSyncService())->sync();

        expect(OrderState::find(ST_A)->is_enabled)->toBeTrue();
    });

    test('без токена ничего не делает', function () {
        config()->set('services.moysklad.token', '');

        $result = (new OrderStateSyncService())->sync();

        expect($result['success'])->toBeFalse()
            ->and($result['message'])->toContain('MOYSKLAD_TOKEN')
            ->and(OrderState::count())->toBe(0);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Админская страница статусов
// ══════════════════════════════════════════════════════════════════════════════

describe('Admin\OrderStateController', function () {

    test('страница доступна админу', function () {
        OrderState::create(['id' => ST_A, 'name' => 'Новый']);
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->get(route('admin.order-states.index'))
            ->assertSuccessful()
            ->assertViewIs('admin.order-states.index')
            ->assertSee('Новый');
    });

    test('мастеру недоступна', function () {
        $dept = Department::create(['name' => 'Отдел', 'is_active' => true]);

        $this->actingAs(orderStateMaster($dept))
            ->get(route('admin.order-states.index'))
            ->assertForbidden();
    });

    test('сохранение галочек включает отмеченные и снимает остальные', function () {
        OrderState::create(['id' => ST_A, 'name' => 'Новый', 'is_enabled' => true]);
        OrderState::create(['id' => ST_B, 'name' => 'Собран', 'is_enabled' => false]);
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->post(route('admin.order-states.update'), ['enabled' => [ST_B]])
            ->assertRedirect(route('admin.order-states.index'));

        expect(OrderState::find(ST_A)->is_enabled)->toBeFalse()
            ->and(OrderState::find(ST_B)->is_enabled)->toBeTrue();
    });

    test('галочка «производственный» сохраняется отдельно от «используется»', function () {
        OrderState::create(['id' => ST_A, 'name' => 'Новый', 'is_enabled' => true]);
        OrderState::create(['id' => ST_B, 'name' => 'В процессе', 'is_enabled' => true]);
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)->post(route('admin.order-states.update'), [
            'enabled'    => [ST_A, ST_B],
            'production' => [ST_B],
        ]);

        expect(OrderState::find(ST_B)->is_production)->toBeTrue()
            ->and(OrderState::find(ST_A)->is_production)->toBeFalse()
            ->and(OrderState::find(ST_A)->is_enabled)->toBeTrue();
    });

    test('снятая галочка «производственный» сбрасывается', function () {
        OrderState::create(['id' => ST_A, 'name' => 'В процессе', 'is_enabled' => true, 'is_production' => true]);
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)->post(route('admin.order-states.update'), ['enabled' => [ST_A]]);

        expect(OrderState::find(ST_A)->is_production)->toBeFalse();
    });

    test('синхронизация справочника не сбрасывает «производственный»', function () {
        OrderState::create(['id' => ST_A, 'name' => 'В процессе', 'is_production' => true]);

        Http::fake(['*' => Http::response(statesMetadata([stateRow(ST_A, 'В процессе')]), 200)]);
        (new OrderStateSyncService())->sync();

        expect(OrderState::find(ST_A)->is_production)->toBeTrue();
    });

    test('пустой список снимает все галочки', function () {
        OrderState::create(['id' => ST_A, 'name' => 'Новый', 'is_enabled' => true]);
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)->post(route('admin.order-states.update'), []);

        expect(OrderState::find(ST_A)->is_enabled)->toBeFalse();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// OrderController::updateState()
// ══════════════════════════════════════════════════════════════════════════════

describe('OrderController::updateState()', function () {

    test('успешная смена статуса пишет в МойСклад и обновляет заявку', function () {
        Http::fake(['*' => Http::response(['id' => 'ms-1'], 200)]);

        OrderState::create(['id' => ST_B, 'name' => 'Собран', 'is_enabled' => true]);
        $order = Order::create([
            'moysklad_id'       => 'ms-1',
            'name'              => 'Заявка 1',
            'state_moysklad_id' => ST_A,
            'state_name'        => 'Новый',
        ]);
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->from(route('orders.show', 'ms-1'))
            ->post(route('orders.state.update', 'ms-1'), ['state_id' => ST_B])
            ->assertRedirect(route('orders.show', 'ms-1'))
            ->assertSessionHas('success');

        $order->refresh();
        expect($order->state_name)->toBe('Собран')
            ->and($order->state_moysklad_id)->toBe(ST_B);

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && str_contains($request->url(), '/entity/customerorder/ms-1')
                && str_contains($request->data()['state']['meta']['href'], ST_B);
        });
    });

    test('ошибка МойСклад не меняет статус в программе', function () {
        Http::fake(['*' => Http::response(['errors' => [['error' => 'Нет прав на изменение']]], 412)]);

        OrderState::create(['id' => ST_B, 'name' => 'Собран', 'is_enabled' => true]);
        $order = Order::create([
            'moysklad_id'       => 'ms-1',
            'name'              => 'Заявка 1',
            'state_moysklad_id' => ST_A,
            'state_name'        => 'Новый',
        ]);
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->from(route('orders.show', 'ms-1'))
            ->post(route('orders.state.update', 'ms-1'), ['state_id' => ST_B])
            ->assertSessionHas('error');

        $order->refresh();
        expect($order->state_name)->toBe('Новый')
            ->and($order->state_moysklad_id)->toBe(ST_A);
    });

    test('неиспользуемый статус выставить нельзя и в МойСклад ничего не уходит', function () {
        Http::fake();

        OrderState::create(['id' => ST_B, 'name' => 'Отменен', 'is_enabled' => false]);
        Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1', 'state_name' => 'Новый']);
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->from(route('orders.show', 'ms-1'))
            ->post(route('orders.state.update', 'ms-1'), ['state_id' => ST_B])
            ->assertSessionHasErrors('state_id');

        Http::assertNothingSent();
    });

    test('несуществующий статус отклоняется валидацией', function () {
        Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->from(route('orders.show', 'ms-1'))
            ->post(route('orders.state.update', 'ms-1'), ['state_id' => ST_A])
            ->assertSessionHasErrors('state_id');
    });

    test('мастер чужого отдела получает 403', function () {
        Http::fake();

        $own = Department::create(['name' => 'Отдел 1', 'is_active' => true]);
        $other = Department::create(['name' => 'Отдел 2', 'is_active' => true]);
        $user = orderStateMaster($own);

        OrderState::create(['id' => ST_B, 'name' => 'Собран', 'is_enabled' => true]);
        $order = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);
        $order->departments()->attach($other->id);

        $this->actingAs($user)
            ->post(route('orders.state.update', 'ms-1'), ['state_id' => ST_B])
            ->assertForbidden();

        Http::assertNothingSent();
    });

    test('повторный выбор того же статуса ничего не отправляет', function () {
        Http::fake();

        OrderState::create(['id' => ST_B, 'name' => 'Собран', 'is_enabled' => true]);
        Order::create([
            'moysklad_id'       => 'ms-1',
            'name'              => 'Заявка 1',
            'state_moysklad_id' => ST_B,
            'state_name'        => 'Собран',
        ]);
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->from(route('orders.show', 'ms-1'))
            ->post(route('orders.state.update', 'ms-1'), ['state_id' => ST_B])
            ->assertSessionHas('warning');

        Http::assertNothingSent();
    });
});
