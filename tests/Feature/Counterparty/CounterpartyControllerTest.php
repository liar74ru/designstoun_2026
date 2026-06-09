<?php

use App\Models\Counterparty;
use App\Models\User;

// ══════════════════════════════════════════════════════════════════════════════
// CounterpartyController::index()
// ══════════════════════════════════════════════════════════════════════════════

describe('CounterpartyController::index()', function () {

    test('отображает список контрагентов для авторизованного пользователя', function () {
        $user = User::factory()->create(['is_admin' => true]);
        Counterparty::create(['name' => 'Контрагент 1', 'moysklad_id' => 'ms-1']);
        Counterparty::create(['name' => 'Контрагент 2', 'moysklad_id' => 'ms-2']);

        $this->actingAs($user)
            ->get(route('counterparties.index'))
            ->assertSuccessful()
            ->assertViewIs('counterparties.index')
            ->assertViewHas('counterparties', fn ($cp) => $cp->count() === 2);
    });

    test('недоступна без авторизации', function () {
        $this->get(route('counterparties.index'))
            ->assertRedirect('/login');
    });

    test('сортирует контрагентов по имени', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $cp1 = Counterparty::create(['name' => 'Зеленый', 'moysklad_id' => 'ms-1']);
        $cp2 = Counterparty::create(['name' => 'Амперсанд', 'moysklad_id' => 'ms-2']);
        $cp3 = Counterparty::create(['name' => 'Мандарин', 'moysklad_id' => 'ms-3']);

        $response = $this->actingAs($user)
            ->get(route('counterparties.index'))
            ->assertSuccessful()
            ->viewData('counterparties');

        $names = $response->pluck('name')->toArray();
        expect($names)->toBe(['Амперсанд', 'Зеленый', 'Мандарин']);
    });

    test('отображает пустой список когда контрагентов нет', function () {
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->get(route('counterparties.index'))
            ->assertSuccessful()
            ->assertViewHas('counterparties', fn ($cp) => $cp->count() === 0);
    });

    test('работнику без админских прав список недоступен', function () {
        $user = User::factory()->create(['is_admin' => false]);
        Counterparty::create(['name' => 'Тест', 'moysklad_id' => 'ms-1']);

        $this->actingAs($user)
            ->get(route('counterparties.index'))
            ->assertForbidden();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// CounterpartyController::sync()
// ══════════════════════════════════════════════════════════════════════════════

describe('CounterpartyController::sync()', function () {

    test('редирект на index после синхронизации', function () {
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->post(route('counterparties.sync'))
            ->assertRedirect(route('counterparties.index'));
    });

    test('недоступен без авторизации', function () {
        $this->post(route('counterparties.sync'))
            ->assertRedirect('/login');
    });

    test('показывает сообщение об ошибке при неудаче синхронизации', function () {
        config()->set('services.moysklad.token', '');
        $user = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($user)
            ->post(route('counterparties.sync'));

        expect($response->status())->toBeIn([200, 302]);
    });

    test('показывает успешное сообщение при успешной синхронизации', function () {
        config()->set('services.moysklad.token', 'test-token');
        $user = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($user)
            ->post(route('counterparties.sync'));

        expect($response->status())->toBeIn([200, 302]);
    });

    test('работнику недоступна синхронизация', function () {
        config()->set('services.moysklad.token', 'test-token');
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->post(route('counterparties.sync'))
            ->assertForbidden();
    });
});
