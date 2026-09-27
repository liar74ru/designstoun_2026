<?php

use App\Models\Product;
use App\Models\RawMaterialBatch;
use App\Models\RawMaterialMovement;
use App\Models\Store;
use App\Services\Moysklad\MoySkladMoveService;
use App\Services\Moysklad\RawMaterialBatchSyncService;
use App\Services\Moysklad\StockSyncService;
use Tests\Helpers\ReceptionTestHelper as H;

// Партия 10 м³ товара ms-raw с первичным движением со склада from → to.
function rbmsBatch(array $batchAttrs = [], array $movementAttrs = [], ?string $productMsId = 'ms-raw'): array
{
    $from    = Store::create(['id' => 'store-from', 'name' => 'Склад сырья']);
    $to      = Store::create(['id' => 'store-to', 'name' => 'Цех']);
    $worker  = H::cutter();
    $product = Product::factory()->create(['moysklad_id' => $productMsId]);

    $batch = RawMaterialBatch::create(array_merge([
        'product_id'         => $product->id,
        'initial_quantity'   => 10.0,
        'remaining_quantity' => 10.0,
        'current_store_id'   => $to->id,
        'current_worker_id'  => $worker->id,
        'batch_number'       => '42',
        'status'             => RawMaterialBatch::STATUS_IN_WORK,
    ], $batchAttrs));

    $movement = RawMaterialMovement::create(array_merge([
        'batch_id'      => $batch->id,
        'from_store_id' => $from->id,
        'to_store_id'   => $to->id,
        'movement_type' => 'create',
        'quantity'      => 10.0,
    ], $movementAttrs));

    return [$batch, $movement];
}

function rbmsService($moveService, $stockSync = null): RawMaterialBatchSyncService
{
    return new RawMaterialBatchSyncService($moveService, $stockSync ?? Mockery::mock(StockSyncService::class)->shouldIgnoreMissing());
}

// ══════════════════════════════════════════════════════════════════════════════
// RawMaterialBatchSyncService::syncBatchMove()
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchSyncService::syncBatchMove()', function () {

    test('успех без id перемещения — ошибка синхронизации, а не TypeError', function () {
        [$batch, $movement] = rbmsBatch();

        $move = mock(MoySkladMoveService::class);
        $move->shouldReceive('createMove')->once()
            ->andReturn(['success' => true, 'move_id' => null, 'code' => '', 'message' => '']);

        $result = rbmsService($move)->syncBatchMove($batch);

        expect($result['success'])->toBeFalse()
            ->and($result['code'])->toBe('api_error')
            ->and($batch->fresh()->moysklad_sync_error)->toBe('МойСклад не вернул id перемещения')
            ->and($movement->fresh()->moysklad_move_id)->toBeNull();
    });

    test('без перемещения в МойСклад создаёт его и помечает партию синхронизированной', function () {
        [$batch, $movement] = rbmsBatch();

        $move = mock(MoySkladMoveService::class);
        $move->shouldReceive('createMove')->once()
            ->withArgs(fn (array $d) => $d['from_store_id'] === 'store-from'
                && $d['to_store_id'] === 'store-to'
                && $d['products'] === [['product_id' => 'ms-raw', 'quantity' => 10.0]]
                && $d['name'] === 'Партия: 42'
                && $d['external_id'] === 'movement_' . $movement->id)
            ->andReturn(['success' => true, 'move_id' => 'move-new', 'code' => '', 'message' => '']);
        $stock = mock(StockSyncService::class);
        $stock->shouldReceive('refreshProducts')->once()->with(['ms-raw']);

        $result = rbmsService($move, $stock)->syncBatchMove($batch);

        expect($result)->toBe(['success' => true, 'code' => 'ok', 'message' => 'Синхронизировано']);
        expect($movement->fresh()->moysklad_move_id)->toBe('move-new')
            ->and((bool) $movement->fresh()->moysklad_synced)->toBeTrue();
        $batch->refresh();
        expect($batch->moysklad_processing_id)->toBe('move-new')
            ->and($batch->moysklad_processing_name)->toBe('Партия: 42')
            ->and($batch->moysklad_sync_status)->toBe(RawMaterialBatch::SYNC_STATUS_SYNCED)
            ->and($batch->moysklad_sync_error)->toBeNull();
    });

    test('коллизия имени — повтор с суффиксом _01', function () {
        [$batch, $movement] = rbmsBatch();

        $move = mock(MoySkladMoveService::class);
        $move->shouldReceive('createMove')->once()
            ->withArgs(fn (array $d) => $d['name'] === 'Партия: 42')
            ->andReturn(['success' => false, 'move_id' => null, 'code' => 'duplicate_name', 'message' => 'Ошибка МойСклад: не уникально']);
        $move->shouldReceive('createMove')->once()
            ->withArgs(fn (array $d) => $d['name'] === 'Партия: 42_01')
            ->andReturn(['success' => true, 'move_id' => 'move-dup', 'code' => '', 'message' => '']);

        $result = rbmsService($move)->syncBatchMove($batch);

        expect($result['success'])->toBeTrue();
        expect($batch->fresh()->moysklad_processing_name)->toBe('Партия: 42_01')
            ->and($movement->fresh()->moysklad_move_id)->toBe('move-dup');
    });

    test('при существующем перемещении обновляет его, а не создаёт новое', function () {
        [$batch, $movement] = rbmsBatch([], ['moysklad_move_id' => 'move-old']);

        $move = mock(MoySkladMoveService::class);
        $move->shouldNotReceive('createMove');
        $move->shouldReceive('updateMove')->once()
            ->withArgs(fn (string $id, array $d) => $id === 'move-old'
                && $d['products'] === [['product_id' => 'ms-raw', 'quantity' => 10.0]]
                && $d['name'] === 'Партия: 42')
            ->andReturn(['success' => true, 'move_id' => 'move-old', 'message' => '']);

        $result = rbmsService($move)->syncBatchMove($batch);

        expect($result['success'])->toBeTrue();
        expect($batch->fresh()->moysklad_processing_id)->toBe('move-old');
    });

    test('берёт первичное движение transfer_to_worker', function () {
        [$batch] = rbmsBatch([], ['movement_type' => 'transfer_to_worker', 'moysklad_move_id' => 'move-tr']);

        $move = mock(MoySkladMoveService::class);
        $move->shouldReceive('updateMove')->once()->withArgs(fn (string $id) => $id === 'move-tr')
            ->andReturn(['success' => true, 'message' => '']);

        expect(rbmsService($move)->syncBatchMove($batch)['success'])->toBeTrue();
    });

    test('ошибка API — пишет ошибку в партию и возвращает код', function () {
        [$batch, $movement] = rbmsBatch();

        $move = mock(MoySkladMoveService::class);
        $move->shouldReceive('createMove')->once()
            ->andReturn(['success' => false, 'move_id' => null, 'code' => 'api_error', 'message' => 'Ошибка МойСклад: Недостаточно товара']);
        $stock = mock(StockSyncService::class);
        $stock->shouldNotReceive('refreshProducts');

        $result = rbmsService($move, $stock)->syncBatchMove($batch);

        expect($result)->toBe(['success' => false, 'code' => 'api_error', 'message' => 'Ошибка МойСклад: Недостаточно товара']);
        expect($batch->fresh()->moysklad_sync_status)->toBe(RawMaterialBatch::SYNC_STATUS_NOT_SYNCED)
            ->and($batch->fresh()->moysklad_sync_error)->toBe('Ошибка МойСклад: Недостаточно товара')
            ->and($movement->fresh()->moysklad_move_id)->toBeNull();
    });

    test('ошибка обновления без code — код api_error', function () {
        [$batch] = rbmsBatch([], ['moysklad_move_id' => 'move-old']);

        $move = mock(MoySkladMoveService::class);
        $move->shouldReceive('updateMove')->andReturn(['success' => false, 'move_id' => 'move-old', 'message' => 'Ошибка МойСклад: заблокирован']);

        $result = rbmsService($move)->syncBatchMove($batch);

        expect($result['code'])->toBe('api_error')
            ->and($batch->fresh()->moysklad_sync_error)->toBe('Ошибка МойСклад: заблокирован');
    });

    test('исключение сервиса перемещений — код exception, без выброса', function () {
        [$batch] = rbmsBatch();

        $move = mock(MoySkladMoveService::class);
        $move->shouldReceive('createMove')->andThrow(new \RuntimeException('сбой сети'));

        $result = rbmsService($move)->syncBatchMove($batch);

        expect($result)->toBe(['success' => false, 'code' => 'exception', 'message' => 'сбой сети']);
        expect($batch->fresh()->moysklad_sync_error)->toBe('сбой сети');
    });

    test('товар без moysklad_id — not_synced, МойСклад не вызывается', function () {
        [$batch] = rbmsBatch([], [], null);

        $move = mock(MoySkladMoveService::class);
        $move->shouldNotReceive('createMove');
        $move->shouldNotReceive('updateMove');

        $result = rbmsService($move)->syncBatchMove($batch);

        expect($result['success'])->toBeFalse()->and($result['code'])->toBe('not_synced');
        expect($batch->fresh()->moysklad_sync_error)->toContain('Товар не синхронизирован');
    });

    test('нет первичного движения — not_synced', function () {
        [$batch, $movement] = rbmsBatch([], ['movement_type' => 'adjust']);

        $move = mock(MoySkladMoveService::class);
        $move->shouldNotReceive('createMove');

        $result = rbmsService($move)->syncBatchMove($batch);

        expect($result['code'])->toBe('not_synced')
            ->and($batch->fresh()->moysklad_sync_error)->toBe('Нет перемещения для синхронизации');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// RawMaterialBatchSyncService::syncCreated()
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchSyncService::syncCreated()', function () {

    test('создаёт перемещение, пишет move_id и обновляет остатки', function () {
        [$batch, $movement] = rbmsBatch();

        $move = mock(MoySkladMoveService::class);
        $move->shouldReceive('createMove')->once()
            ->withArgs(fn (array $d) => $d['name'] === 'Партия: 42' && $d['products'][0]['quantity'] === 10.0)
            ->andReturn(['success' => true, 'move_id' => 'move-c', 'code' => '', 'message' => '']);
        $stock = mock(StockSyncService::class);
        $stock->shouldReceive('refreshProducts')->once()->with(['ms-raw']);

        rbmsService($move, $stock)->syncCreated($batch, $movement);

        expect($movement->fresh()->moysklad_move_id)->toBe('move-c')
            ->and($batch->fresh()->moysklad_sync_status)->toBe(RawMaterialBatch::SYNC_STATUS_SYNCED);
    });

    test('коллизия имени — суффикс сохраняется в номер партии', function () {
        [$batch, $movement] = rbmsBatch();

        $move = mock(MoySkladMoveService::class);
        $move->shouldReceive('createMove')->once()->withArgs(fn (array $d) => $d['name'] === 'Партия: 42')
            ->andReturn(['success' => false, 'move_id' => null, 'code' => 'duplicate_name', 'message' => 'dup']);
        $move->shouldReceive('createMove')->once()->withArgs(fn (array $d) => $d['name'] === 'Партия: 42_01')
            ->andReturn(['success' => true, 'move_id' => 'move-c', 'code' => '', 'message' => '']);

        rbmsService($move)->syncCreated($batch, $movement);

        expect($batch->fresh()->batch_number)->toBe('42_01')
            ->and($batch->fresh()->moysklad_processing_name)->toBe('Партия: 42_01');
    });

    test('ошибка — пишет текст в партию, движение не помечается', function () {
        [$batch, $movement] = rbmsBatch();

        $move = mock(MoySkladMoveService::class);
        $move->shouldReceive('createMove')->andReturn(['success' => false, 'move_id' => null, 'code' => 'api_error', 'message' => 'Ошибка МойСклад: X']);

        rbmsService($move)->syncCreated($batch, $movement);

        expect($batch->fresh()->moysklad_sync_error)->toBe('Ошибка МойСклад: X')
            ->and($movement->fresh()->moysklad_move_id)->toBeNull();
    });

    test('исключение — пишется в партию, наружу не выбрасывается', function () {
        [$batch, $movement] = rbmsBatch();

        $move = mock(MoySkladMoveService::class);
        $move->shouldReceive('createMove')->andThrow(new \RuntimeException('boom'));

        rbmsService($move)->syncCreated($batch, $movement);

        expect($batch->fresh()->moysklad_sync_error)->toBe('boom');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// RawMaterialBatchSyncService::syncReturned()
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchSyncService::syncReturned()', function () {

    test('создаёт перемещение возврата с количеством и внешним кодом', function () {
        [$batch, $movement] = rbmsBatch();

        $move = mock(MoySkladMoveService::class);
        $move->shouldReceive('createMove')->once()
            ->withArgs(fn (array $d) => $d['from_store_id'] === 'store-to'
                && $d['to_store_id'] === 'store-from'
                && $d['products'] === [['product_id' => 'ms-raw', 'quantity' => 3.5]]
                && $d['name'] === 'Возврат партии: 42'
                && $d['external_id'] === 'movement_' . $movement->id . '_return')
            ->andReturn(['success' => true, 'move_id' => 'move-ret', 'code' => '', 'message' => '']);
        $stock = mock(StockSyncService::class);
        $stock->shouldReceive('refreshProducts')->once()->with(['ms-raw']);

        rbmsService($move, $stock)->syncReturned($batch, $movement, 'store-to', 'store-from', 3.5);

        expect($movement->fresh()->moysklad_move_id)->toBe('move-ret');
    });

    test('ошибка — движение не помечается, остатки не обновляются', function () {
        [$batch, $movement] = rbmsBatch();

        $move = mock(MoySkladMoveService::class);
        $move->shouldReceive('createMove')->andReturn(['success' => false, 'move_id' => null, 'code' => 'api_error', 'message' => 'err']);
        $stock = mock(StockSyncService::class);
        $stock->shouldNotReceive('refreshProducts');

        rbmsService($move, $stock)->syncReturned($batch, $movement, 'store-to', 'store-from', 3.5);

        expect($movement->fresh()->moysklad_move_id)->toBeNull();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// RawMaterialBatchSyncService::syncAdjusted()
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchSyncService::syncAdjusted()', function () {

    test('прибавляет дельту к исходному перемещению', function () {
        [$batch, $original] = rbmsBatch([], ['moysklad_move_id' => 'move-1']);
        $adjust = RawMaterialMovement::create([
            'batch_id' => $batch->id, 'movement_type' => 'adjust', 'quantity' => 2.0,
        ]);

        $move = mock(MoySkladMoveService::class);
        $move->shouldReceive('updateMove')->once()
            ->withArgs(fn (string $id, array $d) => $id === 'move-1' && $d['products'][0]['quantity'] === 12.0)
            ->andReturn(['success' => true, 'message' => '']);

        rbmsService($move)->syncAdjusted($batch, $adjust, 2.0, 12.0);

        expect((float) $original->fresh()->quantity)->toBe(12.0)
            ->and($adjust->fresh()->moysklad_move_id)->toBe('move-1');
    });

    test('обнуление — в МойСклад уходит минимальное 0.001', function () {
        [$batch] = rbmsBatch([], ['moysklad_move_id' => 'move-1']);
        $adjust = RawMaterialMovement::create(['batch_id' => $batch->id, 'movement_type' => 'adjust', 'quantity' => 10.0]);

        $move = mock(MoySkladMoveService::class);
        $move->shouldReceive('updateMove')->once()
            ->withArgs(fn (string $id, array $d) => $d['products'][0]['quantity'] === 0.001)
            ->andReturn(['success' => true, 'message' => '']);

        rbmsService($move)->syncAdjusted($batch, $adjust, -10.0, 0.0);
    });

    test('без синхронизированного исходного перемещения МойСклад не вызывается', function () {
        [$batch, $movement] = rbmsBatch();

        $move = mock(MoySkladMoveService::class);
        $move->shouldNotReceive('updateMove');

        rbmsService($move)->syncAdjusted($batch, $movement, 1.0, 11.0);
    });

    test('ошибка — количество исходного движения не меняется', function () {
        [$batch, $original] = rbmsBatch([], ['moysklad_move_id' => 'move-1']);
        $adjust = RawMaterialMovement::create(['batch_id' => $batch->id, 'movement_type' => 'adjust', 'quantity' => 2.0]);

        $move = mock(MoySkladMoveService::class);
        $move->shouldReceive('updateMove')->andReturn(['success' => false, 'message' => 'err']);

        rbmsService($move)->syncAdjusted($batch, $adjust, 2.0, 12.0);

        expect((float) $original->fresh()->quantity)->toBe(10.0)
            ->and($adjust->fresh()->moysklad_move_id)->toBeNull();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// RawMaterialBatchSyncService::updateParentMove()
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchSyncService::updateParentMove()', function () {

    test('по умолчанию отправляет остаток партии', function () {
        [$batch] = rbmsBatch(['remaining_quantity' => 6.5], ['moysklad_move_id' => 'move-p']);

        $move = mock(MoySkladMoveService::class);
        $move->shouldReceive('updateMove')->once()
            ->withArgs(fn (string $id, array $d) => $id === 'move-p'
                && $d['from_store_id'] === 'store-from'
                && $d['products'] === [['product_id' => 'ms-raw', 'quantity' => 6.5]])
            ->andReturn(['success' => true, 'message' => '']);
        $stock = mock(StockSyncService::class);
        $stock->shouldReceive('refreshProducts')->once()->with(['ms-raw']);

        rbmsService($move, $stock)->updateParentMove($batch);
    });

    test('явное количество имеет приоритет', function () {
        [$batch] = rbmsBatch([], ['moysklad_move_id' => 'move-p']);

        $move = mock(MoySkladMoveService::class);
        $move->shouldReceive('updateMove')->once()
            ->withArgs(fn (string $id, array $d) => $d['products'][0]['quantity'] === 4.0)
            ->andReturn(['success' => false, 'message' => 'err']);
        $stock = mock(StockSyncService::class);
        $stock->shouldNotReceive('refreshProducts');

        rbmsService($move, $stock)->updateParentMove($batch, 4.0);
    });

    test('move_id берётся из moysklad_processing_id партии, если у движения его нет', function () {
        [$batch] = rbmsBatch(['moysklad_processing_id' => 'move-batch']);

        $move = mock(MoySkladMoveService::class);
        $move->shouldReceive('updateMove')->once()->withArgs(fn (string $id) => $id === 'move-batch')
            ->andReturn(['success' => true, 'message' => '']);

        rbmsService($move)->updateParentMove($batch);
    });

    test('без move_id МойСклад не вызывается', function () {
        [$batch] = rbmsBatch();

        $move = mock(MoySkladMoveService::class);
        $move->shouldNotReceive('updateMove');

        rbmsService($move)->updateParentMove($batch);
    });

    test('партия, созданная передачей работнику, обновляет своё первичное перемещение', function () {
        [$batch] = rbmsBatch([], ['movement_type' => 'transfer_to_worker', 'moysklad_move_id' => 'move-tr']);

        $move = mock(MoySkladMoveService::class);
        $move->shouldReceive('updateMove')->once()
            ->withArgs(fn (string $id, array $d) => $id === 'move-tr'
                && $d['from_store_id'] === 'store-from'
                && $d['to_store_id'] === 'store-to')
            ->andReturn(['success' => true, 'message' => '']);

        rbmsService($move)->updateParentMove($batch);
    });

    test('без первичного движения, но с processing_id — пропуск без исключения', function () {
        // Раньше в updateMove уходили склады null, и TypeError вылетал из transfer()/return()
        [$batch] = rbmsBatch(['moysklad_processing_id' => 'move-batch'], ['movement_type' => 'adjust']);

        $move = mock(MoySkladMoveService::class);
        $move->shouldNotReceive('updateMove');

        expect(fn () => rbmsService($move)->updateParentMove($batch))->not->toThrow(\Throwable::class);
    });

    test('ошибка уровня Error внутри МойСклад не вылетает наружу', function () {
        [$batch] = rbmsBatch([], ['moysklad_move_id' => 'move-p']);

        $move = mock(MoySkladMoveService::class);
        $move->shouldReceive('updateMove')->andThrow(new \TypeError('boom'));

        expect(fn () => rbmsService($move)->updateParentMove($batch))->not->toThrow(\Throwable::class);
    });
});
