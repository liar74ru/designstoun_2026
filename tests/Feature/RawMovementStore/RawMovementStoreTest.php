<?php

use App\Models\ProductStock;
use App\Models\RawMaterialBatch;
use App\Models\RawMaterialMovement;
use App\Services\MoySkladMoveService;
use Tests\Helpers\ReceptionTestHelper as H;

// МойСклад мокаем во всех тестах — реальных HTTP-запросов нет
function mockMoySklad(bool $success = true): void
{
    $mock = Mockery::mock(MoySkladMoveService::class);
    $mock->shouldReceive('createMove')->andReturn([
        'success' => $success,
        'move_id' => 'msk-test-uuid',
        'message' => $success ? '' : 'API error',
    ]);
    app()->instance(MoySkladMoveService::class, $mock);
}

// ══════════════════════════════════════════════════════════════════════════════
// store() — создание партии и перемещение со склада
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialMovementController store()', function () {

    test('успешно создаёт партию и движение', function () {
        mockMoySklad();
        $user    = H::adminUser();
        $product = H::product();
        $from    = H::store('Главный склад');
        $to      = H::store('Цех');
        $cutter  = H::cutter();
        H::stock($product, $from, 50.0);

        $this->actingAs($user)->post(route('raw-movement.store'), [
            'product_id'    => $product->id,
            'quantity'      => 10.0,
            'worker_id'     => $cutter->id,
            'from_store_id' => $from->id,
            'to_store_id'   => $to->id,
            'batch_number'  => 'BATCH-001',
        ])
            ->assertRedirect(route('raw-batches.index'))
            ->assertSessionHas('success');

        // Партия создана
        $batch = RawMaterialBatch::where('batch_number', 'BATCH-001')->first();
        expect($batch)->not->toBeNull();
        expect((float) $batch->initial_quantity)->toBe(10.0);
        expect($batch->current_store_id)->toBe($to->id);
        expect($batch->current_worker_id)->toBe($cutter->id);
        expect($batch->status)->toBe('active');

        // Движение записано
        $movement = RawMaterialMovement::where('batch_id', $batch->id)->first();
        expect($movement)->not->toBeNull();
        expect($movement->movement_type)->toBe('create');
        expect((float) $movement->quantity)->toBe(10.0);

        // Остатки изменены
        expect((float) ProductStock::where('product_id', $product->id)
            ->where('store_id', $from->id)->value('quantity'))->toBe(40.0);
        expect((float) ProductStock::where('product_id', $product->id)
            ->where('store_id', $to->id)->value('quantity'))->toBe(10.0);
    });

    test('отклоняет если недостаточно сырья на складе', function () {
        mockMoySklad();
        $user    = H::adminUser();
        $product = H::product();
        $from    = H::store();
        $to      = H::store('Цех');
        $cutter  = H::cutter();
        H::stock($product, $from, 5.0); // меньше чем запрошено

        $this->actingAs($user)->post(route('raw-movement.store'), [
            'product_id'    => $product->id,
            'quantity'      => 10.0,
            'worker_id'     => $cutter->id,
            'from_store_id' => $from->id,
            'to_store_id'   => $to->id,
        ])->assertSessionHasErrors('quantity');

        // Партия не создана
        expect(RawMaterialBatch::count())->toBe(0);
    });

    test('отклоняет если склад-источник пустой (нет записи в stock)', function () {
        mockMoySklad();
        $user    = H::adminUser();
        $product = H::product();
        $from    = H::store();
        $to      = H::store();
        $cutter  = H::cutter();
        // stock не создаём

        $this->actingAs($user)->post(route('raw-movement.store'), [
            'product_id'    => $product->id,
            'quantity'      => 5.0,
            'worker_id'     => $cutter->id,
            'from_store_id' => $from->id,
            'to_store_id'   => $to->id,
        ])->assertSessionHasErrors('quantity');
    });

    test('отклоняет невалидные данные — нет product_id', function () {
        $user   = H::adminUser();
        $from   = H::store();
        $to     = H::store();
        $cutter = H::cutter();

        $this->actingAs($user)->post(route('raw-movement.store'), [
            'quantity'      => 5.0,
            'worker_id'     => $cutter->id,
            'from_store_id' => $from->id,
            'to_store_id'   => $to->id,
        ])->assertSessionHasErrors('product_id');
    });

    test('отклоняет quantity = 0', function () {
        $user    = H::adminUser();
        $product = H::product();
        $from    = H::store();
        $to      = H::store();
        $cutter  = H::cutter();

        $this->actingAs($user)->post(route('raw-movement.store'), [
            'product_id'    => $product->id,
            'quantity'      => 0,
            'worker_id'     => $cutter->id,
            'from_store_id' => $from->id,
            'to_store_id'   => $to->id,
        ])->assertSessionHasErrors('quantity');
    });

    test('работает без batch_number (nullable)', function () {
        mockMoySklad();
        $user    = H::adminUser();
        $product = H::product();
        $from    = H::store();
        $to      = H::store();
        $cutter  = H::cutter();
        H::stock($product, $from, 20.0);

        $this->actingAs($user)->post(route('raw-movement.store'), [
            'product_id'    => $product->id,
            'quantity'      => 5.0,
            'worker_id'     => $cutter->id,
            'from_store_id' => $from->id,
            'to_store_id'   => $to->id,
        ])->assertRedirect(route('raw-batches.index'));

        expect(RawMaterialBatch::first()->batch_number)->toBeNull();
    });

    test('недоступен без авторизации', function () {
        $this->post(route('raw-movement.store'), [])
            ->assertRedirect('/login');
    });

    test('МойСклад ошибка не откатывает транзакцию БД', function () {
        mockMoySklad(false); // МойСклад возвращает ошибку
        $user    = H::adminUser();
        $product = H::product();
        $from    = H::store();
        $to      = H::store();
        $cutter  = H::cutter();
        H::stock($product, $from, 20.0);

        $this->actingAs($user)->post(route('raw-movement.store'), [
            'product_id'    => $product->id,
            'quantity'      => 5.0,
            'worker_id'     => $cutter->id,
            'from_store_id' => $from->id,
            'to_store_id'   => $to->id,
        ])->assertRedirect(route('raw-batches.index'));

        // Партия всё равно создана в локальной БД
        expect(RawMaterialBatch::count())->toBe(1);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// return() — возврат партии на склад
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialMovementController return()', function () {

    test('успешно возвращает партию на склад', function () {
        mockMoySklad();
        $user    = H::adminUser();
        $product = H::product();
        $cutter  = H::cutter();
        $fromStore = H::store('Цех');
        $toStore   = H::store('Главный склад');
        $batch   = H::batch($product, $fromStore, $cutter, 15.0);
        H::stock($product, $fromStore, 15.0);

        $this->actingAs($user)
            ->post(route('raw-batches.return', $batch), [
                'to_store_id' => $toStore->id,
            ])
            ->assertRedirect(route('raw-batches.show', $batch))
            ->assertSessionHas('success');

        $batch->refresh();
        expect($batch->status)->toBe('returned');
        expect($batch->current_store_id)->toBe($toStore->id);
        expect($batch->current_worker_id)->toBeNull();

        // Движение записано
        $movement = RawMaterialMovement::where('batch_id', $batch->id)
            ->where('movement_type', 'return_to_store')
            ->first();
        expect($movement)->not->toBeNull();
        expect((float) $movement->quantity)->toBe(15.0);

        // Остатки обновлены
        expect((float) ProductStock::where('product_id', $product->id)
            ->where('store_id', $fromStore->id)->value('quantity'))->toBe(0.0);
        expect((float) ProductStock::where('product_id', $product->id)
            ->where('store_id', $toStore->id)->value('quantity'))->toBe(15.0);
    });

    test('нельзя вернуть неактивную партию', function () {
        mockMoySklad();
        $user    = H::adminUser();
        $product = H::product();
        $cutter  = H::cutter();
        $store   = H::store();
        $toStore = H::store();
        $batch   = H::batch($product, $store, $cutter, 10.0, ['status' => 'used']);

        $this->actingAs($user)
            ->post(route('raw-batches.return', $batch), [
                'to_store_id' => $toStore->id,
            ])
            ->assertSessionHasErrors('batch');

        $batch->refresh();
        expect($batch->status)->toBe('used');
    });

    test('нельзя вернуть партию без работника (уже на складе)', function () {
        mockMoySklad();
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $toStore = H::store();
        // batch без current_worker_id
        $batch = RawMaterialBatch::create([
            'product_id'         => $product->id,
            'initial_quantity'   => 10.0,
            'remaining_quantity' => 10.0,
            'current_store_id'   => $store->id,
            'current_worker_id'  => null,
            'status'             => 'active',
        ]);

        $this->actingAs($user)
            ->post(route('raw-batches.return', $batch), [
                'to_store_id' => $toStore->id,
            ])
            ->assertSessionHasErrors('batch');
    });

    test('требует to_store_id', function () {
        mockMoySklad();
        $user    = H::adminUser();
        $product = H::product();
        $cutter  = H::cutter();
        $store   = H::store();
        $batch   = H::batch($product, $store, $cutter, 10.0);

        $this->actingAs($user)
            ->post(route('raw-batches.return', $batch), [])
            ->assertSessionHasErrors('to_store_id');
    });

    test('недоступен без авторизации', function () {
        $product = H::product();
        $cutter  = H::cutter();
        $store   = H::store();
        $batch   = H::batch($product, $store, $cutter, 10.0);

        $this->post(route('raw-batches.return', $batch), [
            'to_store_id' => $store->id,
        ])->assertRedirect('/login');
    });

    test('МойСклад ошибка не откатывает возврат в БД', function () {
        mockMoySklad(false);
        $user    = H::adminUser();
        $product = H::product();
        $cutter  = H::cutter();
        $fromStore = H::store();
        $toStore   = H::store();
        $batch   = H::batch($product, $fromStore, $cutter, 8.0);
        H::stock($product, $fromStore, 8.0);

        $this->actingAs($user)
            ->post(route('raw-batches.return', $batch), [
                'to_store_id' => $toStore->id,
            ])
            ->assertRedirect(route('raw-batches.show', $batch));

        // Возврат всё равно записан в БД
        $batch->refresh();
        expect($batch->status)->toBe('returned');
    });
});
