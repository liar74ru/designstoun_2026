<?php

use App\Models\Counterparty;
use App\Services\Moysklad\MoySkladService;
use Tests\Helpers\ReceptionTestHelper as H;

function mockMoySkladForCounterparty(bool $success = true): void
{
    $mock = Mockery::mock(MoySkladService::class);
    $mock->shouldReceive('syncCounterparties')->andReturn([
        'success' => $success,
        'message' => $success ? 'Синхронизировано 3 контрагента' : 'Ошибка API',
    ]);
    app()->instance(MoySkladService::class, $mock);
}

// ══════════════════════════════════════════════════════════════════════════════
// index()
// ══════════════════════════════════════════════════════════════════════════════

describe('CounterpartyController index()', function () {

    test('доступна авторизованному', function () {
        $this->actingAs(H::adminUser())
            ->get(route('counterparties.index'))
            ->assertStatus(200);
    });

    test('недоступна без авторизации', function () {
        $this->get(route('counterparties.index'))
            ->assertRedirect('/login');
    });

    test('отображает контрагентов', function () {
        Counterparty::create([
            'name'        => 'ООО Тест Поставщик',
            'moysklad_id' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->actingAs(H::adminUser())
            ->get(route('counterparties.index'))
            ->assertStatus(200)
            ->assertSee('ООО Тест Поставщик');
    });

    test('страница доступна при пустом списке', function () {
        $this->actingAs(H::adminUser())
            ->get(route('counterparties.index'))
            ->assertStatus(200);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// sync()
// ══════════════════════════════════════════════════════════════════════════════

describe('CounterpartyController sync()', function () {

    test('успешная синхронизация редиректит с success', function () {
        mockMoySkladForCounterparty(true);

        $this->actingAs(H::adminUser())
            ->post(route('counterparties.sync'))
            ->assertRedirect(route('counterparties.index'))
            ->assertSessionHas('success');
    });

    test('ошибка синхронизации редиректит с error', function () {
        mockMoySkladForCounterparty(false);

        $this->actingAs(H::adminUser())
            ->post(route('counterparties.sync'))
            ->assertRedirect(route('counterparties.index'))
            ->assertSessionHas('error');
    });

    test('недоступна без авторизации', function () {
        $this->post(route('counterparties.sync'))
            ->assertRedirect('/login');
    });
});
