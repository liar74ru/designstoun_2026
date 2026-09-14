<?php

use App\Models\Product;
use App\Models\RawMaterialBatch;
use App\Models\RawMaterialMovement;
use App\Models\Store;
use App\Models\Worker;
use App\Services\Moysklad\MoySkladMoveService;
use App\Services\Moysklad\RawMaterialBatchSyncService;
use App\Services\Moysklad\StockSyncService;

// ══════════════════════════════════════════════════════════════════════════════
// RawMaterialBatchSyncService::syncEdited()
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchSyncService::syncEdited()', function () {

    test('при смене товара перечитывает остатки нового и прежнего товара', function () {
        $store   = Store::factory()->create();
        $worker  = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
        $product = Product::factory()->create(['moysklad_id' => 'ms-new']);
        $batch   = RawMaterialBatch::create([
            'product_id'         => $product->id,
            'initial_quantity'   => 10.0,
            'remaining_quantity' => 10.0,
            'current_store_id'   => $store->id,
            'current_worker_id'  => $worker->id,
            'status'             => RawMaterialBatch::STATUS_IN_WORK,
        ]);
        RawMaterialMovement::create([
            'batch_id'         => $batch->id,
            'from_store_id'    => $store->id,
            'to_store_id'      => $store->id,
            'movement_type'    => 'create',
            'quantity'         => 10.0,
            'moysklad_move_id' => 'move-1',
        ]);

        $moveService = mock(MoySkladMoveService::class)
            ->shouldReceive('updateMove')
            ->andReturn(['success' => true, 'message' => ''])
            ->getMock();
        $stockSync = mock(StockSyncService::class)
            ->shouldReceive('refreshProducts')->once()->with(['ms-new', 'ms-old'])
            ->getMock();

        (new RawMaterialBatchSyncService($moveService, $stockSync))->syncEdited($batch, 10.0, null, 'ms-old');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// RawMaterialBatchSyncService::deleteMove()
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchSyncService::deleteMove()', function () {

    test('после удаления перемещения обновляет остатки товара', function () {
        $moveService = mock(MoySkladMoveService::class)
            ->shouldReceive('deleteMove')->with('move-1')
            ->andReturn(['success' => true, 'message' => ''])
            ->getMock();
        $stockSync = mock(StockSyncService::class)
            ->shouldReceive('refreshProducts')->once()->with(['ms-raw'])
            ->getMock();

        (new RawMaterialBatchSyncService($moveService, $stockSync))->deleteMove('move-1', 'ms-raw');
    });

    test('при ошибке удаления остатки не обновляются', function () {
        $moveService = mock(MoySkladMoveService::class)
            ->shouldReceive('deleteMove')->with('move-1')
            ->andReturn(['success' => false, 'message' => 'Не найдено'])
            ->getMock();
        $stockSync = mock(StockSyncService::class)
            ->shouldNotReceive('refreshProducts')
            ->getMock();

        (new RawMaterialBatchSyncService($moveService, $stockSync))->deleteMove('move-1', 'ms-raw');
    });
});
