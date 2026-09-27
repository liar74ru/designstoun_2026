<?php

use App\Models\Department;
use App\Models\Order;
use App\Models\OrderState;
use App\Models\User;
use App\Support\OrderPriority;
use Carbon\Carbon;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

beforeEach(function () {
    // Справочник статусов пуст — фильтр статусов в списке не мешает
    OrderState::query()->delete();
});

/** Заявки в отделе с заданными ключами, в порядке аргументов. */
function priorityOrders(Department $dept, array $keys): array
{
    $orders = [];

    foreach ($keys as $name => $key) {
        $order = Order::create([
            'moysklad_id'  => 'ms-' . $name,
            'name'         => $name,
            'priority_key' => $key,
        ]);
        $order->departments()->attach($dept->id);
        $orders[$name] = $order;
    }

    return $orders;
}

/** Имена заявок на первой странице списка — в порядке очереди. */
function priorityIndexNames(User $user, array $query = []): array
{
    return collect(test()->actingAs($user)
        ->get(route('orders.index', $query))
        ->viewData('orders')
        ->items())->pluck('name')->all();
}

// ══════════════════════════════════════════════════════════════════════════════
// Авто-ключ
// ══════════════════════════════════════════════════════════════════════════════

describe('OrderPriority::autoKey()', function () {

    test('заявка со сроком выше заявки без срока', function () {
        $withDeadline = OrderPriority::autoKey(Carbon::parse('2030-01-01'), Carbon::parse('2026-09-01'));
        $noDeadline   = OrderPriority::autoKey(null, Carbon::parse('2020-01-01'));

        expect($withDeadline)->toBeLessThan($noDeadline);
    });

    test('ранний срок выше позднего', function () {
        $moment = Carbon::parse('2026-09-01');

        expect(OrderPriority::autoKey(Carbon::parse('2026-10-01'), $moment))
            ->toBeLessThan(OrderPriority::autoKey(Carbon::parse('2026-10-02'), $moment));
    });

    test('при одном дне срока выше более ранняя заявка — ключи не совпадают', function () {
        $day = Carbon::parse('2026-10-01');

        $first  = OrderPriority::autoKey($day->copy()->setTime(18, 0), Carbon::parse('2026-09-01 10:00'));
        $second = OrderPriority::autoKey($day->copy()->setTime(9, 0), Carbon::parse('2026-09-01 10:01'));

        expect($first)->toBeLessThan($second);
    });

    test('без срока — по дате заявки', function () {
        expect(OrderPriority::autoKey(null, Carbon::parse('2026-09-01')))
            ->toBeLessThan(OrderPriority::autoKey(null, Carbon::parse('2026-09-02')));
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Ручное перемещение
// ══════════════════════════════════════════════════════════════════════════════

describe('Перемещение в очереди', function () {

    test('↑ ставит заявку перед соседом и помечает ключ ручным', function () {
        $dept = Department::create(['name' => 'Отдел', 'is_active' => true]);
        priorityOrders($dept, ['A' => 100, 'B' => 200, 'C' => 300]);
        $admin = H::adminUser();

        $this->actingAs($admin)
            ->from(route('orders.index'))
            ->post(route('orders.priority.move', 'ms-C'), ['direction' => 'up'])
            ->assertRedirect(route('orders.index'));

        expect(priorityIndexNames($admin))->toBe(['A', 'C', 'B'])
            ->and(Order::where('name', 'C')->value('priority_manual'))->toBeTrue();
    });

    test('↓ ставит заявку после соседа', function () {
        $dept = Department::create(['name' => 'Отдел', 'is_active' => true]);
        priorityOrders($dept, ['A' => 100, 'B' => 200, 'C' => 300]);
        $admin = H::adminUser();

        $this->actingAs($admin)
            ->post(route('orders.priority.move', 'ms-A'), ['direction' => 'down']);

        expect(priorityIndexNames($admin))->toBe(['B', 'A', 'C']);
    });

    test('крайнюю заявку двигать некуда', function () {
        $dept = Department::create(['name' => 'Отдел', 'is_active' => true]);
        priorityOrders($dept, ['A' => 100, 'B' => 200]);

        $this->actingAs(H::adminUser())
            ->from(route('orders.index'))
            ->post(route('orders.priority.move', 'ms-A'), ['direction' => 'up'])
            ->assertSessionHas('warning');

        expect(Order::where('name', 'A')->value('priority_key'))->toEqual(100.0);
    });

    test('сосед ищется в отфильтрованном списке', function () {
        $dept  = Department::create(['name' => 'Отдел', 'is_active' => true]);
        $other = Department::create(['name' => 'Другой', 'is_active' => true]);
        priorityOrders($dept, ['A' => 100, 'C' => 300]);
        priorityOrders($other, ['B' => 200]);
        $admin = H::adminUser();
        $filter = ['filter' => ['department_id' => [$dept->id]]];

        // В списке отдела C стоит сразу после A — ↑ ставит её перед A, а не перед скрытой B
        $this->actingAs($admin)
            ->post(route('orders.priority.move', ['ms-C'] + $filter), ['direction' => 'up']);

        expect(priorityIndexNames($admin, $filter))->toBe(['C', 'A']);
    });

    test('обычная заявка не поднимается выше срочных', function () {
        $dept = Department::create(['name' => 'Отдел', 'is_active' => true]);
        priorityOrders($dept, ['A' => 100, 'B' => 200]);
        Order::where('name', 'B')->update(['is_urgent' => true]);

        $this->actingAs(H::adminUser())
            ->from(route('orders.index'))
            ->post(route('orders.priority.move', 'ms-A'), ['direction' => 'up'])
            ->assertSessionHas('warning');
    });

    test('несколько ↑ подряд поднимают заявку на самый верх', function () {
        $dept = Department::create(['name' => 'Отдел', 'is_active' => true]);
        priorityOrders($dept, ['A' => 100, 'B' => 200, 'C' => 300, 'D' => 400]);
        $admin = H::adminUser();

        foreach (range(1, 3) as $step) {
            $this->actingAs($admin)
                ->post(route('orders.priority.move', 'ms-D'), ['direction' => 'up']);
        }

        expect(priorityIndexNames($admin))->toBe(['D', 'A', 'B', 'C']);
    });

    test('срочная заявка не опускается в обычные', function () {
        $dept = Department::create(['name' => 'Отдел', 'is_active' => true]);
        priorityOrders($dept, ['A' => 100, 'U' => 900]);
        Order::where('name', 'U')->update(['is_urgent' => true]);

        $this->actingAs(H::adminUser())
            ->from(route('orders.index'))
            ->post(route('orders.priority.move', 'ms-U'), ['direction' => 'down'])
            ->assertSessionHas('warning');

        expect(Order::where('name', 'U')->value('priority_key'))->toEqual(900.0);
    });

    test('неверное направление не проходит валидацию', function () {
        $dept = Department::create(['name' => 'Отдел', 'is_active' => true]);
        priorityOrders($dept, ['A' => 100]);

        $this->actingAs(H::adminUser())
            ->post(route('orders.priority.move', 'ms-A'), ['direction' => 'left'])
            ->assertSessionHasErrors('direction');
    });

    test('мастер чужого отдела получает 403', function () {
        $dept  = Department::create(['name' => 'Отдел', 'is_active' => true]);
        $other = Department::create(['name' => 'Другой', 'is_active' => true]);
        priorityOrders($dept, ['A' => 100, 'B' => 200]);

        $master = Access::master($other, 'orders');

        $this->actingAs($master)
            ->post(route('orders.priority.move', 'ms-B'), ['direction' => 'up'])
            ->assertForbidden();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// «Срочно» и сброс
// ══════════════════════════════════════════════════════════════════════════════

describe('Срочно и сброс ручного места', function () {

    test('срочная заявка закрепляется сверху, отметка снимается', function () {
        $dept = Department::create(['name' => 'Отдел', 'is_active' => true]);
        priorityOrders($dept, ['A' => 100, 'B' => 200]);
        $admin = H::adminUser();

        $this->actingAs($admin)->post(route('orders.priority.urgent', 'ms-B'), ['urgent' => 1]);
        expect(priorityIndexNames($admin))->toBe(['B', 'A']);

        $this->actingAs($admin)->post(route('orders.priority.urgent', 'ms-B'), ['urgent' => 0]);
        expect(priorityIndexNames($admin))->toBe(['A', 'B']);
    });

    test('urgent без значения или не boolean не проходит валидацию', function () {
        $dept = Department::create(['name' => 'Отдел', 'is_active' => true]);
        priorityOrders($dept, ['A' => 100]);
        $admin = H::adminUser();

        $this->actingAs($admin)
            ->post(route('orders.priority.urgent', 'ms-A'))
            ->assertSessionHasErrors('urgent');

        $this->actingAs($admin)
            ->post(route('orders.priority.urgent', 'ms-A'), ['urgent' => 'abc'])
            ->assertSessionHasErrors('urgent');

        expect(Order::where('name', 'A')->value('is_urgent'))->toBeFalse();
    });

    test('мастер чужого отдела не может отметить срочной или сбросить место', function () {
        $dept  = Department::create(['name' => 'Отдел', 'is_active' => true]);
        $other = Department::create(['name' => 'Другой', 'is_active' => true]);
        priorityOrders($dept, ['A' => 100]);
        $master = Access::master($other, 'orders');

        $this->actingAs($master)
            ->post(route('orders.priority.urgent', 'ms-A'), ['urgent' => 1])
            ->assertForbidden();

        $this->actingAs($master)
            ->post(route('orders.priority.reset', 'ms-A'))
            ->assertForbidden();
    });

    test('сброс возвращает авто-ключ по сроку и дате', function () {
        $order = Order::create([
            'moysklad_id'         => 'ms-1',
            'name'                => 'Заявка',
            'moment'              => '2026-09-01 10:00:00',
            'delivery_planned_at' => '2026-10-01 00:00:00',
            'priority_key'        => 5,
            'priority_manual'     => true,
        ]);

        $this->actingAs(H::adminUser())
            ->post(route('orders.priority.reset', 'ms-1'))
            ->assertSessionHas('success');

        $order->refresh();
        expect($order->priority_manual)->toBeFalse()
            ->and($order->priority_key)->toEqual(OrderPriority::autoKey($order->delivery_planned_at, $order->moment));
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Список
// ══════════════════════════════════════════════════════════════════════════════

describe('Очередь в списке заявок', function () {

    test('показывает кнопки очереди, срок отгрузки и срочные', function () {
        $dept = Department::create(['name' => 'Отдел', 'is_active' => true]);
        $orders = priorityOrders($dept, ['A' => 100, 'U' => 900]);
        $orders['A']->update(['delivery_planned_at' => '2026-10-05 00:00:00']);
        $orders['U']->update(['is_urgent' => true]);

        $this->actingAs(H::adminUser())
            ->get(route('orders.index'))
            ->assertSuccessful()
            ->assertSee(route('orders.priority.move', 'ms-A'), false)
            ->assertSee(route('orders.priority.urgent', 'ms-U'), false)
            ->assertSee('отгр. 05.10.2026')
            ->assertSee('order-urgent', false);
    });

    test('кнопка сброса есть только у заявки с ручным местом', function () {
        $dept = Department::create(['name' => 'Отдел', 'is_active' => true]);
        $orders = priorityOrders($dept, ['A' => 100, 'M' => 200]);
        $orders['M']->update(['priority_manual' => true]);

        $this->actingAs(H::adminUser())
            ->get(route('orders.index'))
            ->assertSee(route('orders.priority.reset', 'ms-M'), false)
            ->assertDontSee(route('orders.priority.reset', 'ms-A'), false);
    });
});
