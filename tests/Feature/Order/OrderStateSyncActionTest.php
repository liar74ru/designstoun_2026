<?php

use App\Models\Order;
use App\Models\OrderState;
use App\Services\Moysklad\OrderStateSyncService;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

// ══════════════════════════════════════════════════════════════════════════════
// POST /admin/order-states/sync — перечитать справочник статусов из МойСклад
// ══════════════════════════════════════════════════════════════════════════════

const OSA_NEW  = '33333333-3333-3333-3333-333333333333';
const OSA_DONE = '44444444-4444-4444-4444-444444444444';
const OSA_OLD  = '55555555-5555-5555-5555-555555555555';

function osaState(string $id, string $name, int $color = 15280409): array
{
    return ['id' => $id, 'name' => $name, 'color' => $color, 'stateType' => 'Regular'];
}

beforeEach(function () {
    config()->set('services.moysklad.token', 'test-token');
    Order::forgetStateCache();
});

describe('Admin\OrderStateController sync()', function () {

    test('перечитывает справочник из МойСклад и возвращает к списку с итогом', function () {
        Http::fake(['*' => Http::response(['states' => [
            osaState(OSA_NEW, 'Новый', 15106326),
            osaState(OSA_DONE, 'Отгружен', 8767198),
        ]], 200)]);

        $this->actingAs(H::adminUser())
            ->post(route('admin.order-states.sync'))
            ->assertRedirect(route('admin.order-states.index'))
            ->assertSessionHas('success', 'Добавлено: 2, обновлено: 0')
            ->assertSessionMissing('error');

        expect(OrderState::count())->toBe(2)
            ->and(OrderState::find(OSA_NEW)->name)->toBe('Новый')
            ->and(OrderState::find(OSA_DONE)->position)->toBe(1);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/entity/customerorder/metadata'));
    });

    test('повторная синхронизация: обновляет, а пропавший статус архивирует', function () {
        OrderState::create(['id' => OSA_NEW, 'name' => 'Старое имя', 'is_enabled' => true]);
        OrderState::create(['id' => OSA_OLD, 'name' => 'Удалённый', 'is_enabled' => true]);

        Http::fake(['*' => Http::response(['states' => [osaState(OSA_NEW, 'Новый')]], 200)]);

        $this->actingAs(H::adminUser())
            ->post(route('admin.order-states.sync'))
            ->assertRedirect(route('admin.order-states.index'))
            ->assertSessionHas('success', 'Добавлено: 0, обновлено: 1, пропало из МойСклад: 1');

        expect(OrderState::find(OSA_NEW)->name)->toBe('Новый')
            ->and(OrderState::find(OSA_NEW)->is_enabled)->toBeTrue()
            ->and(OrderState::find(OSA_OLD)->archived)->toBeTrue();
    });

    test('итог показывается на странице справочника после редиректа', function () {
        Http::fake(['*' => Http::response(['states' => [osaState(OSA_NEW, 'Новый')]], 200)]);

        $this->actingAs(H::adminUser())
            ->followingRedirects()
            ->post(route('admin.order-states.sync'))
            ->assertOk()
            ->assertViewIs('admin.order-states.index')
            ->assertSee('Добавлено: 1, обновлено: 0')
            ->assertSee('Новый');
    });

    test('ошибка МойСклад — flash error, справочник не тронут', function () {
        OrderState::create(['id' => OSA_NEW, 'name' => 'Новый']);

        Http::fake(['*' => Http::response(['errors' => [['error' => 'Доступ запрещён']]], 403)]);

        $this->actingAs(H::adminUser())
            ->post(route('admin.order-states.sync'))
            ->assertRedirect(route('admin.order-states.index'))
            ->assertSessionHas('error')
            ->assertSessionMissing('success');

        expect(OrderState::count())->toBe(1)
            ->and(OrderState::find(OSA_NEW)->archived)->toBeFalse();
    });

    test('МойСклад вернул пустой список — ошибка, существующие статусы не архивируются', function () {
        OrderState::create(['id' => OSA_NEW, 'name' => 'Новый']);

        Http::fake(['*' => Http::response(['states' => []], 200)]);

        $this->actingAs(H::adminUser())
            ->post(route('admin.order-states.sync'))
            ->assertRedirect(route('admin.order-states.index'))
            ->assertSessionHas('error', 'МойСклад не вернул ни одного статуса');

        expect(OrderState::find(OSA_NEW)->archived)->toBeFalse();
    });

    test('без токена — ошибка и запрос в МойСклад не уходит', function () {
        config()->set('services.moysklad.token', null);
        Http::fake();

        $this->actingAs(H::adminUser())
            ->post(route('admin.order-states.sync'))
            ->assertRedirect(route('admin.order-states.index'))
            ->assertSessionHas('error', 'MOYSKLAD_TOKEN не установлен');

        Http::assertNothingSent();
        expect(OrderState::count())->toBe(0);
    });

    test('результат сервиса передаётся во flash как есть', function () {
        $mock = Mockery::mock(OrderStateSyncService::class);
        $mock->shouldReceive('sync')->once()->andReturn([
            'success' => false, 'synced' => 0, 'updated' => 0, 'archived' => 0,
            'message' => 'Ошибка синхронизации: timeout',
        ]);
        app()->instance(OrderStateSyncService::class, $mock);

        $this->actingAs(H::adminUser())
            ->post(route('admin.order-states.sync'))
            ->assertRedirect(route('admin.order-states.index'))
            ->assertSessionHas('error', 'Ошибка синхронизации: timeout');
    });

    test('мастер получает 403, синхронизация не запускается', function () {
        $mock = Mockery::mock(OrderStateSyncService::class);
        $mock->shouldNotReceive('sync');
        app()->instance(OrderStateSyncService::class, $mock);

        $this->actingAs(Access::master(Access::department(), 'orders'))
            ->post(route('admin.order-states.sync'))
            ->assertForbidden();
    });

    test('работник получает 403', function () {
        Http::fake();

        $this->actingAs(Access::userWithPosition('Работник', Access::department()))
            ->post(route('admin.order-states.sync'))
            ->assertForbidden();

        Http::assertNothingSent();
    });
});
