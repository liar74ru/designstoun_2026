<?php

use App\Jobs\DeleteProcessingInMoysklad;
use App\Jobs\SyncReceptionToMoysklad;
use App\Models\Product;
use App\Models\RawMaterialBatch;
use App\Models\RawMaterialMovement;
use App\Models\ReceptionLog;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use App\Models\Store;
use App\Models\User;
use App\Models\Worker;
use App\Services\RawMaterialBatchService;
use App\Services\StoneReceptionService;
use Illuminate\Support\Facades\Queue;

/**
 * Сборка StoneReceptionService с моками для тестов.
 * Единственная зависимость — batchService, нужна только для split-логики при создании
 * повторной приёмки на партии. Очередь подменена: МойСклад здесь не участвует.
 */
function makeStoneReceptionService(): StoneReceptionService
{
    Queue::fake();

    return new StoneReceptionService(app(RawMaterialBatchService::class));
}

// ══════════════════════════════════════════════════════════════════════════════
// StoneReceptionService — getFilterData()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionService::getFilterData()', function () {

    test('возвращает данные для фильтров', function () {
        $rawProduct = Product::factory()->create(['name' => 'Гранит']);
        $product = Product::factory()->create(['name' => 'Плитка']);
        $store = Store::factory()->create();
        $worker = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $worker->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'position' => 'Мастер']);
        StoneReception::create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $worker->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 5.0,
            'status' => StoneReception::STATUS_ACTIVE,
        ]);

        $service = makeStoneReceptionService();
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
        $cutter = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'position' => 'Мастер']);

        $service = makeStoneReceptionService();
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
        $cutter = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'position' => 'Мастер']);

        $service = makeStoneReceptionService();
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
        $cutter = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 95.0, // 5 уже списала активная приёмка ниже
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'position' => 'Мастер']);
        $oldReception = StoneReception::create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 5.0,
            'status' => StoneReception::STATUS_ACTIVE,
        ]);

        $service = makeStoneReceptionService();
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
        $cutter = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 95.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'position' => 'Мастер']);
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

        // Правку выполняет мастер-редактор (не создатель приёмки): дельта должна засчитаться ему.
        $editor     = Worker::create(['name' => 'Редактор', 'position' => 'Мастер']);
        $editorUser = User::factory()->create(['worker_id' => $editor->id]);
        $this->actingAs($editorUser);

        $service = makeStoneReceptionService();
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

        // Лог правки засчитан редактору, а не исходному приёмщику
        $log = ReceptionLog::where('stone_reception_id', $reception->id)
            ->where('type', ReceptionLog::TYPE_UPDATED)->first();
        expect($log)->not->toBeNull()
            ->and($log->receiver_id)->toBe($editor->id);
    });

    test('перечитывает остатки убранных позиций и сырья партии', function () {
        $rawProduct = Product::factory()->create(['name' => 'Гранит', 'moysklad_id' => 'ms-raw']);
        $kept       = Product::factory()->create(['name' => 'Плитка', 'moysklad_id' => 'ms-kept']);
        $removed    = Product::factory()->create(['name' => 'Брусчатка', 'moysklad_id' => 'ms-removed']);
        $store      = Store::factory()->create();
        $cutter     = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
        $batch      = RawMaterialBatch::create([
            'product_id'         => $rawProduct->id,
            'initial_quantity'   => 100.0,
            'remaining_quantity' => 95.0,
            'current_store_id'   => $store->id,
            'current_worker_id'  => $cutter->id,
            'status'             => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver  = Worker::create(['name' => 'Приёмщик', 'position' => 'Мастер']);
        $reception = StoneReception::create([
            'receiver_id'           => $receiver->id,
            'cutter_id'             => $cutter->id,
            'store_id'              => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used'     => 5.0,
            'status'                => StoneReception::STATUS_ACTIVE,
        ]);
        StoneReceptionItem::create(['stone_reception_id' => $reception->id, 'product_id' => $kept->id, 'quantity' => 2.0]);
        StoneReceptionItem::create(['stone_reception_id' => $reception->id, 'product_id' => $removed->id, 'quantity' => 1.0]);

        $this->actingAs(User::factory()->create(['worker_id' => $receiver->id]));

        // Убранная позиция и сырьё партии уезжают в задаче на перечитывание остатков
        makeStoneReceptionService()->update($reception, [
            'receiver_id'           => $receiver->id,
            'cutter_id'             => $cutter->id,
            'store_id'              => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used'     => 5.0,
            'raw_quantity_delta'    => 0,
            'products'              => [
                ['product_id' => $kept->id, 'quantity' => 2.0],
            ],
        ], false);

        expect($reception->fresh()->items)->toHaveCount(1);
        Queue::assertPushed(SyncReceptionToMoysklad::class, fn ($job) => in_array('ms-removed', $job->alsoRefresh, true)
            && in_array('ms-raw', $job->alsoRefresh, true));
    });

    test('не пишет лог если ничего не изменилось', function () {
        $rawProduct = Product::factory()->create(['name' => 'Гранит']);
        $product = Product::factory()->create(['name' => 'Плитка']);
        $store = Store::factory()->create();
        $cutter = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 95.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'position' => 'Мастер']);
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

        $service = makeStoneReceptionService();
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
        $cutter = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'position' => 'Мастер']);
        $reception = StoneReception::create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 5.0,
            'status' => StoneReception::STATUS_ACTIVE,
        ]);

        $service = makeStoneReceptionService();
        $service->delete($reception);

        expect(StoneReception::find($reception->id))->toBeNull();
    });

    test('удаляет техоперацию в МойСклад при наличии moysklad_processing_id', function () {
        $rawProduct = Product::factory()->create(['name' => 'Гранит']);
        $store = Store::factory()->create();
        $cutter = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'position' => 'Мастер']);
        $reception = StoneReception::create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 5.0,
            'status' => StoneReception::STATUS_ACTIVE,
            'moysklad_processing_id' => 'ms-uuid-1',
        ]);

        $service = makeStoneReceptionService();
        $service->delete($reception);

        expect(StoneReception::find($reception->id))->toBeNull();
        Queue::assertPushed(DeleteProcessingInMoysklad::class, fn ($job) => $job->processingId === 'ms-uuid-1');
    });

    test('возвращает сырьё в партию и пишет движение return_to_store, статус партии не меняет', function () {
        $rawProduct = Product::factory()->create(['name' => 'Гранит']);
        $product    = Product::factory()->create(['name' => 'Плитка']);
        $store      = Store::factory()->create();
        $cutter     = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
        $receiver   = Worker::create(['name' => 'Приёмщик', 'position' => 'Мастер']);
        $batch      = RawMaterialBatch::create([
            'product_id'         => $rawProduct->id,
            'initial_quantity'   => 10.0,
            'remaining_quantity' => 10.0,
            'current_store_id'   => $store->id,
            'current_worker_id'  => $cutter->id,
            'status'             => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $service   = makeStoneReceptionService();
        $reception = $service->create([
            'receiver_id'           => $receiver->id,
            'cutter_id'             => $cutter->id,
            'store_id'              => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used'     => 4.0,
            'products'              => [['product_id' => $product->id, 'quantity' => 1.0]],
        ], false);

        expect((float) $batch->fresh()->remaining_quantity)->toBe(6.0);
        $statusBefore = $batch->fresh()->status;

        $service->delete($reception);

        $batch->refresh();
        expect((float) $batch->remaining_quantity)->toBe(10.0)
            ->and($batch->status)->toBe($statusBefore);

        $movement = RawMaterialMovement::where('batch_id', $batch->id)
            ->where('movement_type', 'return_to_store')->sole();
        expect((float) $movement->quantity)->toBe(4.0)
            ->and($movement->to_store_id)->toBe($store->id)
            ->and($movement->to_worker_id)->toBe($cutter->id)
            ->and($movement->moved_by)->toBe($receiver->id);
    });

    test('запись в обход сервиса партию и движения не трогает', function () {
        $rawProduct = Product::factory()->create(['name' => 'Гранит']);
        $store      = Store::factory()->create();
        $cutter     = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
        $batch      = RawMaterialBatch::create([
            'product_id'         => $rawProduct->id,
            'initial_quantity'   => 10.0,
            'remaining_quantity' => 10.0,
            'current_store_id'   => $store->id,
            'current_worker_id'  => $cutter->id,
            'status'             => RawMaterialBatch::STATUS_NEW,
        ]);

        $reception = StoneReception::create([
            'receiver_id'           => $cutter->id,
            'cutter_id'             => $cutter->id,
            'store_id'              => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used'     => 4.0,
            'status'                => StoneReception::STATUS_ACTIVE,
        ]);
        $reception->delete();

        $batch->refresh();
        expect((float) $batch->remaining_quantity)->toBe(10.0)
            ->and($batch->status)->toBe(RawMaterialBatch::STATUS_NEW)
            ->and(RawMaterialMovement::where('batch_id', $batch->id)->exists())->toBeFalse();
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
        $cutter = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 0.0, // приёмка ниже списала всё
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'position' => 'Мастер']);
        $reception = StoneReception::create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 100.0,
            'status' => StoneReception::STATUS_ACTIVE,
        ]);

        $service = makeStoneReceptionService();
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
        $cutter = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 0.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_USED,
        ]);

        $service = makeStoneReceptionService();
        $result = $service->closeBatch($batch);

        expect($result)->toBeFalse();
    });

    test('приёмка с ошибкой синхронизации не завершается в МойСклад и сохраняет ошибку', function () {
        $rawProduct = Product::factory()->create(['name' => 'Гранит']);
        $store = Store::factory()->create();
        $cutter = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0, // приёмка ниже спишет всё — остаток 0
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'position' => 'Мастер']);
        $reception = StoneReception::create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 100.0,
            'status' => StoneReception::STATUS_ACTIVE,
            'moysklad_processing_id' => 'proc-uuid',
            'moysklad_sync_status' => StoneReception::SYNC_STATUS_NOT_SYNCED,
            'moysklad_sync_error' => 'Ошибка МойСклад: товар не найден',
        ]);

        $service = makeStoneReceptionService();
        $result = $service->closeBatch($batch);

        expect($result)->toBeTrue();

        // Ошибку разбирает задача: статус техоперации она сменит только после успешного обновления
        $reception->refresh();
        expect($reception->status)->toBe(StoneReception::STATUS_COMPLETED);
        expect($reception->isSyncPending())->toBeTrue();
        expect($reception->moysklad_sync_error)->toBeNull();
        Queue::assertPushed(SyncReceptionToMoysklad::class);
    });

    test('приёмка без ошибки ставится в очередь на завершение', function () {
        $rawProduct = Product::factory()->create(['name' => 'Гранит']);
        $store = Store::factory()->create();
        $cutter = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0, // приёмка ниже спишет всё — остаток 0
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'position' => 'Мастер']);
        $reception = StoneReception::create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 100.0,
            'status' => StoneReception::STATUS_ACTIVE,
            'moysklad_processing_id' => 'proc-uuid',
            'moysklad_sync_status' => StoneReception::SYNC_STATUS_SYNCED,
        ]);

        $service = makeStoneReceptionService();
        $service->closeBatch($batch);

        $reception->refresh();
        expect($reception->status)->toBe(StoneReception::STATUS_COMPLETED);
        expect($reception->isSyncPending())->toBeTrue();
        Queue::assertPushed(SyncReceptionToMoysklad::class, fn ($job) => $job->reception->is($reception));
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// StoneReceptionService — markCompleted()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionService::markCompleted()', function () {

    test('завершает приёмку', function () {
        $rawProduct = Product::factory()->create(['name' => 'Гранит']);
        $store = Store::factory()->create();
        $cutter = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'position' => 'Мастер']);
        $reception = StoneReception::create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 5.0,
            'status' => StoneReception::STATUS_ACTIVE,
        ]);

        $service = makeStoneReceptionService();
        $service->markCompleted($reception);

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
        $cutter = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'position' => 'Мастер']);
        $reception = StoneReception::create([
            'receiver_id' => $receiver->id,
            'cutter_id' => $cutter->id,
            'store_id' => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used' => 5.0,
            'status' => StoneReception::STATUS_COMPLETED,
        ]);

        $service = makeStoneReceptionService();
        $result = $service->resetStatus($reception);

        expect($result)->toBeTrue();
        $reception->refresh();
        expect($reception->status)->toBe(StoneReception::STATUS_ACTIVE);
    });

    test('не сбрасывает если есть более новая приёмка', function () {
        $rawProduct = Product::factory()->create(['name' => 'Гранит']);
        $store = Store::factory()->create();
        $cutter = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
        $batch = RawMaterialBatch::create([
            'product_id' => $rawProduct->id,
            'initial_quantity' => 100.0,
            'remaining_quantity' => 100.0,
            'current_store_id' => $store->id,
            'current_worker_id' => $cutter->id,
            'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);

        $receiver = Worker::create(['name' => 'Приёмщик', 'position' => 'Мастер']);
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

        $service = makeStoneReceptionService();
        $result = $service->resetStatus($oldReception);

        expect($result)->toBeString();
        expect($result)->toContain('более новая');
    });
});
