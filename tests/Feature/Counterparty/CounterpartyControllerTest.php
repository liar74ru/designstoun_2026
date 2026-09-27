<?php

use App\Models\Counterparty;
use App\Models\User;
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
// CounterpartyController::index()
// ══════════════════════════════════════════════════════════════════════════════

describe('CounterpartyController::index()', function () {

    test('отображает список контрагентов', function () {
        Counterparty::create(['name' => 'ООО Тест Поставщик', 'moysklad_id' => 'ms-1']);
        Counterparty::create(['name' => 'Контрагент 2', 'moysklad_id' => 'ms-2']);

        $this->actingAs(H::adminUser())
            ->get(route('counterparties.index'))
            ->assertSuccessful()
            ->assertViewIs('counterparties.index')
            ->assertViewHas('counterparties', fn ($cp) => $cp->count() === 2)
            ->assertSee('ООО Тест Поставщик');
    });

    test('сортирует контрагентов по имени', function () {
        Counterparty::create(['name' => 'Зеленый', 'moysklad_id' => 'ms-1']);
        Counterparty::create(['name' => 'Амперсанд', 'moysklad_id' => 'ms-2']);
        Counterparty::create(['name' => 'Мандарин', 'moysklad_id' => 'ms-3']);

        $names = $this->actingAs(H::adminUser())
            ->get(route('counterparties.index'))
            ->assertSuccessful()
            ->viewData('counterparties')
            ->pluck('name')
            ->toArray();

        expect($names)->toBe(['Амперсанд', 'Зеленый', 'Мандарин']);
    });

    test('отображает пустой список когда контрагентов нет', function () {
        $this->actingAs(H::adminUser())
            ->get(route('counterparties.index'))
            ->assertSuccessful()
            ->assertViewHas('counterparties', fn ($cp) => $cp->count() === 0);
    });

    test('работнику без админских прав список недоступен', function () {
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get(route('counterparties.index'))
            ->assertForbidden();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// CounterpartyController::sync()
// ══════════════════════════════════════════════════════════════════════════════

describe('CounterpartyController::sync()', function () {

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

    test('работнику недоступна синхронизация', function () {
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->post(route('counterparties.sync'))
            ->assertForbidden();
    });
});
