<?php

use App\Models\Department;
use App\Models\Order;
use App\Models\OrderState;
use App\Services\OrderService;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

/** Строка метаданных доп. реквизита заказа покупателя. */
function orderDeptAttribute(string $id, string $name, string $type = 'boolean'): array
{
    return [
        'id'   => $id,
        'name' => $name,
        'type' => $type,
        'meta' => [
            'href'      => 'https://api.moysklad.ru/api/remap/1.2/entity/customerorder/metadata/attributes/' . $id,
            'type'      => 'attributemetadata',
            'mediaType' => 'application/json',
        ],
    ];
}

/** МойСклад: метаданные реквизитов на GET, ответ $putStatus на PUT заявки. */
function fakeOrderDeptMoysklad(array $attributes, int $putStatus = 200, array $putBody = ['id' => 'ms-1']): void
{
    Http::fake([
        '*/entity/customerorder/metadata/attributes*' => Http::response(['rows' => $attributes], 200),
        '*/entity/customerorder/*'                    => Http::response($putBody, $putStatus),
    ]);
}

/** Имена заявок на странице списка. */
function orderDeptNames($response): array
{
    return collect($response->viewData('orders')->items())->pluck('name')->sort()->values()->all();
}

beforeEach(function () {
    config()->set('services.moysklad.token', 'test-token');
    // Справочник статусов пуст — фильтр статусов в списке не мешает
    OrderState::query()->delete();
});

// ══════════════════════════════════════════════════════════════════════════════
// Список: заявки без отдела
// ══════════════════════════════════════════════════════════════════════════════

describe('Список заявок: «Без отдела»', function () {

    test('без фильтра заявки без отдела скрыты', function () {
        $dept = Department::create(['name' => 'Отдел 1', 'is_active' => true]);
        Order::create(['moysklad_id' => 'ms-1', 'name' => 'С отделом'])->departments()->attach($dept->id);
        Order::create(['moysklad_id' => 'ms-2', 'name' => 'Без отдела']);

        $response = $this->actingAs(H::adminUser())
            ->get(route('orders.index'))
            ->assertSuccessful();

        expect(orderDeptNames($response))->toBe(['С отделом']);
    });

    test('«Без отдела» показывает только заявки без отдела', function () {
        $dept = Department::create(['name' => 'Отдел 1', 'is_active' => true]);
        Order::create(['moysklad_id' => 'ms-1', 'name' => 'С отделом'])->departments()->attach($dept->id);
        Order::create(['moysklad_id' => 'ms-2', 'name' => 'Без отдела']);

        $response = $this->actingAs(H::adminUser())
            ->get(route('orders.index', ['filter' => ['department_id' => [OrderService::NO_DEPARTMENT]]]))
            ->assertSuccessful();

        expect(orderDeptNames($response))->toBe(['Без отдела']);
    });

    test('отдел и «Без отдела» вместе дают обе выборки', function () {
        $dept1 = Department::create(['name' => 'Отдел 1', 'is_active' => true]);
        $dept2 = Department::create(['name' => 'Отдел 2', 'is_active' => true]);
        Order::create(['moysklad_id' => 'ms-1', 'name' => 'Отдел 1'])->departments()->attach($dept1->id);
        Order::create(['moysklad_id' => 'ms-2', 'name' => 'Отдел 2'])->departments()->attach($dept2->id);
        Order::create(['moysklad_id' => 'ms-3', 'name' => 'Без отдела']);

        $response = $this->actingAs(H::adminUser())
            ->get(route('orders.index', ['filter' => ['department_id' => [$dept1->id, OrderService::NO_DEPARTMENT]]]))
            ->assertSuccessful();

        expect(orderDeptNames($response))->toBe(['Без отдела', 'Отдел 1']);
    });

    test('мастер видит заявки без отдела, но не чужого отдела', function () {
        $own   = Department::create(['name' => 'Свой', 'is_active' => true]);
        $other = Department::create(['name' => 'Чужой', 'is_active' => true]);
        Order::create(['moysklad_id' => 'ms-1', 'name' => 'Свой'])->departments()->attach($own->id);
        Order::create(['moysklad_id' => 'ms-2', 'name' => 'Чужой'])->departments()->attach($other->id);
        Order::create(['moysklad_id' => 'ms-3', 'name' => 'Без отдела']);

        $response = $this->actingAs(Access::master($own, 'orders'))
            ->get(route('orders.index', ['filter' => ['department_id' => [$own->id, $other->id, OrderService::NO_DEPARTMENT]]]))
            ->assertSuccessful();

        expect(orderDeptNames($response))->toBe(['Без отдела', 'Свой']);
    });

    test('пункт «Без отдела» выводится в фильтре', function () {
        Department::create(['name' => 'Отдел 1', 'is_active' => true]);

        $this->actingAs(H::adminUser())
            ->get(route('orders.index'))
            ->assertSuccessful()
            ->assertSee('value="' . OrderService::NO_DEPARTMENT . '"', false)
            ->assertSee('Без отдела');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// OrderController::updateDepartments()
// ══════════════════════════════════════════════════════════════════════════════

describe('OrderController::updateDepartments()', function () {

    test('пишет флажки отделов в МойСклад и обновляет отделы заявки', function () {
        $dept1 = Department::create(['name' => 'Резка', 'is_active' => true]);
        $dept2 = Department::create(['name' => 'Цех', 'is_active' => true]);
        $order = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);
        $order->departments()->attach($dept2->id);

        fakeOrderDeptMoysklad([
            orderDeptAttribute('attr-1', 'Резка'),
            orderDeptAttribute('attr-2', 'Цех'),
            orderDeptAttribute('attr-3', 'Комментарий', 'string'),
        ]);

        $this->actingAs(H::adminUser())
            ->from(route('orders.index'))
            ->post(route('orders.departments.update', 'ms-1'), ['departments' => [$dept1->id]])
            ->assertRedirect(route('orders.index'))
            ->assertSessionHas('success');

        expect($order->departments()->pluck('departments.id')->all())->toBe([$dept1->id]);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PUT' || ! str_ends_with($request->url(), '/entity/customerorder/ms-1')) {
                return false;
            }

            $values = collect($request->data()['attributes'])
                ->mapWithKeys(fn ($a) => [basename($a['meta']['href']) => $a['value']])
                ->all();

            // Снятый отдел сбрасывается в false, небулев реквизит не трогаем
            return $values === ['attr-1' => true, 'attr-2' => false];
        });
    });

    test('пустой выбор снимает все отделы', function () {
        $dept  = Department::create(['name' => 'Резка', 'is_active' => true]);
        $order = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);
        $order->departments()->attach($dept->id);

        fakeOrderDeptMoysklad([orderDeptAttribute('attr-1', 'Резка')]);

        $this->actingAs(H::adminUser())
            ->from(route('orders.index'))
            ->post(route('orders.departments.update', 'ms-1'))
            ->assertSessionHas('success');

        expect($order->departments()->count())->toBe(0);

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request->data()['attributes'][0]['value'] === false);
    });

    test('отдел без реквизита в МойСклад — ошибка, ничего не пишется', function () {
        $dept  = Department::create(['name' => 'Резка', 'is_active' => true]);
        $order = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);

        fakeOrderDeptMoysklad([orderDeptAttribute('attr-9', 'Другой')]);

        $this->actingAs(H::adminUser())
            ->from(route('orders.index'))
            ->post(route('orders.departments.update', 'ms-1'), ['departments' => [$dept->id]])
            ->assertSessionHas('error', fn ($message) => str_contains($message, '«Резка»'));

        expect($order->departments()->count())->toBe(0);
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    });

    test('ошибка МойСклад не меняет отделы в программе', function () {
        $dept1 = Department::create(['name' => 'Резка', 'is_active' => true]);
        $dept2 = Department::create(['name' => 'Цех', 'is_active' => true]);
        $order = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);
        $order->departments()->attach($dept1->id);

        fakeOrderDeptMoysklad(
            [orderDeptAttribute('attr-1', 'Резка'), orderDeptAttribute('attr-2', 'Цех')],
            412,
            ['errors' => [['error' => 'Нет прав на изменение']]],
        );

        $this->actingAs(H::adminUser())
            ->from(route('orders.index'))
            ->post(route('orders.departments.update', 'ms-1'), ['departments' => [$dept2->id]])
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'Нет прав на изменение'));

        expect($order->departments()->pluck('departments.id')->all())->toBe([$dept1->id]);
    });

    test('без токена МойСклад — ошибка, запросов нет', function () {
        config()->set('services.moysklad.token', '');
        Http::fake();

        $dept = Department::create(['name' => 'Резка', 'is_active' => true]);
        Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);

        $this->actingAs(H::adminUser())
            ->from(route('orders.index'))
            ->post(route('orders.departments.update', 'ms-1'), ['departments' => [$dept->id]])
            ->assertSessionHas('error');

        Http::assertNothingSent();
    });

    test('несуществующий отдел не проходит валидацию', function () {
        Http::fake();
        Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);

        $this->actingAs(H::adminUser())
            ->from(route('orders.index'))
            ->post(route('orders.departments.update', 'ms-1'), ['departments' => [999999]])
            ->assertSessionHasErrors('departments.0');

        Http::assertNothingSent();
    });

    test('мастер назначает отдел заявке без отдела', function () {
        $own   = Department::create(['name' => 'Резка', 'is_active' => true]);
        $order = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);

        fakeOrderDeptMoysklad([orderDeptAttribute('attr-1', 'Резка')]);

        $this->actingAs(Access::master($own, 'orders'))
            ->from(route('orders.index'))
            ->post(route('orders.departments.update', 'ms-1'), ['departments' => [$own->id]])
            ->assertSessionHas('success');

        expect($order->departments()->pluck('departments.id')->all())->toBe([$own->id]);
    });

    test('мастер не может менять отделы заявки чужого отдела', function () {
        $own   = Department::create(['name' => 'Резка', 'is_active' => true]);
        $other = Department::create(['name' => 'Цех', 'is_active' => true]);
        $order = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);
        $order->departments()->attach($other->id);

        Http::fake();

        $this->actingAs(Access::master($own, 'orders'))
            ->post(route('orders.departments.update', 'ms-1'), ['departments' => [$own->id]])
            ->assertForbidden();

        expect($order->departments()->pluck('departments.id')->all())->toBe([$other->id]);
        Http::assertNothingSent();
    });
});
