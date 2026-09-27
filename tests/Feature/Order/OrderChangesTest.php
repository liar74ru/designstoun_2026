<?php

use App\Models\Department;
use App\Models\Order;
use App\Models\OrderState;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

/**
 * Изменение состава заявки: плашка «Изменена», «было → стало» и кнопка «Принято».
 */

const CHG_WORK    = '11111111-aaaa-1111-1111-111111111111';
const CHG_NEW     = '22222222-aaaa-2222-2222-222222222222';
const CHG_CHANGED = '33333333-aaaa-3333-3333-333333333333';

beforeEach(function () {
    config()->set('services.moysklad.token', 'test-token');
    OrderState::create(['id' => CHG_WORK, 'name' => 'В процессе', 'is_enabled' => true, 'is_production' => true]);
    OrderState::create(['id' => CHG_NEW, 'name' => 'Новый', 'is_enabled' => true]);
    OrderState::create(['id' => CHG_CHANGED, 'name' => 'Изменено', 'is_enabled' => true, 'is_changed' => true]);
    Order::forgetStateCache();
});

/** Заявка отдела с непринятым изменением: «Плитка» было 10 → стало 15. */
function changedOrder(?Department $dept = null, array $attrs = []): Order
{
    $order = Order::create(array_merge([
        'moysklad_id'          => 'ms-chg',
        'name'                 => 'Заявка-изм',
        'state_moysklad_id'    => CHG_WORK,
        'state_name'           => 'В процессе',
        'positions_changed_at' => now(),
        'position_changes'     => ['pm-1' => ['name' => 'Плитка', 'from' => 10, 'to' => 15]],
    ], $attrs));
    $order->items()->create(['product_moysklad_id' => 'pm-1', 'product_name' => 'Плитка', 'quantity' => 15, 'shipped' => 0]);

    if ($dept) {
        $order->departments()->attach($dept->id);
    }

    return $order;
}

describe('«Принято»', function () {

    test('очищает изменения, статус не трогает', function () {
        Http::fake();
        $order = changedOrder();

        $this->actingAs(H::adminUser())
            ->post(route('orders.changes.acknowledge', $order->moysklad_id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $order->refresh();
        expect($order->positions_changed_at)->toBeNull()
            ->and($order->position_changes)->toBeNull()
            ->and($order->state_moysklad_id)->toBe(CHG_WORK);
        Http::assertNothingSent();
    });

    test('заявку в «Изменено» возвращает в прежний статус в МойСклад', function () {
        Http::fake(['*' => Http::response([], 200)]);
        $order = changedOrder(attrs: ['state_moysklad_id' => CHG_CHANGED, 'state_before_change' => CHG_NEW]);

        $this->actingAs(H::adminUser())
            ->post(route('orders.changes.acknowledge', $order->moysklad_id))
            ->assertSessionHas('success');

        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && str_contains($r->url(), '/entity/customerorder/ms-chg')
            && str_contains($r['state']['meta']['href'], CHG_NEW));

        $order->refresh();
        expect($order->state_moysklad_id)->toBe(CHG_NEW)
            ->and($order->state_before_change)->toBeNull()
            ->and($order->positions_changed_at)->toBeNull();
    });

    test('прежний статус неизвестен — возвращает в производственный, окно не перезапускается', function () {
        Http::fake(['*' => Http::response([], 200)]);
        $started = now()->subDays(3)->startOfSecond();
        $order = changedOrder(attrs: ['state_moysklad_id' => CHG_CHANGED, 'production_started_at' => $started]);

        $this->actingAs(H::adminUser())
            ->post(route('orders.changes.acknowledge', $order->moysklad_id));

        $order->refresh();
        expect($order->state_moysklad_id)->toBe(CHG_WORK)
            ->and($order->production_started_at->equalTo($started))->toBeTrue()
            ->and($order->production_ended_at)->toBeNull();
    });

    test('МойСклад не записал статус — изменения остаются на виду', function () {
        Http::fake(['*' => Http::response(['errors' => [['error' => 'Сбой']]], 500)]);
        $order = changedOrder(attrs: ['state_moysklad_id' => CHG_CHANGED, 'state_before_change' => CHG_WORK]);

        $this->actingAs(H::adminUser())
            ->post(route('orders.changes.acknowledge', $order->moysklad_id))
            ->assertSessionHas('error');

        $order->refresh();
        expect($order->positions_changed_at)->not->toBeNull()
            ->and($order->state_moysklad_id)->toBe(CHG_CHANGED);
    });

    test('мастер чужого отдела принять не может', function () {
        $order = changedOrder(Access::department('Чужой'));

        $this->actingAs(Access::master(Access::department('Свой'), 'orders'))
            ->post(route('orders.changes.acknowledge', $order->moysklad_id))
            ->assertForbidden();

        expect($order->fresh()->positions_changed_at)->not->toBeNull();
    });

    test('смена статуса из программы в «Изменено» запоминает прежний', function () {
        Http::fake(['*' => Http::response([], 200)]);
        $order = changedOrder();

        $this->actingAs(H::adminUser())
            ->post(route('orders.state.update', $order->moysklad_id), ['state_id' => CHG_CHANGED]);

        expect($order->fresh()->state_before_change)->toBe(CHG_WORK);
    });
});

describe('Плашка «Изменена»', function () {

    test('в списке и карточке — с «было → стало» и кнопкой; после «Принято» нет', function () {
        Http::fake();
        // Список по умолчанию показывает заявки с отделом
        $order = changedOrder(Access::department('Отдел'));
        $admin = H::adminUser();

        $this->actingAs($admin)->get(route('orders.index'))->assertSee('Изменена');
        $this->actingAs($admin)->get(route('orders.show', $order->moysklad_id))
            ->assertSee('Заявка изменена')
            ->assertSee('было 10')
            ->assertSee(route('orders.changes.acknowledge', $order->moysklad_id));

        $this->actingAs($admin)->post(route('orders.changes.acknowledge', $order->moysklad_id));

        $this->actingAs($admin)->get(route('orders.index'))->assertDontSee('Изменена');
        $this->actingAs($admin)->get(route('orders.show', $order->moysklad_id))
            ->assertDontSee('Заявка изменена')
            ->assertDontSee(route('orders.changes.acknowledge', $order->moysklad_id));
    });

    test('заявка в статусе «Изменено» без замеченных изменений тоже с плашкой и возвратом', function () {
        $order = changedOrder(attrs: [
            'state_moysklad_id'    => CHG_CHANGED,
            'positions_changed_at' => null,
            'position_changes'     => null,
            'state_before_change'  => CHG_NEW,
        ]);

        $this->actingAs(H::adminUser())->get(route('orders.show', $order->moysklad_id))
            ->assertSee('Заявка изменена')
            ->assertSee('вернуть в «Новый»', false);
    });
});
