<?php

use App\Models\Department;
use App\Models\Order;
use App\Support\OrderPriority;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

beforeEach(function () {
    config()->set('services.moysklad.token', 'test-token');
});

// ══════════════════════════════════════════════════════════════════════════════
// OrderController::updateDeliveryDate() — дата готовности с записью в МойСклад
// ══════════════════════════════════════════════════════════════════════════════

describe('OrderController::updateDeliveryDate()', function () {

    test('пишет дату в МойСклад, обновляет заявку и пересчитывает место в очереди', function () {
        Http::fake(['*' => Http::response(['id' => 'ms-1'], 200)]);

        $order = Order::create([
            'moysklad_id'  => 'ms-1',
            'name'         => 'Заявка 1',
            'moment'       => '2026-09-18 10:00:00',
            'priority_key' => 1.0,
        ]);

        $this->actingAs(H::adminUser())
            ->from(route('orders.show', 'ms-1'))
            ->post(route('orders.delivery-date.update', 'ms-1'), ['delivery_planned_at' => '2026-10-05'])
            ->assertRedirect(route('orders.show', 'ms-1'))
            ->assertSessionHas('success');

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/entity/customerorder/ms-1')
            && $request->data() === ['deliveryPlannedMoment' => '2026-10-05 00:00:00']);

        $order->refresh();
        expect($order->delivery_planned_at->format('Y-m-d H:i:s'))->toBe('2026-10-05 00:00:00')
            ->and($order->priority_key)->toBe(OrderPriority::autoKey($order->delivery_planned_at, $order->moment));
    });

    test('время прежней даты сохраняется — меняется только день', function () {
        Http::fake(['*' => Http::response(['id' => 'ms-1'], 200)]);

        Order::create([
            'moysklad_id'         => 'ms-1',
            'name'                => 'Заявка 1',
            'delivery_planned_at' => '2026-09-25 14:30:00',
        ]);

        $this->actingAs(H::adminUser())
            ->post(route('orders.delivery-date.update', 'ms-1'), ['delivery_planned_at' => '2026-10-05'])
            ->assertSessionHas('success');

        Http::assertSent(fn ($request) => $request->data() === ['deliveryPlannedMoment' => '2026-10-05 14:30:00']);
    });

    test('ручное место в очереди не сбрасывается', function () {
        Http::fake(['*' => Http::response(['id' => 'ms-1'], 200)]);

        Order::create([
            'moysklad_id'     => 'ms-1',
            'name'            => 'Заявка 1',
            'priority_key'    => 42.5,
            'priority_manual' => true,
        ]);

        $this->actingAs(H::adminUser())
            ->post(route('orders.delivery-date.update', 'ms-1'), ['delivery_planned_at' => '2026-10-05'])
            ->assertSessionHas('success');

        expect(Order::first()->priority_key)->toBe(42.5);
    });

    test('ошибка МойСклад — flash error, локальная дата прежняя', function () {
        Http::fake(['*' => Http::response(['errors' => [['error' => 'Нет прав на изменение']]], 412)]);

        Order::create([
            'moysklad_id'         => 'ms-1',
            'name'                => 'Заявка 1',
            'delivery_planned_at' => '2026-09-25 00:00:00',
        ]);

        $this->actingAs(H::adminUser())
            ->post(route('orders.delivery-date.update', 'ms-1'), ['delivery_planned_at' => '2026-10-05'])
            ->assertSessionHas('error', 'Ошибка МойСклад: Нет прав на изменение');

        expect(Order::first()->delivery_planned_at->format('Y-m-d'))->toBe('2026-09-25');
    });

    test('пустая или неверная дата отклоняется без запроса в МойСклад', function (?string $value) {
        Http::fake();

        Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);

        $this->actingAs(H::adminUser())
            ->from(route('orders.show', 'ms-1'))
            ->post(route('orders.delivery-date.update', 'ms-1'), ['delivery_planned_at' => $value])
            ->assertSessionHasErrors('delivery_planned_at');

        Http::assertNothingSent();
    })->with([
        'пусто'        => [null],
        'не дата'      => ['завтра'],
        'чужой формат' => ['05.10.2026'],
    ]);

    test('мастер чужого отдела получает 403', function () {
        Http::fake();

        $own   = Department::create(['name' => 'Отдел 1', 'is_active' => true]);
        $other = Department::create(['name' => 'Отдел 2', 'is_active' => true]);
        $user  = Access::master($own, 'orders');

        $order = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);
        $order->departments()->attach($other->id);

        $this->actingAs($user)
            ->post(route('orders.delivery-date.update', 'ms-1'), ['delivery_planned_at' => '2026-10-05'])
            ->assertForbidden();

        Http::assertNothingSent();
    });
});
