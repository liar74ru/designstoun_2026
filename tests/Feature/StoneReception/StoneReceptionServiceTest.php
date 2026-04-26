<?php

use App\Models\Product;
use App\Models\RawMaterialBatch;
use App\Models\ReceptionLog;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use App\Models\Store;
use App\Models\Worker;
use App\Services\StoneReceptionService;
use App\Services\Moysklad\StoneReceptionSyncService;

// ══════════════════════════════════════════════════════════════════════════════
// StoneReceptionService — getFilterData()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionService::getFilterData()', function () {

    test('возвращает данные для фильтров', function () {
        $rawProduct = Product::factory()->create(['name' => 'Гранит']);
        $product = Product::factory()->create(['name' => 'Плитка']);
        $store = Store::factory()->create();
        $worker = Worker::create(['name' => 'Пильщик', 'positions' => ['Пильщик']]);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $worker->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'positions' => ['Приёмщик']]);
        StoneReception::create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $worker->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 5.0,
            'status' => StoneReception::STATUS_ACTIVE,
        ]);

        $mockSync = \Mockery::mock(StoneReceptionSyncService::class);
        $mockSync->shouldReceive('syncReception')->andReturn(null);
        
        $service = new StoneReceptionService($mockSync);
        $result = $service->getFilterData();

        expect($result['filterRawProducts'])->not->toBeNull();
        expect($result['filterProducts'])->not->toBeNull();
        expect($result['filterCutters'])->not->toBeNull();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// StoneReceptionService — create()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionService::create()', function () {

    test('создаёт приёмку с позициями', function () {
        $rawProduct = Product::factory()->create(['name' => 'Гранит']);
        $product = Product::factory()->create(['name' => 'Плитка']);
        $store = Store::factory()->create();
        $cutter = Worker::create(['name' => 'Пильщик', 'positions' => ['Пильщик']]);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'positions' => ['Приёмщик']]);

        $mockSync = \Mockery::mock(StoneReceptionSyncService::class);
        $mockSync->shouldReceive('syncReception')->andReturn(null);
        
        $service = new StoneReceptionService($mockSync);
        $reception = $service->create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 5.0,
            'products' => [
                ['product_id' => $product->id, 'quantity' => 2.0],
            ],
        ], false);

        expect($reception)->not->toBeNull();
        expect($reception->status ?? 'null')->not->toBeNull();
    });

    test('закрывает активную приёмку при создании новой', function () {
        $rawProduct = Product::factory()->create(['name' => 'Гранит']);
        $product = Product::factory()->create(['name' => 'Плитка']);
        $store = Store::factory()->create();
        $cutter = Worker::create(['name' => 'Пильщик', 'positions' => ['Пильщик']]);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'positions' => ['Приёмщик']]);
        StoneReception::create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 5.0,
            'status' => StoneReception::STATUS_ACTIVE,
        ]);

        $mockSync = \Mockery::mock(StoneReceptionSyncService::class);
        $mockSync->shouldReceive('syncReception')->andReturn(null);
        
        $service = new StoneReceptionService($mockSync);
        $service->create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 3.0,
            'products' => [
                ['product_id' => $product->id, 'quantity' => 1.0],
            ],
        ], false);

        $batch->refresh();
        expect($batch->status)->toBe(RawMaterialBatch::STATUS_CONFIRMED);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// StoneReceptionService — update()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionService::update()', function () {

    test('обновляет приёмку', function () {
        $rawProduct = Product::factory()->create(['name' => 'Гранит']);
        $product = Product::factory()->create(['name' => 'Плитка']);
        $store = Store::factory()->create();
        $cutter = Worker::create(['name' => 'Пильщик', 'positions' => ['Пильщик']]);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 95.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'positions' => ['Приёмщик']]);
        $reception = StoneReception::create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 5.0,
            'status' => StoneReception::STATUS_ACTIVE,
        ]);
        StoneReceptionItem::create([
            'stone_reception_id' => $reception->id,
            'product_id' => $product->id,
            'quantity' => 2.0,
        ]);

        $mockSync = \Mockery::mock(StoneReceptionSyncService::class);
        $mockSync->shouldReceive('syncReception')->andReturn(null);
        
        $service = new StoneReceptionService($mockSync);
        $service->update($reception, [
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 5.0,
            'raw_quantity_delta' => 0,
            'products' => [
                ['product_id' => $product->id, 'quantity' => 4.0],
            ],
        ], false);

        $reception->refresh();
        expect((float) $reception->items->first()->quantity)->toBe(4.0);
    });

    test('не пишет лог если ничего не изменилось', function () {
        $rawProduct = Product::factory()->create(['name' => 'Гранит']);
        $product = Product::factory()->create(['name' => 'Плитка']);
        $store = Store::factory()->create();
        $cutter = Worker::create(['name' => 'Пильщик', 'positions' => ['Пильщик']]);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 95.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'positions' => ['Приёмщик']]);
        $reception = StoneReception::create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 5.0,
            'status' => StoneReception::STATUS_ACTIVE,
        ]);
        StoneReceptionItem::create([
            'stone_reception_id' => $reception->id,
            'product_id' => $product->id,
            'quantity' => 2.0,
        ]);

        $mockSync = \Mockery::mock(StoneReceptionSyncService::class);
        $mockSync->shouldReceive('syncReception')->andReturn(null);
        
        $service = new StoneReceptionService($mockSync);
        $service->update($reception, [
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 5.0,
            'raw_quantity_delta' => 0,
            'products' => [
                ['product_id' => $product->id, 'quantity' => 2.0],
            ],
        ], false);

        expect(ReceptionLog::where('stone_reception_id', $reception->id)->count())->toBe(0);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// StoneReceptionService — delete()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionService::delete()', function () {

    test('удаляет приёмку', function () {
        $rawProduct = Product::factory()->create(['name' => 'Гранит']);
        $product = Product::factory()->create(['name' => 'Плитка']);
        $store = Store::factory()->create();
        $cutter = Worker::create(['name' => 'Пильщик', 'positions' => ['Пильщик']]);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'positions' => ['Приёмщик']]);
        $reception = StoneReception::create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 5.0,
            'status' => StoneReception::STATUS_ACTIVE,
        ]);

        $mockSync = \Mockery::mock(StoneReceptionSyncService::class);
        
        $service = new StoneReceptionService($mockSync);
        $service->delete($reception);

        expect(StoneReception::find($reception->id))->toBeNull();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// StoneReceptionService — closeBatch()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionService::closeBatch()', function () {

    test('закрывает партию и завершает активные приёмки', function () {
        $rawProduct = Product::factory()->create(['name' => 'Гранит']);
        $product = Product::factory()->create(['name' => 'Плитка']);
        $store = Store::factory()->create();
        $cutter = Worker::create(['name' => 'Пильщик', 'positions' => ['Пильщик']]);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 0.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'positions' => ['Приёмщик']]);
        $reception = StoneReception::create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 100.0,
            'status' => StoneReception::STATUS_ACTIVE,
        ]);

        $mockSync = \Mockery::mock(StoneReceptionSyncService::class);
        $mockSync->shouldReceive('completeProcessing')->andReturn(['success' => false, 'message' => 'no processing']);
        
        $service = new StoneReceptionService($mockSync);
        $result = $service->closeBatch($batch);

        expect($result)->toBeTrue();
        $batch->refresh();
        expect($batch->status)->toBe(RawMaterialBatch::STATUS_USED);

        $reception->refresh();
        expect($reception->status)->toBe(StoneReception::STATUS_COMPLETED);
    });

    test('не закрывает если партия не в работе', function () {
        $rawProduct = Product::factory()->create(['name' => 'Гранит']);
        $store = Store::factory()->create();
        $cutter = Worker::create(['name' => 'Пильщик', 'positions' => ['Пильщик']]);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 0.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_USED,
        ]);

        $mockSync = \Mockery::mock(StoneReceptionSyncService::class);
        
        $service = new StoneReceptionService($mockSync);
        $result = $service->closeBatch($batch);

        expect($result)->toBeFalse();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// StoneReceptionService — markCompleted()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionService::markCompleted()', function () {

    test('завершает приёмку', function () {
        $rawProduct = Product::factory()->create(['name' => 'Гранит']);
        $store = Store::factory()->create();
        $cutter = Worker::create(['name' => 'Пильщик', 'positions' => ['Пильщик']]);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'positions' => ['Приёмщик']]);
        $reception = StoneReception::create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 5.0,
            'status' => StoneReception::STATUS_ACTIVE,
        ]);

        $mockSync = \Mockery::mock(StoneReceptionSyncService::class);
        $mockSync->shouldReceive('completeProcessing')->andReturn(['success' => false, 'message' => 'no id']);
        
        $service = new StoneReceptionService($mockSync);
        $result = $service->markCompleted($reception);

        expect($result['success'])->toBeTrue();
        $reception->refresh();
        expect($reception->status)->toBe(StoneReception::STATUS_COMPLETED);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// StoneReceptionService — resetStatus()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionService::resetStatus()', function () {

    test('сбрасывает статус на active', function () {
        $rawProduct = Product::factory()->create(['name' => 'Гранит']);
        $store = Store::factory()->create();
        $cutter = Worker::create(['name' => 'Пильщик', 'positions' => ['Пильщик']]);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'positions' => ['Приёмщик']]);
        $reception = StoneReception::create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 5.0,
            'status' => StoneReception::STATUS_COMPLETED,
        ]);

        $mockSync = \Mockery::mock(StoneReceptionSyncService::class);
        
        $service = new StoneReceptionService($mockSync);
        $result = $service->resetStatus($reception);

        expect($result)->toBeTrue();
        $reception->refresh();
        expect($reception->status)->toBe(StoneReception::STATUS_ACTIVE);
    });

    test('не сбрасывает если есть более новая приёмка', function () {
        $rawProduct = Product::factory()->create(['name' => 'Гранит']);
        $store = Store::factory()->create();
        $cutter = Worker::create(['name' => 'Пильщик', 'positions' => ['Пильщик']]);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'positions' => ['Приёмщик']]);
        $oldReception = StoneReception::create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 5.0,
            'status' => StoneReception::STATUS_COMPLETED,
        ]);
        StoneReception::create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 3.0,
            'status' => StoneReception::STATUS_ACTIVE,
        ]);

        $mockSync = \Mockery::mock(StoneReceptionSyncService::class);
        
        $service = new StoneReceptionService($mockSync);
        $result = $service->resetStatus($oldReception);

        expect($result)->toBeString();
        expect($result)->toContain('более новая');
    });
});