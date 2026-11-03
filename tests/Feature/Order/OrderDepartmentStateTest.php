<?php

use App\Models\Department;
use App\Models\Order;
use App\Models\OrderState;
use App\Models\Product;
use App\Models\User;
use App\Services\Moysklad\CustomerOrderSyncService;
use App\Services\Moysklad\MoySkladService;
use App\Services\Moysklad\OrderStateSyncService;
use App\Services\OrderChangeService;
use App\Services\OrderDepartmentStateService;
use App\Services\OrderProductionService;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

const DS_WORK  = 'a1111111-1111-1111-1111-111111111111';
const DS_DONE  = 'a2222222-2222-2222-2222-222222222222';
const DS_SHIP  = 'a3333333-3333-3333-3333-333333333333';

/**
 * Заявка двух отделов в производственном статусе и мастер каждого отдела.
 *
 * @return array{0: Order, 1: Department, 2: Department, 3: User, 4: User}
 */
function dsTwoDepartmentOrder(): array
{
    OrderState::create(['id' => DS_WORK, 'name' => 'В процессе', 'is_enabled' => true, 'is_production' => true]);
    OrderState::create(['id' => DS_DONE, 'name' => 'Собран', 'is_enabled' => true, 'is_final' => true, 'is_list_bottom' => true]);
    OrderState::create(['id' => DS_SHIP, 'name' => 'Отгружен', 'is_enabled' => true, 'is_final' => true]);

    $dept1 = Access::department('Отдел 1');
    $dept2 = Access::department('Отдел 2');

    $order = Order::create([
        'moysklad_id'           => 'ms-1',
        'name'                  => 'Заявка 1',
        'state_moysklad_id'     => DS_WORK,
        'state_name'            => 'В процессе',
        'production_started_at' => now()->subDay(),
    ]);
    $order->departments()->attach([$dept1->id, $dept2->id]);

    return [$order, $dept1, $dept2, Access::master($dept1, 'orders'), Access::master($dept2, 'orders')];
}

/** @return array<int, ?string>  [department_id => final_state_id] */
function dsMarks(Order $order): array
{
    return $order->departments()->get()
        ->mapWithKeys(fn ($d) => [$d->id => $d->pivot->final_state_id])
        ->all();
}

function dsSetState(User $user, string $stateId, ?array $departments = null)
{
    return test()->actingAs($user)
        ->from(route('orders.show', 'ms-1'))
        ->post(route('orders.state.update', 'ms-1'), array_filter([
            'state_id'    => $stateId,
            'departments' => $departments,
        ], fn ($v) => $v !== null));
}

beforeEach(function () {
    config()->set('services.moysklad.token', 'test-token');
    Order::forgetStateCache();
});

describe('Финальный статус по отделам', function () {

    test('мастер одного из двух отделов ставит финальный статус только своему отделу', function () {
        Http::fake();
        [$order, $dept1, $dept2, $master1] = dsTwoDepartmentOrder();

        dsSetState($master1, DS_DONE)
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Ждём: Отдел 2'));

        Http::assertNothingSent();

        $order->refresh();
        expect($order->state_moysklad_id)->toBe(DS_WORK)
            ->and($order->production_ended_at)->toBeNull()
            ->and(dsMarks($order))->toBe([$dept1->id => DS_DONE, $dept2->id => null]);
    });

    test('последний отдел закрывает заявку статусом, который выбрал он', function () {
        Http::fake(['*' => Http::response(['id' => 'ms-1'], 200)]);
        [$order, $dept1, $dept2, $master1, $master2] = dsTwoDepartmentOrder();

        dsSetState($master1, DS_DONE);
        dsSetState($master2, DS_SHIP)->assertSessionHas('success');

        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && str_contains($r->data()['state']['meta']['href'], DS_SHIP));

        $order->refresh();
        expect($order->state_moysklad_id)->toBe(DS_SHIP)
            ->and($order->production_ended_at)->not->toBeNull()
            ->and(dsMarks($order))->toBe([$dept1->id => null, $dept2->id => null]);
    });

    test('ошибка МойСклад при закрытии: отметка последнего отдела не сохраняется', function () {
        Http::fake(['*' => Http::response(['errors' => [['error' => 'Нет прав']]], 412)]);
        [$order, $dept1, $dept2, $master1, $master2] = dsTwoDepartmentOrder();

        dsSetState($master1, DS_DONE);
        dsSetState($master2, DS_DONE)->assertSessionHas('error');

        $order->refresh();
        expect($order->state_moysklad_id)->toBe(DS_WORK)
            ->and(dsMarks($order))->toBe([$dept1->id => DS_DONE, $dept2->id => null]);
    });

    test('администратор ставит финальный статус всей заявке сразу', function () {
        Http::fake(['*' => Http::response(['id' => 'ms-1'], 200)]);
        [$order] = dsTwoDepartmentOrder();

        dsSetState(H::adminUser(), DS_DONE)->assertSessionHas('success');

        expect($order->refresh()->state_moysklad_id)->toBe(DS_DONE);
    });

    test('мастер, у которого все отделы заявки, ставит статус всей заявке', function () {
        Http::fake(['*' => Http::response(['id' => 'ms-1'], 200)]);
        [$order, $dept1, $dept2, $master1] = dsTwoDepartmentOrder();
        $master1->worker->departments()->syncWithoutDetaching([$dept2->id]);

        dsSetState($master1->fresh(), DS_DONE)->assertSessionHas('success');

        expect($order->refresh()->state_moysklad_id)->toBe(DS_DONE);
    });

    test('заявка с одним отделом закрывается как раньше', function () {
        Http::fake(['*' => Http::response(['id' => 'ms-1'], 200)]);
        [$order, $dept1, $dept2, $master1] = dsTwoDepartmentOrder();
        $order->departments()->detach($dept2->id);

        dsSetState($master1, DS_DONE)->assertSessionHas('success');

        expect($order->refresh()->state_moysklad_id)->toBe(DS_DONE);
    });

    test('повторный выбор своего финального статуса — предупреждение', function () {
        Http::fake();
        [$order, $dept1, $dept2, $master1] = dsTwoDepartmentOrder();

        dsSetState($master1, DS_DONE);
        dsSetState($master1, DS_DONE)->assertSessionHas('warning');
    });

    test('нефинальный статус снимает отметку отдела, общий статус не трогает', function () {
        Http::fake();
        [$order, $dept1, $dept2, $master1] = dsTwoDepartmentOrder();

        dsSetState($master1, DS_DONE);
        dsSetState($master1, DS_WORK)->assertSessionHas('success');

        Http::assertNothingSent();
        expect(dsMarks($order))->toBe([$dept1->id => null, $dept2->id => null])
            ->and($order->refresh()->state_moysklad_id)->toBe(DS_WORK);
    });

    test('снятие единственного работающего отдела закрывает заявку', function () {
        Http::fake(['*' => Http::response(['id' => 'ms-1'], 200)]);
        [$order, $dept1, $dept2, $master1] = dsTwoDepartmentOrder();
        dsSetState($master1, DS_DONE);

        $service = app(OrderDepartmentStateService::class);
        $order->departments()->detach($dept2->id);

        expect($service->finalizeIfComplete($order)['success'])->toBeTrue()
            ->and($order->refresh()->state_moysklad_id)->toBe(DS_DONE);
    });

    test('закрытие прямо в МойСклад сбрасывает отметки отделов', function () {
        [$order, $dept1, $dept2, $master1] = dsTwoDepartmentOrder();
        // Без Http::fake(): отметка отдела в МойСклад не ходит, а ранний fake со стабом '*'
        // перебил бы ответы синхронизации ниже.
        dsSetState($master1, DS_DONE);
        expect(dsMarks($order)[$dept1->id])->toBe(DS_DONE);

        $product = Product::factory()->create();
        $boolean = fn ($name) => ['name' => $name, 'type' => 'boolean', 'value' => true];

        Http::fake([
            '*/entity/customerorder/metadata' => Http::response(['states' => [
                ['id' => DS_WORK, 'name' => 'В процессе', 'color' => 0, 'stateType' => 'Regular'],
                ['id' => DS_DONE, 'name' => 'Собран', 'color' => 0, 'stateType' => 'Regular'],
                ['id' => DS_SHIP, 'name' => 'Отгружен', 'color' => 0, 'stateType' => 'Regular'],
            ]], 200),
            '*/entity/customerorder?*' => Http::response([
                'rows' => [[
                    'id'    => 'ms-1',
                    'name'  => 'Заявка 1',
                    'state' => [
                        'meta' => ['href' => 'https://api.moysklad.ru/entity/customerorder/metadata/states/' . DS_SHIP],
                        'name' => 'Отгружен',
                    ],
                    'positions'  => ['rows' => [[
                        'quantity'   => 1,
                        'shipped'    => 0,
                        'assortment' => [
                            'meta' => ['href' => 'https://api.moysklad.ru/entity/product/' . $product->moysklad_id],
                            'name' => $product->name,
                        ],
                    ]]],
                    'attributes' => [$boolean('Отдел 1'), $boolean('Отдел 2')],
                ]],
                'meta' => ['size' => 1],
            ], 200),
            '*' => Http::response(['rows' => [], 'meta' => ['size' => 0]], 200),
        ]);

        (new CustomerOrderSyncService(
            new MoySkladService(),
            new OrderStateSyncService(),
            app(OrderProductionService::class),
            new OrderChangeService(),
        ))->pullActive();

        $order->refresh();
        expect($order->state_moysklad_id)->toBe(DS_SHIP)
            ->and(dsMarks($order))->toBe([$dept1->id => null, $dept2->id => null]);
    });
});

describe('Вид для отделов', function () {

    test('закончивший отдел видит свой статус и «Ждём», другой — отметку', function () {
        Http::fake();
        [$order, $dept1, $dept2, $master1, $master2] = dsTwoDepartmentOrder();
        dsSetState($master1, DS_DONE);

        $service = app(OrderDepartmentStateService::class);
        $order->load('departments');

        $mine = $service->viewFor($order, $master1);
        expect($mine['state']->id)->toBe(DS_DONE)
            ->and($mine['waiting'])->toBe(['Отдел 2']);

        $other = $service->viewFor($order, $master2);
        expect($other['state'])->toBeNull()
            ->and($other['finished'][0]['name'])->toBe('Отдел 1');

        $this->actingAs($master1)->get(route('orders.show', 'ms-1'))
            ->assertSuccessful()
            ->assertSee('Ждём: Отдел 2');

        $this->actingAs($master2)->get(route('orders.show', 'ms-1'))
            ->assertSuccessful()
            ->assertSee('Отдел 1 · Собран');
    });

    test('в списке закончившего отдела заявка уходит вниз, у другого — нет', function () {
        Http::fake();
        [$order, $dept1, $dept2, $master1, $master2] = dsTwoDepartmentOrder();
        $second = Order::create([
            'moysklad_id'       => 'ms-2',
            'name'              => 'Заявка 2',
            'state_moysklad_id' => DS_WORK,
            'state_name'        => 'В процессе',
        ]);
        $second->departments()->attach([$dept1->id, $dept2->id]);
        // Заявка 1 в очереди выше
        $order->update(['priority_key' => 1]);
        $second->update(['priority_key' => 2]);

        dsSetState($master1, DS_DONE);

        $names = fn (User $u) => Order::query()
            ->listOrdered($u->accessibleDepartmentIds())
            ->pluck('name')->all();

        expect($names($master1))->toBe(['Заявка 2', 'Заявка 1'])
            ->and($names($master2))->toBe(['Заявка 1', 'Заявка 2']);
    });
});

describe('Мастер нескольких отделов', function () {

    test('выбрал один из своих отделов — отмечен только он, заявка ждёт второй', function () {
        Http::fake();
        [$order, $dept1, $dept2, $master1] = dsTwoDepartmentOrder();
        $master1->worker->departments()->syncWithoutDetaching([$dept2->id]);

        dsSetState($master1->fresh(), DS_DONE, [0, $dept1->id])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Ждём: Отдел 2'));

        Http::assertNothingSent();
        expect(dsMarks($order))->toBe([$dept1->id => DS_DONE, $dept2->id => null])
            ->and($order->refresh()->state_moysklad_id)->toBe(DS_WORK);
    });

    test('выбрал все отделы заявки — заявка закрывается целиком', function () {
        Http::fake(['*' => Http::response(['id' => 'ms-1'], 200)]);
        [$order, $dept1, $dept2, $master1] = dsTwoDepartmentOrder();
        $master1->worker->departments()->syncWithoutDetaching([$dept2->id]);

        dsSetState($master1->fresh(), DS_DONE, [0, $dept1->id, $dept2->id])->assertSessionHas('success');

        expect($order->refresh()->state_moysklad_id)->toBe(DS_DONE);
    });

    test('на заявке трёх отделов отмечается только выбранный', function () {
        Http::fake();
        [$order, $dept1, $dept2, $master1] = dsTwoDepartmentOrder();
        $dept3 = Access::department('Отдел 3');
        $order->departments()->attach($dept3->id);
        $master1->worker->departments()->syncWithoutDetaching([$dept2->id]);

        dsSetState($master1->fresh(), DS_DONE, [0, $dept2->id]);

        expect(dsMarks($order))->toBe([$dept1->id => null, $dept2->id => DS_DONE, $dept3->id => null]);
    });

    test('нефинальный статус снимает отметку только выбранного отдела', function () {
        Http::fake();
        [$order, $dept1, $dept2, $master1] = dsTwoDepartmentOrder();
        $dept3 = Access::department('Отдел 3');
        $order->departments()->attach($dept3->id);
        $master1->worker->departments()->syncWithoutDetaching([$dept2->id]);
        $master = $master1->fresh();

        dsSetState($master, DS_DONE);
        dsSetState($master, DS_WORK, [0, $dept1->id])->assertSessionHas('success');

        expect(dsMarks($order))->toBe([$dept1->id => null, $dept2->id => DS_DONE, $dept3->id => null]);
    });

    test('ни одного своего отдела не выбрано — ошибка, ничего не меняется', function () {
        Http::fake();
        [$order, $dept1, $dept2, $master1] = dsTwoDepartmentOrder();

        dsSetState($master1, DS_DONE, [0, $dept2->id])->assertSessionHas('error');

        Http::assertNothingSent();
        expect(dsMarks($order))->toBe([$dept1->id => null, $dept2->id => null]);
    });

    test('в меню статуса мастер двух отделов видит галочки отделов', function () {
        [$order, $dept1, $dept2, $master1, $master2] = dsTwoDepartmentOrder();
        $master1->worker->departments()->syncWithoutDetaching([$dept2->id]);

        $view = app(OrderDepartmentStateService::class)->viewFor($order->load('departments'), $master1->fresh());
        expect($view['choices'])->toBe([$dept1->id => 'Отдел 1', $dept2->id => 'Отдел 2'])
            ->and($view['state'])->toBeNull();

        $this->actingAs($master1->fresh())->get(route('orders.show', 'ms-1'))
            ->assertSuccessful()
            ->assertSee('За отделы:');

        $this->actingAs($master2)->get(route('orders.show', 'ms-1'))
            ->assertSuccessful()
            ->assertDontSee('За отделы:');
    });
});

describe('Справочник статусов', function () {

    test('галочка «Финал.» сохраняется', function () {
        OrderState::create(['id' => DS_WORK, 'name' => 'В процессе', 'is_enabled' => true]);
        OrderState::create(['id' => DS_DONE, 'name' => 'Собран', 'is_enabled' => true]);

        $this->actingAs(H::adminUser())->post(route('admin.order-states.update'), [
            'enabled' => [DS_WORK, DS_DONE],
            'final'   => [DS_DONE],
        ]);

        expect(OrderState::find(DS_DONE)->is_final)->toBeTrue()
            ->and(OrderState::find(DS_WORK)->is_final)->toBeFalse();
    });
});
