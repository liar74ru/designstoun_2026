<?php

use App\Exceptions\InsufficientRawMaterialException;
use App\Models\RawMaterialBatch;
use App\Models\StoneReception;
use App\Services\Moysklad\RawMaterialBatchSyncService;
use App\Services\Moysklad\StoneReceptionSyncService;
use App\Services\RawMaterialBatchService;
use App\Services\StoneReceptionService;
use Tests\Helpers\ReceptionTestHelper as H;

/*
 * Создание приёмки на партии с завершёнными приёмками делит партию (split).
 * Закрытие прежней активной приёмки, split и сама приёмка — одна транзакция,
 * МойСклад — только после неё.
 */

/**
 * Партия 10 м³: завершённая приёмка на 3 и активная на 1 → остаток 6.
 *
 * @return array{batch: RawMaterialBatch, active: StoneReception, receiver: \App\Models\Worker}
 */
function rcsBatchWithHistory(): array
{
    $store    = H::store();
    $cutter   = H::cutter();
    $receiver = H::worker();
    $batch    = H::batch(H::product(), $store, $cutter, 10.0, ['batch_number' => 'B-01']);

    H::reception($batch, $receiver, $cutter, $store, 3.0, ['status' => StoneReception::STATUS_COMPLETED]);
    $active = H::reception($batch, $receiver, $cutter, $store, 1.0);

    return ['batch' => $batch->fresh(), 'active' => $active, 'receiver' => $receiver];
}

function rcsService(RawMaterialBatchSyncService $batchSync, StoneReceptionSyncService $sync): StoneReceptionService
{
    return new StoneReceptionService($sync, app(RawMaterialBatchService::class), $batchSync);
}

test('приёмка не сохранилась — партия не разделена, прежняя приёмка активна, МойСклад не вызван', function () {
    ['batch' => $batch, 'active' => $active, 'receiver' => $receiver] = rcsBatchWithHistory();

    $batchSync = Mockery::mock(RawMaterialBatchSyncService::class);
    $batchSync->shouldNotReceive('syncCreated');
    $batchSync->shouldNotReceive('updateParentMove');
    $sync = Mockery::mock(StoneReceptionSyncService::class);
    $sync->shouldNotReceive('syncReception');

    // 8 м³ при остатке 6: предварительную проверку контроллера обходим —
    // так выглядит приёмка, у которой остаток забрали между проверкой и сохранением
    $data = H::receptionPostData($receiver, $batch->currentWorker, $batch->currentStore, $batch, 8.0);

    expect(fn () => rcsService($batchSync, $sync)->create($data, false))
        ->toThrow(InsufficientRawMaterialException::class);

    expect(RawMaterialBatch::count())->toBe(1);

    $batch->refresh();
    expect((float) $batch->initial_quantity)->toBe(10.0);
    expect((float) $batch->remaining_quantity)->toBe(6.0);
    expect($batch->status)->toBe(RawMaterialBatch::STATUS_IN_WORK);

    expect($active->fresh()->status)->toBe(StoneReception::STATUS_ACTIVE);
    expect(StoneReception::count())->toBe(2);
});

test('разделение уходит в МойСклад после того, как приёмка сохранена', function () {
    ['batch' => $parent, 'active' => $active, 'receiver' => $receiver] = rcsBatchWithHistory();

    $receptionsAtSync = null;
    $batchSync = Mockery::mock(RawMaterialBatchSyncService::class);
    $batchSync->shouldReceive('syncCreated')->once()
        ->andReturnUsing(function (RawMaterialBatch $child) use (&$receptionsAtSync) {
            $receptionsAtSync = StoneReception::where('raw_material_batch_id', $child->id)->count();
        });
    $batchSync->shouldReceive('updateParentMove')->once()
        ->withArgs(fn (RawMaterialBatch $batch, float $qty) => $batch->id === $parent->id && $qty === 4.0);
    $sync = Mockery::mock(StoneReceptionSyncService::class);
    $sync->shouldReceive('syncReception')->once();

    $data = H::receptionPostData($receiver, $parent->currentWorker, $parent->currentStore, $parent, 2.0);

    $reception = rcsService($batchSync, $sync)->create($data, false);

    expect($receptionsAtSync)->toBe(1);

    $child = RawMaterialBatch::whereKeyNot($parent->id)->sole();
    expect($reception->raw_material_batch_id)->toBe($child->id);
    expect((float) $child->remaining_quantity)->toBe(4.0);
    expect($parent->fresh()->status)->toBe(RawMaterialBatch::STATUS_USED);
    expect($active->fresh()->status)->toBe(StoneReception::STATUS_COMPLETED);
});
