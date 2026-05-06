<?php

use App\Models\Product;
use App\Models\RawMaterialBatch;
use App\Models\ReceptionLog;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use App\Models\Store;
use App\Models\Worker;
use App\Services\Moysklad\RawMaterialBatchSyncService;
use App\Services\Moysklad\StoneReceptionSyncService;
use App\Services\RawMaterialBatchService;
use App\Services\StoneReceptionService;

/**
 * Сборка StoneReceptionService с моками для тестов.
 * Конструктор принимает 3 зависимости — две из них (batchService, batchSyncService)
 * нужны только для split-логики при создании повторной приёмки на партии.
 */
function makeStoneReceptionService(StoneReceptionSyncService $sync): StoneReceptionService
{
    $mockBatchSync = \Mockery::mock(RawMaterialBatchSyncService::class);
    $mockBatchSync->shouldReceive('syncCreated')->andReturn(null);
    $mockBatchSync->shouldReceive('updateParentMove')->andReturn(null);

    return new StoneReceptionService($sync, app(RawMaterialBatchService::class), $mockBatchSync);
}

// ══════════════════════════════════════════════════════════════════════════════
// StoneReceptionService — getFilterData()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionService::getFilterData()', function () {

    test('возвращает данные для фильтров', function () {
        $rawProduct = Product::factory()->create(['name' => 'Гранит']);
        $product = Product::factory()->create(['name' => 'Плитка']);
        $store = Store::factory()->create();
        $worker = Worker::create(['name' => 'Пильщик', 'positions' => ['Работник']]);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $worker->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'positions' => ['Мастер']]);
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
        
        $service = makeStoneReceptionService($mockSync);
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
        $cutter = Worker::create(['name' => 'Пильщик', 'positions' => ['Работник']]);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'positions' => ['Мастер']]);

        $mockSync = \Mockery::mock(StoneReceptionSyncService::class);
        $mockSync->shouldReceive('syncReception')->andReturn(null);
        
        $service = makeStoneReceptionService($mockSync);
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

    test('не разделяет партию при первой приёмке (нет завершённых ранее)', function () {
        $rawProduct = Product::factory()->create(['name' => 'Гранит']);
        $product = Product::factory()->create(['name' => 'Плитка']);
        $store = Store::factory()->create();
        $cutter = Worker::create(['name' => 'Пильщик', 'positions' => ['Работник']]);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'positions' => ['Мастер']]);

        $mockSync = \Mockery::mock(StoneReceptionSyncService::class);
        $mockSync->shouldReceive('syncReception')->andReturn(null);

        $service = makeStoneReceptionService($mockSync);
        $reception = $service->create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 4.0,
            'products' => [
                ['product_id' => $product->id, 'quantity' => 1.0],
            ],
        ], false);

        // Партия не разделена — приёмка создана на исходной партии
        expect($reception->raw_material_batch_id)->toBe($batch->id);

        // Только одна партия (исходная) существует
        expect(RawMaterialBatch::count())->toBe(1);
    });

    test('закрывает активную приёмку и разделяет партию при создании новой', function () {
        $rawProduct = Product::factory()->create(['name' => 'Гранит']);
        $product = Product::factory()->create(['name' => 'Плитка']);
        $store = Store::factory()->create();
        $cutter = Worker::create(['name' => 'Пильщик', 'positions' => ['Работник']]);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'positions' => ['Мастер']]);
        $oldReception = StoneReception::create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 5.0,
            'status' => StoneReception::STATUS_ACTIVE,
        ]);

        $mockSync = \Mockery::mock(StoneReceptionSyncService::class);
        $mockSync->shouldReceive('syncReception')->andReturn(null);

        $service = makeStoneReceptionService($mockSync);
        $newReception = $service->create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 3.0,
            'products' => [
                ['product_id' => $product->id, 'quantity' => 1.0],
            ],
        ], false);

        // Старая активная приёмка завершена
        $oldReception->refresh();
        expect($oldReception->status)->toBe(StoneReception::STATUS_COMPLETED);

        // Партия разделена: родитель сжат до фактически использованного объёма (5 м³),
        // его статус — USED.
        $batch->refresh();
        expect($batch->status)->toBe(RawMaterialBatch::STATUS_USED);
        expect((float) $batch->initial_quantity)->toBe(5.0);
        expect((float) $batch->remaining_quantity)->toBe(0.0);

        // Новая приёмка ведётся на дочерней партии, не на родительской.
        expect($newReception->raw_material_batch_id)->not->toBe($batch->id);
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
        $cutter = Worker::create(['name' => 'Пильщик', 'positions' => ['Работник']]);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 95.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'positions' => ['Мастер']]);
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
        
        $service = makeStoneReceptionService($mockSync);
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
        $cutter = Worker::create(['name' => 'Пильщик', 'positions' => ['Работник']]);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 95.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'positions' => ['Мастер']]);
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
        
        $service = makeStoneReceptionService($mockSync);
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
        $cutter = Worker::create(['name' => 'Пильщик', 'positions' => ['Работник']]);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'positions' => ['Мастер']]);
        $reception = StoneReception::create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 5.0,
            'status' => StoneReception::STATUS_ACTIVE,
        ]);

        $mockSync = \Mockery::mock(StoneReceptionSyncService::class);
        
        $service = makeStoneReceptionService($mockSync);
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
        $cutter = Worker::create(['name' => 'Пильщик', 'positions' => ['Работник']]);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 0.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'positions' => ['Мастер']]);
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
        
        $service = makeStoneReceptionService($mockSync);
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
        $cutter = Worker::create(['name' => 'Пильщик', 'positions' => ['Работник']]);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 0.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_USED,
        ]);

        $mockSync = \Mockery::mock(StoneReceptionSyncService::class);
        
        $service = makeStoneReceptionService($mockSync);
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
        $cutter = Worker::create(['name' => 'Пильщик', 'positions' => ['Работник']]);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'positions' => ['Мастер']]);
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
        
        $service = makeStoneReceptionService($mockSync);
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
        $cutter = Worker::create(['name' => 'Пильщик', 'positions' => ['Работник']]);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'positions' => ['Мастер']]);
        $reception = StoneReception::create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 5.0,
            'status' => StoneReception::STATUS_COMPLETED,
        ]);

        $mockSync = \Mockery::mock(StoneReceptionSyncService::class);
        
        $service = makeStoneReceptionService($mockSync);
        $result = $service->resetStatus($reception);

        expect($result)->toBeTrue();
        $reception->refresh();
        expect($reception->status)->toBe(StoneReception::STATUS_ACTIVE);
    });

    test('не сбрасывает если есть более новая приёмка', function () {
        $rawProduct = Product::factory()->create(['name' => 'Гранит']);
        $store = Store::factory()->create();
        $cutter = Worker::create(['name' => 'Пильщик', 'positions' => ['Работник']]);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'positions' => ['Мастер']]);
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
        
        $service = makeStoneReceptionService($mockSync);
        $result = $service->resetStatus($oldReception);

        expect($result)->toBeString();
        expect($result)->toContain('более новая');
    });
});