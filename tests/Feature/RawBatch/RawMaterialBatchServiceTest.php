<?php

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\RawMaterialBatch;
use App\Models\RawMaterialMovement;
use App\Models\Store;
use App\Models\Worker;
use App\Services\RawMaterialBatchService;

// ══════════════════════════════════════════════════════════════════════════════
// RawMaterialBatchService — create()
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchService::create()', function () {

    test('создаёт партию с движением', function () {
        $product = Product::factory()->create();
        $storeFrom = Store::factory()->create();
        $storeTo = Store::factory()->create();
        $worker = Worker::create(['name' => 'Пильщик', 'positions' => ['Пильщик']]);
        ProductStock::create([
            'product_id' => $product->id,
            'store_id' => $storeFrom->id,
            'quantity' => 100.0,
        ]);

        $service = new RawMaterialBatchService();
        $result = $service->create([
            'product_id' => $product->id,
            'quantity' => 10.0,
            'worker_id' => $worker->id,
            'from_store_id' => $storeFrom->id,
            'to_store_id' => $storeTo->id,
            'batch_number' => 'TEST-001',
        ], false);

        expect($result['batch'])->not->toBeNull();
        expect((float) $result['batch']->initial_quantity)->toBe(10.0);
        expect($result['movement'])->not->toBeNull();
        expect($result['movement']->movement_type)->toBe('create');
    });

    test('корректирует остатки при создании', function () {
        $product = Product::factory()->create();
        $storeFrom = Store::factory()->create();
        $storeTo = Store::factory()->create();
        $worker = Worker::create(['name' => 'Пильщик', 'positions' => ['Пильщик']]);
        ProductStock::create([
            'product_id' => $product->id,
            'store_id' => $storeFrom->id,
            'quantity' => 100.0,
        ]);

        $service = new RawMaterialBatchService();
        $service->create([
            'product_id' => $product->id,
            'quantity' => 10.0,
            'worker_id' => $worker->id,
            'from_store_id' => $storeFrom->id,
            'to_store_id' => $storeTo->id,
        ], false);

        $fromStock = ProductStock::where('product_id', $product->id)->where('store_id', $storeFrom->id)->first();
        $toStock = ProductStock::where('product_id', $product->id)->where('store_id', $storeTo->id)->first();

        expect((float) $fromStock->quantity)->toBe(90.0);
        expect((float) $toStock->quantity)->toBe(10.0);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// RawMaterialBatchService — update()
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchService::update()', function () {

    test('обновляет количество партии', function () {
        $product = Product::factory()->create();
        $store = Store::factory()->create();
        $worker = Worker::create(['name' => 'Пильщик', 'positions' => ['Пильщик']]);

        $batch = RawMaterialBatch::create([
            'product_id' => $product->id,
            'initial_quantity' => 50.0,
            'remaining_quantity' => 50.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $worker->id,
            'status' => RawMaterialBatch::STATUS_NEW,
        ]);
        ProductStock::create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 50.0,
        ]);

        $service = new RawMaterialBatchService();
        $result = $service->update($batch, [
            'product_id' => $product->id,
            'quantity' => 30.0,
        ], false);

        expect($result)->not->toBeNull();
        $batch->refresh();
        expect((float) $batch->initial_quantity)->toBe(30.0);
    });

    test('возвращает null если изменений нет', function () {
        $product = Product::factory()->create();
        $store = Store::factory()->create();
        $worker = Worker::create(['name' => 'Пильщик', 'positions' => ['Пильщик']]);

        $batch = RawMaterialBatch::create([
            'product_id' => $product->id,
            'initial_quantity' => 50.0,
            'remaining_quantity' => 50.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $worker->id,
            'status' => RawMaterialBatch::STATUS_NEW,
        ]);

        $service = new RawMaterialBatchService();
        $result = $service->update($batch, [
            'product_id' => $product->id,
            'quantity' => 50.0,
        ], false);

        expect($result)->toBeNull();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// RawMaterialBatchService — adjust()
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchService::adjust()', function () {

    test('увеличивает количество партии', function () {
        $product = Product::factory()->create();
        $store = Store::factory()->create();
        $worker = Worker::create(['name' => 'Пильщик', 'positions' => ['Пильщик']]);

        $batch = RawMaterialBatch::create([
            'product_id' => $product->id,
            'initial_quantity' => 50.0,
            'remaining_quantity' => 50.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $worker->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $service = new RawMaterialBatchService();
        $result = $service->adjust($batch, 10.0, 'Тестовая корректировка', false, null);

        $batch->refresh();
        expect((float) $batch->remaining_quantity)->toBe(60.0);
        expect((float) $batch->initial_quantity)->toBe(60.0);
    });

    test('уменьшает количество партии', function () {
        $product = Product::factory()->create();
        $store = Store::factory()->create();
        $worker = Worker::create(['name' => 'Пильщик', 'positions' => ['Пильщик']]);

        $batch = RawMaterialBatch::create([
            'product_id' => $product->id,
            'initial_quantity' => 50.0,
            'remaining_quantity' => 50.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $worker->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $service = new RawMaterialBatchService();
        $result = $service->adjust($batch, -5.0, 'Списание', false, null);

        $batch->refresh();
        expect((float) $batch->remaining_quantity)->toBe(45.0);
    });
});

// ═════════════════════��════════════════════════════════════════════════════════
// RawMaterialBatchService — deleteNew()
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchService::deleteNew()', function () {

    test('удаляет новую партию и возвращает moysklad_move_id', function () {
        $product = Product::factory()->create();
        $store = Store::factory()->create();
        $worker = Worker::create(['name' => 'Пильщик', 'positions' => ['Пильщик']]);

        $batch = RawMaterialBatch::create([
            'product_id' => $product->id,
            'initial_quantity' => 50.0,
            'remaining_quantity' => 50.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $worker->id,
            'status' => RawMaterialBatch::STATUS_NEW,
        ]);
        ProductStock::create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 50.0,
        ]);

        $movement = RawMaterialMovement::create([
            'batch_id' => $batch->id,
            'from_store_id' => $store->id,
            'to_store_id' => $store->id,
            'to_worker_id' => $worker->id,
            'movement_type' => 'create',
            'quantity' => 50.0,
            'moysklad_move_id' => 'ms-move-123',
        ]);

        $service = new RawMaterialBatchService();
        $moyskladMoveId = $service->deleteNew($batch);

        expect($moyskladMoveId)->toBe('ms-move-123');
        expect(RawMaterialBatch::find($batch->id))->toBeNull();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// RawMaterialBatchService — markAsUsed()
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchService::markAsUsed()', function () {

    test('переводит партию в статус used', function () {
        $product = Product::factory()->create();
        $store = Store::factory()->create();
        $worker = Worker::create(['name' => 'Пильщик', 'positions' => ['Пильщик']]);

        $batch = RawMaterialBatch::create([
            'product_id' => $product->id,
            'initial_quantity' => 50.0,
            'remaining_quantity' => 0.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $worker->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $service = new RawMaterialBatchService();
        $service->markAsUsed($batch);

        $batch->refresh();
        expect($batch->status)->toBe(RawMaterialBatch::STATUS_USED);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// RawMaterialBatchService — markAsInWork()
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchService::markAsInWork()', function () {

    test('возвращает партию из статуса used в in_work', function () {
        $product = Product::factory()->create();
        $store = Store::factory()->create();
        $worker = Worker::create(['name' => 'Пильщик', 'positions' => ['Пильщик']]);

        $batch = RawMaterialBatch::create([
            'product_id' => $product->id,
            'initial_quantity' => 50.0,
            'remaining_quantity' => 0.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $worker->id,
            'status' => RawMaterialBatch::STATUS_USED,
        ]);

        $service = new RawMaterialBatchService();
        $service->markAsInWork($batch);

        $batch->refresh();
        expect($batch->status)->toBe(RawMaterialBatch::STATUS_IN_WORK);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// RawMaterialBatchService — archive()
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchService::archive()', function () {

    test('архивирует партию', function () {
        $product = Product::factory()->create();
        $store = Store::factory()->create();
        $worker = Worker::create(['name' => 'Пильщик', 'positions' => ['Пильщик']]);

        $batch = RawMaterialBatch::create([
            'product_id' => $product->id,
            'initial_quantity' => 50.0,
            'remaining_quantity' => 0.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $worker->id,
            'status' => RawMaterialBatch::STATUS_USED,
        ]);

        $service = new RawMaterialBatchService();
        $service->archive($batch);

        $batch->refresh();
        expect($batch->status)->toBe('archived');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// RawMaterialBatchService — transfer()
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchService::transfer()', function () {

    test('передаёт часть партии другому пильщику', function () {
        $product = Product::factory()->create();
        $store = Store::factory()->create();
        $cutter1 = Worker::create(['name' => 'Пильщик 1', 'positions' => ['Пильщик']]);
        $cutter2 = Worker::create(['name' => 'Пильщик 2', 'positions' => ['Пильщик']]);

        $batch = RawMaterialBatch::create([
            'product_id' => $product->id,
            'initial_quantity' => 50.0,
            'remaining_quantity' => 50.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter1->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $service = new RawMaterialBatchService();
        $result = $service->transfer($batch, [
            'to_worker_id' => $cutter2->id,
            'quantity' => 20.0,
        ]);

        expect($result['newBatch'])->not->toBeNull();
        expect($result['newBatch']->current_worker_id)->toBe($cutter2->id);
        expect((float) $result['newBatch']->initial_quantity)->toBe(20.0);

        $batch->refresh();
        expect((float) $batch->remaining_quantity)->toBe(30.0);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// RawMaterialBatchService — returnToStore()
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchService::returnToStore()', function () {

    test('возвращает часть партии на склад', function () {
        $product = Product::factory()->create();
        $store = Store::factory()->create();
        $store2 = Store::factory()->create();
        $cutter = Worker::create(['name' => 'Пильщик', 'positions' => ['Пильщик']]);

        $batch = RawMaterialBatch::create([
            'product_id' => $product->id,
            'initial_quantity' => 50.0,
            'remaining_quantity' => 50.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $service = new RawMaterialBatchService();
        $result = $service->returnToStore($batch, [
            'to_store_id' => $store2->id,
            'quantity' => 15.0,
        ]);

        expect($result['newBatch'])->not->toBeNull();
        expect($result['newBatch']->status)->toBe(RawMaterialBatch::STATUS_RETURNED);

        $batch->refresh();
        expect((float) $batch->remaining_quantity)->toBe(35.0);
});
});