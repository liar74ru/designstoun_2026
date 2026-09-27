<?php

use App\Models\Department;
use App\Models\DepartmentExpense;
use App\Models\RawMaterialBatch;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use App\Services\Moysklad\StoneReceptionSyncService;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

/**
 * Готовая активная приёмка: партия (по умолчанию без отдела) + приёмка на складе.
 *
 * @return array{reception: StoneReception, batch: RawMaterialBatch, store: \App\Models\Store}
 */
function rxaFixture(array $batchAttrs = [], array $receptionAttrs = []): array
{
    $receiver = H::worker('Приёмщик RXA');
    $cutter   = H::cutter('Пильщик RXA');
    $store    = H::store('Склад RXA');
    $rawProd  = H::product(['name' => 'Сырьё RXA']);
    $batch    = H::batch($rawProd, $store, $cutter, 50.0, $batchAttrs);

    return [
        'reception' => H::reception($batch, $receiver, $cutter, $store, 5.0, $receptionAttrs),
        'batch'     => $batch,
        'store'     => $store,
    ];
}

/** Подменить сервис синхронизации приёмок; $onSync выполняется вместо вызова МойСклад. */
function rxaMockSync(?callable $onSync = null, int $times = 1): void
{
    $mock = Mockery::mock(StoneReceptionSyncService::class);
    $expectation = $mock->shouldReceive('syncReception')->times($times);

    if ($onSync) {
        $expectation->andReturnUsing($onSync);
    }

    app()->instance(StoneReceptionSyncService::class, $mock);
}

// ══════════════════════════════════════════════════════════════════════════════
// syncToProcessing()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionController syncToProcessing()', function () {

    test('успешная синхронизация — флеш success', function () {
        ['reception' => $reception] = rxaFixture();

        rxaMockSync(function (StoneReception $r) {
            $r->markSynced('ms-proc-1', 'ТО-1');
        });

        $this->actingAs(H::adminUser())
            ->from(route('stone-receptions.show', $reception))
            ->post(route('stone-receptions.sync', $reception))
            ->assertRedirect(route('stone-receptions.show', $reception))
            ->assertSessionHas('success', 'Техоперация синхронизирована с МойСклад.');

        $reception->refresh();
        expect($reception->isSynced())->toBeTrue()
            ->and($reception->moysklad_processing_id)->toBe('ms-proc-1');
    });

    test('ошибка синхронизации — флеш error с текстом ошибки', function () {
        ['reception' => $reception] = rxaFixture();

        rxaMockSync(function (StoneReception $r) {
            $r->markSyncError('Товар не найден');
        });

        $this->actingAs(H::adminUser())
            ->post(route('stone-receptions.sync', $reception))
            ->assertRedirect()
            ->assertSessionHas('error', 'Ошибка синхронизации: Товар не найден');

        expect($reception->fresh()->isSynced())->toBeFalse();
    });

    test('приёмка без партии сырья — ошибка, МойСклад не вызывается', function () {
        ['reception' => $reception] = rxaFixture([], ['raw_material_batch_id' => null]);

        rxaMockSync(null, 0);

        $this->actingAs(H::adminUser())
            ->post(route('stone-receptions.sync', $reception))
            ->assertRedirect()
            ->assertSessionHas('error', 'Партия сырья не найдена.');
    });

    test('мастер отдела без операции stone-receptions — 403', function () {
        ['reception' => $reception] = rxaFixture();
        rxaMockSync(null, 0);

        $master = Access::master(Access::department(), 'raw-batches');

        $this->actingAs($master)
            ->post(route('stone-receptions.sync', $reception))
            ->assertForbidden();
    });

    test('работник — 403', function () {
        ['reception' => $reception] = rxaFixture();
        rxaMockSync(null, 0);

        $this->actingAs(Access::userWithPosition('Работник', Access::department()))
            ->post(route('stone-receptions.sync', $reception))
            ->assertForbidden();
    });

    test('мастер отдела с операцией stone-receptions может синхронизировать', function () {
        $dept = Access::department();
        ['reception' => $reception] = rxaFixture(['department_id' => $dept->id], ['department_id' => $dept->id]);

        rxaMockSync(fn (StoneReception $r) => $r->markSynced('ms-proc-2'));

        $this->actingAs(Access::master($dept, 'stone-receptions'))
            ->post(route('stone-receptions.sync', $reception))
            ->assertSessionHas('success');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// updateStore()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionController updateStore()', function () {

    test('меняет склад активной приёмки', function () {
        ['reception' => $reception] = rxaFixture();
        $newStore = H::store('Новый склад RXA');

        $this->actingAs(H::adminUser())
            ->from(route('stone-receptions.show', $reception))
            ->patch(route('stone-receptions.update-store', $reception), ['store_id' => $newStore->id])
            ->assertRedirect(route('stone-receptions.show', $reception))
            ->assertSessionHas('success', 'Склад приёмки обновлён.');

        expect($reception->fresh()->store_id)->toBe($newStore->id);
    });

    test('403 если приёмка не активна — склад не меняется', function () {
        ['reception' => $reception, 'store' => $store] = rxaFixture([], [
            'status' => StoneReception::STATUS_COMPLETED,
        ]);
        $newStore = H::store('Новый склад RXA');

        $this->actingAs(H::adminUser())
            ->patch(route('stone-receptions.update-store', $reception), ['store_id' => $newStore->id])
            ->assertForbidden();

        expect($reception->fresh()->store_id)->toBe($store->id);
    });

    test('отклоняет без store_id', function () {
        ['reception' => $reception] = rxaFixture();

        $this->actingAs(H::adminUser())
            ->patch(route('stone-receptions.update-store', $reception), [])
            ->assertSessionHasErrors(['store_id']);
    });

    test('отклоняет несуществующий склад', function () {
        ['reception' => $reception, 'store' => $store] = rxaFixture();

        $this->actingAs(H::adminUser())
            ->patch(route('stone-receptions.update-store', $reception), [
                'store_id' => '00000000-0000-0000-0000-000000000000',
            ])
            ->assertSessionHasErrors(['store_id']);

        expect($reception->fresh()->store_id)->toBe($store->id);
    });

    test('мастер отдела без операции stone-receptions — 403', function () {
        ['reception' => $reception, 'store' => $store] = rxaFixture();
        $newStore = H::store('Новый склад RXA');

        $this->actingAs(Access::master(Access::department(), 'workers'))
            ->patch(route('stone-receptions.update-store', $reception), ['store_id' => $newStore->id])
            ->assertForbidden();

        expect($reception->fresh()->store_id)->toBe($store->id);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// refreshItemCoeffs()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionController refreshItemCoeffs()', function () {

    test('подтягивает коэффициенты продукта из справочника', function () {
        ['reception' => $reception] = rxaFixture();
        $product = H::product(['prod_cost_coeff' => 1.0, 'master_cost_coeff' => 0.5]);

        $item = StoneReceptionItem::create([
            'stone_reception_id'   => $reception->id,
            'product_id'           => $product->id,
            'quantity'             => 2.0,
            'base_cost_coeff'      => 1.0,
            'effective_cost_coeff' => 1.0,
        ]);

        $product->update(['prod_cost_coeff' => 3.0, 'master_cost_coeff' => 2.0]);

        $this->actingAs(H::adminUser())
            ->from(route('stone-receptions.show', $reception))
            ->post(route('stone-receptions.refresh-item-coeffs', $reception))
            ->assertRedirect(route('stone-receptions.show', $reception))
            ->assertSessionHas('success', 'Коэффициенты обновлены из справочника');

        $item->refresh();
        expect((float) $item->base_cost_coeff)->toBe(3.0)
            ->and((float) $item->effective_cost_coeff)->toBe(3.0)
            ->and((float) $item->master_base_cost_coeff)->toBe(2.0)
            ->and((float) $item->master_effective_cost_coeff)->toBe(2.0)
            ->and($item->worker_cost_per_m2)->not->toBeNull();
    });

    test('сохраняет ручные правила из снапшота позиции', function () {
        $dept = H::departmentWithModifiers('Отдел RXA');
        ['reception' => $reception] = rxaFixture(['department_id' => $dept->id]);
        $product = H::product(['prod_cost_coeff' => 1.0]);

        $item = StoneReceptionItem::create([
            'stone_reception_id'   => $reception->id,
            'product_id'           => $product->id,
            'quantity'             => 2.0,
            'effective_cost_coeff' => 1.0,
        ]);

        $admin = H::adminUser();

        // Отмечаем подкол штатным путём — так пишется снапшот правил
        $this->actingAs($admin)->post(route('stone-receptions.update-item-coeff', $reception), [
            'items' => [[
                'item_id'           => $item->id,
                'base_coeff'        => 1.0,
                'master_base_coeff' => 0,
                'modifiers'         => ['undercut'],
            ]],
        ]);

        $product->update(['prod_cost_coeff' => 2.0]);

        $this->actingAs($admin)
            ->post(route('stone-receptions.refresh-item-coeffs', $reception))
            ->assertSessionHas('success');

        $item->refresh();
        // Подкол (−1.5) сохранился и лёг на новую базу 2.0
        expect((float) $item->base_cost_coeff)->toBe(2.0)
            ->and((float) $item->effective_cost_coeff)->toBe(0.5)
            ->and($item->is_undercut)->toBeTrue()
            ->and($item->modifiers->pluck('key')->all())->toBe(['undercut']);
    });

    test('пропускает позицию, у продукта которой нет коэффициента', function () {
        ['reception' => $reception] = rxaFixture();
        $product = H::product(['prod_cost_coeff' => null]);

        $item = StoneReceptionItem::create([
            'stone_reception_id'   => $reception->id,
            'product_id'           => $product->id,
            'quantity'             => 2.0,
            'base_cost_coeff'      => 1.25,
            'effective_cost_coeff' => 1.25,
        ]);

        $this->actingAs(H::adminUser())
            ->post(route('stone-receptions.refresh-item-coeffs', $reception))
            ->assertSessionHas('success');

        expect((float) $item->fresh()->effective_cost_coeff)->toBe(1.25);
    });

    test('обновляет processing_sum партии по накладным отдела', function () {
        $dept = Access::department();
        DepartmentExpense::create(['department_id' => $dept->id, 'name' => 'Аренда', 'amount' => 120]);
        DepartmentExpense::create(['department_id' => $dept->id, 'name' => 'Свет', 'amount' => 30]);
        $dept->forgetSettingsCache();

        ['reception' => $reception, 'batch' => $batch] = rxaFixture(['department_id' => $dept->id]);

        $this->actingAs(H::adminUser())
            ->post(route('stone-receptions.refresh-item-coeffs', $reception))
            ->assertSessionHas('success');

        expect((float) $batch->fresh()->processing_sum)->toBe(150.0);
    });

    test('мастер отдела без операции stone-receptions — 403', function () {
        ['reception' => $reception] = rxaFixture();

        $this->actingAs(Access::master(Access::department(), 'workshops'))
            ->post(route('stone-receptions.refresh-item-coeffs', $reception))
            ->assertForbidden();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// getReceptionsByBatchJson()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionController getReceptionsByBatchJson()', function () {

    test('возвращает приёмки всех партий того же сырья с позициями', function () {
        $receiver = H::worker('Приёмщик JSON');
        $cutter   = H::cutter('Пильщик JSON');
        $store    = H::store();
        $rawProd  = H::product(['name' => 'Сырьё JSON']);
        $output   = H::product(['name' => 'Плитка JSON']);

        $batchA = H::batch($rawProd, $store, $cutter, 50.0, ['batch_number' => 'J-A']);
        $batchB = H::batch($rawProd, $store, $cutter, 50.0, ['batch_number' => 'J-B']);

        $recA = H::reception($batchA, $receiver, $cutter, $store, 1.0);
        $recB = H::reception($batchB, $receiver, $cutter, $store, 1.0);

        StoneReceptionItem::create([
            'stone_reception_id' => $recA->id,
            'product_id'         => $output->id,
            'quantity'           => 1.5,
        ]);
        StoneReceptionItem::create([
            'stone_reception_id' => $recA->id,
            'product_id'         => H::product()->id,
            'quantity'           => 0.25,
        ]);

        // Приёмка по другому сырью в выдачу не попадает
        $otherBatch = H::batch(H::product(), $store, $cutter, 50.0, ['batch_number' => 'J-X']);
        $recOther   = H::reception($otherBatch, $receiver, $cutter, $store, 1.0);

        $response = $this->actingAs(H::adminUser())
            ->getJson(route('api.batch.receptions', $batchA))
            ->assertOk()
            ->assertJsonCount(2);

        $ids = collect($response->json())->pluck('id')->all();
        expect($ids)->toContain($recA->id, $recB->id)
            ->not->toContain($recOther->id);

        $rowA = collect($response->json())->firstWhere('id', $recA->id);
        expect($rowA['total_quantity'])->toBe('1.75')
            ->and($rowA['cutter_name'])->toBe('Пильщик JSON')
            ->and($rowA['cutter_id'])->toBe($cutter->id)
            ->and($rowA['raw_material_batch_id'])->toBe($batchA->id)
            ->and($rowA['items'])->toHaveCount(2)
            ->and(collect($rowA['items'])->firstWhere('product_id', $output->id))->toMatchArray([
                'product_name' => 'Плитка JSON',
                'quantity'     => '1.50',
                'modifiers'    => [],
            ]);
    });

    test('сортирует по дате создания, новые сверху, и ограничивает 15 записями', function () {
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $batch    = H::batch(H::product(), $store, $cutter, 100.0);

        $receptions = collect(range(1, 17))->map(fn (int $i) => H::reception(
            $batch, $receiver, $cutter, $store, 0.5,
            ['created_at' => now()->subDays(20 - $i)],
        ));

        $response = $this->actingAs(H::adminUser())
            ->getJson(route('api.batch.receptions', $batch))
            ->assertOk()
            ->assertJsonCount(15);

        expect($response->json('0.id'))->toBe($receptions->last()->id)
            ->and(collect($response->json())->pluck('id'))->not->toContain($receptions->first()->id);
    });

    test('пустой массив, если приёмок нет', function () {
        $batch = H::batch(H::product(), H::store(), H::cutter(), 10.0);

        $this->actingAs(H::adminUser())
            ->getJson(route('api.batch.receptions', $batch))
            ->assertOk()
            ->assertExactJson([]);
    });

    test('404 для несуществующей партии', function () {
        $this->actingAs(H::adminUser())
            ->getJson(route('api.batch.receptions', 999999))
            ->assertNotFound();
    });

    test('мастер отдела без операции stone-receptions — 403', function () {
        $batch = H::batch(H::product(), H::store(), H::cutter(), 10.0);

        $this->actingAs(Access::master(Access::department(), 'raw-batches'))
            ->getJson(route('api.batch.receptions', $batch))
            ->assertForbidden();
    });

    test('мастер без отдела — 403', function () {
        $batch = H::batch(H::product(), H::store(), H::cutter(), 10.0);

        $this->actingAs(Access::masterWithoutDept())
            ->getJson(route('api.batch.receptions', $batch))
            ->assertForbidden();
    });
});
