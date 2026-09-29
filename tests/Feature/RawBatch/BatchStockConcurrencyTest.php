<?php

use App\Exceptions\InsufficientRawMaterialException;
use App\Models\ProductStock;
use App\Models\RawMaterialBatch;
use App\Models\RawMaterialMovement;
use App\Models\StoneReception;
use App\Services\Moysklad\RawMaterialBatchSyncService;
use App\Services\Moysklad\StoneReceptionSyncService;
use App\Services\RawMaterialBatchService;
use App\Services\StoneReceptionService;
use App\Support\BatchStock;
use Tests\Helpers\ReceptionTestHelper as H;

/*
 * Остатки при одновременной работе. SQLite не даёт настоящей параллельности,
 * поэтому гонка моделируется «устаревшей» моделью: партия загружена в начале
 * запроса, а остаток в БД к моменту сохранения уже изменил другой запрос.
 * Сервис обязан считать от свежего остатка (BatchStock::lock), а не от модели.
 */

function bsBatch(float $qty = 10.0): RawMaterialBatch
{
    return H::batch(H::product(), H::store(), H::cutter(), $qty);
}

/** Остаток в БД меняет «параллельный» запрос — модель в памяти об этом не знает. */
function bsChangeRemainingBehind(RawMaterialBatch $batch, float $remaining): void
{
    RawMaterialBatch::whereKey($batch->id)->update(['remaining_quantity' => $remaining]);
}

function bsReceptionService(): StoneReceptionService
{
    $sync = Mockery::mock(StoneReceptionSyncService::class);
    $sync->shouldReceive('syncReception')->andReturn(null);

    return new StoneReceptionService($sync, app(RawMaterialBatchService::class), Mockery::mock(RawMaterialBatchSyncService::class));
}

describe('Партия сырья: расчёт от свежего остатка', function () {

    test('корректировка прибавляет к остатку в БД, а не к загруженной модели', function () {
        $batch = bsBatch(10.0);
        bsChangeRemainingBehind($batch, 6.0); // приёмка списала 4

        $result = app(RawMaterialBatchService::class)->adjust($batch, 2.0, null, false, null);

        expect($result['newRemaining'])->toBe(8.0);
        expect((float) $batch->fresh()->remaining_quantity)->toBe(8.0);
    });

    test('корректировка в минус больше свежего остатка отклоняется', function () {
        $batch = bsBatch(10.0);
        bsChangeRemainingBehind($batch, 2.0);

        expect(fn () => app(RawMaterialBatchService::class)->adjust($batch, -5.0, null, false, null))
            ->toThrow(InsufficientRawMaterialException::class);

        expect((float) $batch->fresh()->remaining_quantity)->toBe(2.0);
        expect(RawMaterialMovement::where('batch_id', $batch->id)->count())->toBe(0);
    });

    test('правка количества считает израсходованное по свежему остатку', function () {
        $batch = bsBatch(10.0);
        bsChangeRemainingBehind($batch, 7.0); // израсходовано 3

        app(RawMaterialBatchService::class)->update($batch, [
            'product_id' => $batch->product_id,
            'quantity'   => 12.0,
        ], false);

        $batch->refresh();
        expect((float) $batch->initial_quantity)->toBe(12.0);
        expect((float) $batch->remaining_quantity)->toBe(9.0);
    });

    test('передача пильщику не проходит, если остаток уже забрали', function () {
        $batch = bsBatch(10.0);
        bsChangeRemainingBehind($batch, 2.0);

        expect(fn () => app(RawMaterialBatchService::class)->transfer($batch, [
            'quantity'     => 5.0,
            'to_worker_id' => H::cutter('Другой Пильщик')->id,
        ]))->toThrow(InsufficientRawMaterialException::class);

        expect(RawMaterialBatch::count())->toBe(1);
        expect((float) $batch->fresh()->remaining_quantity)->toBe(2.0);
    });

    test('возврат на склад не проходит, если остаток уже забрали', function () {
        $batch = bsBatch(10.0);
        bsChangeRemainingBehind($batch, 2.0);

        expect(fn () => app(RawMaterialBatchService::class)->returnToStore($batch, [
            'quantity'    => 5.0,
            'to_store_id' => H::store('Склад возврата')->id,
        ]))->toThrow(InsufficientRawMaterialException::class);

        expect(RawMaterialBatch::count())->toBe(1);
        expect((float) $batch->fresh()->remaining_quantity)->toBe(2.0);
    });
});

describe('Приёмка: списание под блокировкой партии', function () {

    test('приёмка не создаётся, если к моменту списания сырья не хватает', function () {
        $batch = bsBatch(5.0);

        // Предварительную проверку контроллера прошла бы и вторая из двух одновременных
        // приёмок — окончательно остаток проверяется при списании
        $data = H::receptionPostData(H::worker(), $batch->currentWorker, $batch->currentStore, $batch, 8.0);

        expect(fn () => bsReceptionService()->create($data, false))
            ->toThrow(InsufficientRawMaterialException::class);

        expect(StoneReception::count())->toBe(0);
        expect(RawMaterialMovement::where('batch_id', $batch->id)->count())->toBe(0);
        expect((float) $batch->fresh()->remaining_quantity)->toBe(5.0);
    });

    test('контроллер показывает нехватку у поля расхода сырья', function () {
        $batch = bsBatch(5.0);

        $this->actingAs(H::adminUser())
            ->post(route('stone-receptions.store'),
                H::receptionPostData(H::worker(), $batch->currentWorker, $batch->currentStore, $batch, 8.0))
            ->assertSessionHasErrors(['raw_quantity_used' => 'Недостаточно сырья']);

        expect(StoneReception::count())->toBe(0);
    });
});

describe('Остаток на складе', function () {

    test('вторая партия с того же склада-источника видит списание первой', function () {
        $product = H::product();
        $from    = H::store('Склад-источник');
        $to      = H::store('Склад пильщика');
        $cutter  = H::cutter();
        ProductStock::create(['product_id' => $product->id, 'store_id' => $from->id, 'quantity' => 10]);

        $data = [
            'product_id'    => $product->id,
            'quantity'      => 6.0,
            'worker_id'     => $cutter->id,
            'from_store_id' => $from->id,
            'to_store_id'   => $to->id,
        ];
        $service = app(RawMaterialBatchService::class);

        $service->create($data, false);

        expect(fn () => $service->create($data, false))->toThrow(InsufficientRawMaterialException::class);

        expect(RawMaterialBatch::count())->toBe(1);
        expect((float) ProductStock::where('store_id', $from->id)->value('quantity'))->toBe(4.0);
        expect((float) ProductStock::where('store_id', $to->id)->value('quantity'))->toBe(6.0);
    });

    test('без проверки остатка склад-источник уходит в минус, строка создаётся', function () {
        $product = H::product();
        $from    = H::store('Склад-источник');
        $to      = H::store('Склад пильщика');

        app(RawMaterialBatchService::class)->create([
            'product_id'    => $product->id,
            'quantity'      => 3.0,
            'worker_id'     => H::cutter()->id,
            'from_store_id' => $from->id,
            'to_store_id'   => $to->id,
        ], false, checkSourceStock: false);

        expect((float) ProductStock::where('store_id', $from->id)->value('quantity'))->toBe(-3.0);
        expect((float) ProductStock::where('store_id', $to->id)->value('quantity'))->toBe(3.0);
    });
});

describe('BatchStock::syncStatus', function () {

    test('рабочая партия получает статус по остатку', function (string $status, float $remaining, string $expected) {
        $batch = new RawMaterialBatch(['status' => $status, 'remaining_quantity' => $remaining]);

        BatchStock::syncStatus($batch);

        expect($batch->status)->toBe($expected);
    })->with([
        'новая с остатком'       => [RawMaterialBatch::STATUS_NEW, 3.0, RawMaterialBatch::STATUS_CONFIRMED],
        'в работе с остатком'    => [RawMaterialBatch::STATUS_IN_WORK, 3.0, RawMaterialBatch::STATUS_CONFIRMED],
        'уточнённая без остатка' => [RawMaterialBatch::STATUS_CONFIRMED, 0.0, RawMaterialBatch::STATUS_IN_WORK],
        'израсходованная'        => [RawMaterialBatch::STATUS_USED, 3.0, RawMaterialBatch::STATUS_USED],
        'возвращённая'           => [RawMaterialBatch::STATUS_RETURNED, 0.0, RawMaterialBatch::STATUS_RETURNED],
    ]);
});
