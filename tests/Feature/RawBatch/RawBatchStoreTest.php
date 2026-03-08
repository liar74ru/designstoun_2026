<?php

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\RawMaterialBatch;
use App\Models\RawMaterialMovement;
use App\Models\Store;
use App\Models\User;
use App\Models\Worker;
use Tests\Helpers\ReceptionTestHelper as H;

// ══════════════════════════════════════════════════════════════════════════════
// ШАГ 1: Создание партии сырья — RawMaterialBatchController::store()
// ══════════════════════════════════════════════════════════════════════════════

describe('Создание партии [store()]', function () {

    test('успешно создаёт партию и уменьшает остаток на складе-источнике', function () {
        $user      = H::adminUser();
        $product   = H::product();
        $fromStore = H::store('Главный склад');
        $toStore   = H::store('Цех');
        $worker    = H::cutter();

        H::stock($product, $fromStore, 50.0);

        $this->actingAs($user)->post('/raw-batches', [
            'product_id'    => $product->id,
            'quantity'      => 10.0,
            'worker_id'     => $worker->id,
            'from_store_id' => $fromStore->id,
            'to_store_id'   => $toStore->id,
            'batch_number'  => '26-12-Тест-01',
        ])->assertRedirect(route('raw-batches.index'));

        // Партия создана
        $batch = RawMaterialBatch::where('product_id', $product->id)->first();
        expect($batch)->not->toBeNull();
        expect((float) $batch->remaining_quantity)->toBe(10.0);
        expect($batch->status)->toBe('active');
        expect($batch->batch_number)->toBe('26-12-Тест-01');

        // Остатки обновлены
        expect((float) ProductStock::where('product_id', $product->id)
            ->where('store_id', $fromStore->id)->value('quantity'))->toBe(40.0);
        expect((float) ProductStock::where('product_id', $product->id)
            ->where('store_id', $toStore->id)->value('quantity'))->toBe(10.0);

        // Движение записано
        expect(RawMaterialMovement::where('batch_id', $batch->id)
            ->where('movement_type', 'create')->exists())->toBeTrue();
    });

    test('отклоняет создание если недостаточно сырья на складе-источнике', function () {
        $user      = H::adminUser();
        $product   = H::product();
        $fromStore = H::store();
        $toStore   = H::store();
        $worker    = H::cutter();

        H::stock($product, $fromStore, 3.0); // только 3, запрашиваем 10

        $this->actingAs($user)->post('/raw-batches', [
            'product_id'    => $product->id,
            'quantity'      => 10.0,
            'worker_id'     => $worker->id,
            'from_store_id' => $fromStore->id,
            'to_store_id'   => $toStore->id,
        ])->assertSessionHasErrors('quantity');

        expect(RawMaterialBatch::count())->toBe(0);
    });

    test('отклоняет без авторизации', function () {
        $this->post('/raw-batches', [])->assertRedirect('/login');
    });

    test('требует обязательные поля', function () {
        $user = H::adminUser();
        $this->actingAs($user)->post('/raw-batches', [])
            ->assertSessionHasErrors(['product_id', 'quantity', 'worker_id', 'from_store_id', 'to_store_id']);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Корректировка партии — RawMaterialBatchController::adjust()
// ══════════════════════════════════════════════════════════════════════════════

describe('Корректировка партии [adjust()]', function () {

    test('добавление: увеличивает remaining_quantity и product_stocks', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $worker  = H::cutter();
        $batch   = H::batch($product, $store, $worker, 10.0);

        H::stock($product, $store, 10.0);

        $this->actingAs($user)
            ->post(route('raw-batches.adjust', $batch), ['delta' => 5.0])
            ->assertRedirect(route('raw-batches.show', $batch));

        $batch->refresh();
        expect((float) $batch->remaining_quantity)->toBe(15.0);
        expect($batch->status)->toBe('active');

        expect((float) ProductStock::where('product_id', $product->id)
            ->where('store_id', $store->id)->value('quantity'))->toBe(15.0);
    });

    test('списание: уменьшает remaining_quantity', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $worker  = H::cutter();
        $batch   = H::batch($product, $store, $worker, 10.0);

        H::stock($product, $store, 10.0);

        $this->actingAs($user)
            ->post(route('raw-batches.adjust', $batch), ['delta' => -4.0])
            ->assertRedirect(route('raw-batches.show', $batch));

        $batch->refresh();
        expect((float) $batch->remaining_quantity)->toBe(6.0);
    });

    test('при списании до нуля меняет статус на used', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $worker  = H::cutter();
        $batch   = H::batch($product, $store, $worker, 5.0);

        H::stock($product, $store, 5.0);

        $this->actingAs($user)
            ->post(route('raw-batches.adjust', $batch), ['delta' => -5.0]);

        $batch->refresh();
        expect($batch->status)->toBe('used');
        expect((float) $batch->remaining_quantity)->toBe(0.0);
    });

    test('нельзя убрать больше чем есть в партии', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $worker  = H::cutter();
        $batch   = H::batch($product, $store, $worker, 3.0);

        $this->actingAs($user)
            ->post(route('raw-batches.adjust', $batch), ['delta' => -10.0])
            ->assertSessionHasErrors('delta');

        $batch->refresh();
        expect((float) $batch->remaining_quantity)->toBe(3.0);
    });

    test('нельзя передать delta=0', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $worker  = H::cutter();
        $batch   = H::batch($product, $store, $worker, 10.0);

        $this->actingAs($user)
            ->post(route('raw-batches.adjust', $batch), ['delta' => 0])
            ->assertSessionHasErrors('delta');
    });

    test('архивную партию нельзя корректировать', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $worker  = H::cutter();
        $batch   = H::batch($product, $store, $worker, 0.0, ['status' => 'archived']);

        $this->actingAs($user)
            ->post(route('raw-batches.adjust', $batch), ['delta' => 5.0])
            ->assertSessionHas('error');
    });

    test('пишет запись в raw_material_movements', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $worker  = H::cutter();
        $batch   = H::batch($product, $store, $worker, 10.0);

        H::stock($product, $store, 10.0);

        $this->actingAs($user)
            ->post(route('raw-batches.adjust', $batch), ['delta' => 3.0]);

        expect(RawMaterialMovement::where('batch_id', $batch->id)->count())->toBe(1);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Архивирование партии
// ══════════════════════════════════════════════════════════════════════════════

describe('Архивирование партии [archive()]', function () {

    test('успешно архивирует партию со статусом used и нулевым остатком', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $worker  = H::cutter();
        $batch   = H::batch($product, $store, $worker, 0.0, ['status' => 'used']);

        $this->actingAs($user)
            ->post(route('raw-batches.archive', $batch))
            ->assertRedirect(route('raw-batches.show', $batch));

        $batch->refresh();
        expect($batch->status)->toBe('archived');
    });

    test('нельзя архивировать активную партию', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $worker  = H::cutter();
        $batch   = H::batch($product, $store, $worker, 10.0);

        $this->actingAs($user)
            ->post(route('raw-batches.archive', $batch))
            ->assertSessionHas('error');

        $batch->refresh();
        expect($batch->status)->toBe('active');
    });

    test('нельзя архивировать партию с ненулевым остатком', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $worker  = H::cutter();
        $batch   = H::batch($product, $store, $worker, 2.5, ['status' => 'used']);

        $this->actingAs($user)
            ->post(route('raw-batches.archive', $batch))
            ->assertSessionHas('error');
    });
});
