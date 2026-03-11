<?php

use App\Models\ProductStock;
use App\Models\RawMaterialBatch;
use App\Models\RawMaterialMovement;
use Tests\Helpers\ReceptionTestHelper as H;

// ══════════════════════════════════════════════════════════════════════════════
// Передача партии другому пильщику — transfer()
// ══════════════════════════════════════════════════════════════════════════════

describe('Передача партии пильщику [transfer()]', function () {

    test('успешно передаёт партию другому пильщику', function () {
        $user      = H::adminUser();
        $product   = H::product();
        $store     = H::store();
        $cutter1   = H::cutter('Пильщик Первый');
        $cutter2   = H::cutter('Пильщик Второй');
        $batch     = H::batch($product, $store, $cutter1, 20.0);

        $this->actingAs($user)
            ->post(route('raw-batches.transfer', $batch), [
                'to_worker_id' => $cutter2->id,
            ])->assertRedirect(route('raw-batches.show', $batch));

        $batch->refresh();
        expect($batch->current_worker_id)->toBe($cutter2->id);

        // Движение записано
        $movement = RawMaterialMovement::where('batch_id', $batch->id)
            ->where('movement_type', 'transfer_to_worker')
            ->first();
        expect($movement)->not->toBeNull();
        expect($movement->from_worker_id)->toBe($cutter1->id);
        expect($movement->to_worker_id)->toBe($cutter2->id);
    });

    test('нельзя передать неактивную партию', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $cutter1 = H::cutter('Пильщик А');
        $cutter2 = H::cutter('Пильщик Б');
        $batch   = H::batch($product, $store, $cutter1, 0.0, ['status' => 'used']);

        $this->actingAs($user)
            ->post(route('raw-batches.transfer', $batch), [
                'to_worker_id' => $cutter2->id,
            ])->assertSessionHas('error');

        $batch->refresh();
        expect($batch->current_worker_id)->toBe($cutter1->id);
    });

    test('требует to_worker_id', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::batch($product, $store, $cutter, 10.0);

        $this->actingAs($user)
            ->post(route('raw-batches.transfer', $batch), [])
            ->assertSessionHasErrors('to_worker_id');
    });

    test('форма передачи недоступна для неактивной партии', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::batch($product, $store, $cutter, 0.0, ['status' => 'used']);

        $this->actingAs($user)
            ->get(route('raw-batches.transfer.form', $batch))
            ->assertRedirect(route('raw-batches.show', $batch));
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Возврат партии на склад — RawMaterialMovementController::return()
// ══════════════════════════════════════════════════════════════════════════════

describe('Возврат партии на склад [return()]', function () {

    test('успешно возвращает партию на склад', function () {
        $user       = H::adminUser();
        $product    = H::product();
        $fromStore  = H::store('Цех');
        $toStore    = H::store('Главный склад');
        $cutter     = H::cutter();
        $batch      = H::batch($product, $fromStore, $cutter, 10.0);

        // Создаём остатки на складе цеха
        H::stock($product, $fromStore, 10.0);

        $this->actingAs($user)
            ->post(route('raw-batches.return', $batch), [
                'to_store_id' => $toStore->id,
            ])->assertRedirect(route('raw-batches.show', $batch));

        $batch->refresh();
        expect($batch->status)->toBe('returned');
        expect($batch->current_worker_id)->toBeNull();
        expect($batch->current_store_id)->toBe($toStore->id);

        // Остатки пересчитаны
        expect((float) ProductStock::where('product_id', $product->id)
            ->where('store_id', $fromStore->id)->value('quantity'))->toBe(0.0);
        expect((float) ProductStock::where('product_id', $product->id)
            ->where('store_id', $toStore->id)->value('quantity'))->toBe(10.0);

        // Движение записано
        expect(RawMaterialMovement::where('batch_id', $batch->id)
            ->where('movement_type', 'return_to_store')->exists())->toBeTrue();
    });

    test('нельзя вернуть неактивную партию', function () {
        $user      = H::adminUser();
        $product   = H::product();
        $store     = H::store();
        $toStore   = H::store();
        $cutter    = H::cutter();
        $batch     = H::batch($product, $store, $cutter, 10.0, ['status' => 'returned']);

        $this->actingAs($user)
            ->post(route('raw-batches.return', $batch), [
                'to_store_id' => $toStore->id,
            ])->assertSessionHasErrors('batch');
    });

    test('нельзя вернуть партию которая уже на складе (нет current_worker_id)', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $toStore = H::store();
        // current_worker_id = null — уже на складе
        $batch   = RawMaterialBatch::create([
            'product_id'         => $product->id,
            'initial_quantity'   => 10.0,
            'remaining_quantity' => 10.0,
            'current_store_id'   => $store->id,
            'current_worker_id'  => null,
            'status'             => 'in_work',
        ]);

        $this->actingAs($user)
            ->post(route('raw-batches.return', $batch), [
                'to_store_id' => $toStore->id,
            ])->assertSessionHasErrors('batch');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Копирование партии — copy()
// ══════════════════════════════════════════════════════════════════════════════

describe('Копирование партии [copy()]', function () {

    test('кладёт copy_from в сессию и редиректит на create', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::batch($product, $store, $cutter, 15.0);

        $this->actingAs($user)
            ->get(route('raw-batches.copy', $batch))
            ->assertRedirect(route('raw-batches.create'))
            ->assertSessionHas('copy_from');

        $copyFrom = session('copy_from');
        expect($copyFrom['product_id'])->toBe($product->id);
        expect($copyFrom['worker_id'])->toBe($cutter->id);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Удаление партии — destroy()
// ══════════════════════════════════════════════════════════════════════════════

describe('Удаление партии [destroy()]', function () {

    test('успешно удаляет партию без приёмок', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::batch($product, $store, $cutter, 5.0);
        H::stock($product, $store, 5.0);

        $this->actingAs($user)
            ->delete(route('raw-batches.destroy', $batch))
            ->assertRedirect(route('raw-batches.index'));

        expect(RawMaterialBatch::find($batch->id))->toBeNull();
    });

    test('нельзя удалить партию к которой есть приёмки', function () {
        $user      = H::adminUser();
        $product   = H::product();
        $store     = H::store();
        $receiver  = H::worker();
        $cutter    = H::cutter();
        $batch     = H::batch($product, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0);

        $this->actingAs($user)
            ->delete(route('raw-batches.destroy', $batch))
            ->assertSessionHas('error');

        expect(RawMaterialBatch::find($batch->id))->not->toBeNull();
    });
});
