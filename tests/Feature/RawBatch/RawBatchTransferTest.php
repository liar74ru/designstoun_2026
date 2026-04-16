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
        $batch     = H::batch($product, $store, $cutter1, 20.0, ['status' => 'confirmed']);

        $this->actingAs($user)
            ->post(route('raw-batches.transfer', $batch), [
                'to_worker_id' => $cutter2->id,
                'quantity'     => 8.0,
            ])->assertRedirect(route('raw-batches.show', $batch));

        // Родительская партия: уменьшились initial и remaining
        $batch->refresh();
        expect((float) $batch->initial_quantity)->toBe(12.0);
        expect((float) $batch->remaining_quantity)->toBe(12.0);

        // Новая партия создана для получателя
        $newBatch = \App\Models\RawMaterialBatch::where('current_worker_id', $cutter2->id)->first();
        expect($newBatch)->not->toBeNull();
        expect((float) $newBatch->initial_quantity)->toBe(8.0);
        expect((float) $newBatch->remaining_quantity)->toBe(8.0);

        // Движение записано на новую партию с типом 'create' (как у обычной партии)
        $movement = RawMaterialMovement::where('batch_id', $newBatch->id)
            ->where('movement_type', 'create')
            ->first();
        expect($movement)->not->toBeNull();
        expect($movement->from_worker_id)->toBe($cutter1->id);
        expect($movement->to_worker_id)->toBe($cutter2->id);
    });

    test('успешно передаёт партию со статусом new', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $cutter1 = H::cutter('Пильщик Раз');
        $cutter2 = H::cutter('Пильщик Два');
        $batch   = H::newBatch($product, $store, $cutter1, 20.0);

        $this->actingAs($user)
            ->post(route('raw-batches.transfer', $batch), [
                'to_worker_id' => $cutter2->id,
                'quantity'     => 5.0,
            ])->assertRedirect(route('raw-batches.show', $batch));

        $batch->refresh();
        expect((float) $batch->initial_quantity)->toBe(15.0);
        expect((float) $batch->remaining_quantity)->toBe(15.0);
        expect($batch->status)->toBe('new');
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
        $batch   = H::batch($product, $store, $cutter, 10.0, ['status' => 'confirmed']);

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
        $batch      = H::batch($product, $fromStore, $cutter, 10.0, ['status' => 'confirmed']);

        // Создаём остатки на складе цеха
        H::stock($product, $fromStore, 10.0);

        $this->actingAs($user)
            ->post(route('raw-batches.return', $batch), [
                'to_store_id' => $toStore->id,
                'quantity'    => 10.0,
            ])->assertRedirect(route('raw-batches.show', $batch));

        // Родительская партия: остаток = 0, статус = in_work
        $batch->refresh();
        expect((float) $batch->remaining_quantity)->toBe(0.0);
        expect($batch->status)->toBe('in_work');

        // Новая партия создана: статус returned, на складе-назначении
        $returnedBatch = \App\Models\RawMaterialBatch::where('status', 'returned')
            ->where('current_store_id', $toStore->id)
            ->first();
        expect($returnedBatch)->not->toBeNull();
        expect((float) $returnedBatch->remaining_quantity)->toBe(10.0);
        expect($returnedBatch->current_worker_id)->toBeNull();

        // Остатки пересчитаны
        expect((float) ProductStock::where('product_id', $product->id)
            ->where('store_id', $fromStore->id)->value('quantity'))->toBe(0.0);
        expect((float) ProductStock::where('product_id', $product->id)
            ->where('store_id', $toStore->id)->value('quantity'))->toBe(10.0);

        // Движение записано на новую партию
        expect(RawMaterialMovement::where('batch_id', $returnedBatch->id)
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

    test('редиректит на create с query-параметрами продукта и работника', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::batch($product, $store, $cutter, 15.0);

        $response = $this->actingAs($user)
            ->get(route('raw-batches.copy', $batch));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $location = $response->headers->get('Location');
        expect($location)->toContain('copy_worker=' . $batch->current_worker_id);
        expect($location)->toContain('copy_product=' . $batch->product_id);
        expect($location)->toContain('copy_to_store=' . $batch->current_store_id);
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
