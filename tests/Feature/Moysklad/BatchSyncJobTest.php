<?php

use App\Jobs\DeleteMoveInMoysklad;
use App\Jobs\SyncBatchToMoysklad;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\RawMaterialBatch;
use App\Models\RawMaterialMovement;
use App\Models\Store;
use App\Services\Moysklad\RawMaterialBatchSyncService;
use App\Services\RawMaterialBatchService;
use App\Support\DocumentNaming;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Helpers\ReceptionTestHelper as H;

/*
 * Партии в очереди: перемещение в МойСклад равно партии. Количество читается при выполнении,
 * поэтому повтор не применит изменение дважды. Правила — docs/moysklad-sync-pattern.md.
 */

beforeEach(function () {
    config()->set('services.moysklad.token', 'test-token');
    config()->set('services.moysklad.base_url', 'https://ms.test');

    $this->source = Store::create(['id' => 'store-source', 'name' => 'Склад сырья']);
    $this->shop   = Store::create(['id' => 'store-shop', 'name' => 'Цех']);
    $this->other  = Store::create(['id' => 'store-other', 'name' => 'Другой склад']);
    $this->worker = H::cutter();
    $this->raw    = Product::factory()->create(['moysklad_id' => 'ms-raw']);

    foreach ([$this->source, $this->shop, $this->other] as $store) {
        ProductStock::create(['product_id' => $this->raw->id, 'store_id' => $store->id, 'quantity' => 100]);
    }
});

/** Партия 10 м³ со склада сырья в цех. */
function bsjBatch($ctx, array $batch = [], array $movement = []): RawMaterialBatch
{
    $model = RawMaterialBatch::create(array_merge([
        'product_id'         => $ctx->raw->id,
        'initial_quantity'   => 10.0,
        'remaining_quantity' => 10.0,
        'current_store_id'   => $ctx->shop->id,
        'current_worker_id'  => $ctx->worker->id,
        'batch_number'       => '42',
        'status'             => RawMaterialBatch::STATUS_IN_WORK,
    ], $batch));

    RawMaterialMovement::create(array_merge([
        'batch_id'      => $model->id,
        'from_store_id' => $ctx->source->id,
        'to_store_id'   => $ctx->shop->id,
        'movement_type' => 'create',
        'quantity'      => 10.0,
    ], $movement));

    return $model;
}

/** Фейк МойСклад. Переопределения: lookup, post, put, delete. */
function bsjFake(array $responses = []): void
{
    Http::fake(function (Request $request) use ($responses) {
        $url = $request->url();

        if (str_contains($url, 'report/stock/bystore')) {
            return Http::response(['rows' => []], 200);
        }
        if ($request->method() === 'POST') {
            $post = $responses['post'] ?? Http::response(['id' => 'move-new', 'externalCode' => 'x'], 200);

            return is_callable($post) ? $post($request) : $post;
        }
        if ($request->method() === 'PUT') {
            return $responses['put'] ?? Http::response(['id' => 'move-1'], 200);
        }
        if ($request->method() === 'DELETE') {
            return $responses['delete'] ?? Http::response(null, 200);
        }
        if (str_contains($url, 'filter=externalCode')) {
            return $responses['lookup'] ?? Http::response(['rows' => []], 200);
        }

        $meta = ['href' => 'h', 'type' => 't', 'mediaType' => 'application/json'];

        return Http::response(['meta' => $meta, 'rows' => [['meta' => $meta]]], 200);
    });
}

function bsjRun(RawMaterialBatch $batch, int $attempt = 1, array $alsoRefresh = []): SyncBatchToMoysklad
{
    $job = (new SyncBatchToMoysklad($batch->fresh(), $alsoRefresh))->withFakeQueueInteractions();
    $job->job->attempts = $attempt;
    $job->handle(app(RawMaterialBatchSyncService::class));

    return $job;
}

function bsjSent(string $method): array
{
    return Http::recorded(fn (Request $r) => $r->method() === $method && str_contains($r->url(), '/entity/move'))
        ->map(fn ($pair) => $pair[0])
        ->values()
        ->all();
}

function bsjQuantity(Request $request): float
{
    return (float) $request->data()['positions'][0]['quantity'];
}

function bsjStockIds(): array
{
    return Http::recorded(fn (Request $r) => str_contains($r->url(), 'report/stock/bystore'))
        ->map(fn ($pair) => $pair[0]['filter'] ?? $pair[0]->url())
        ->values()
        ->all();
}

describe('Количество: перемещение равно партии', function () {

    test('создание: POST с количеством партии и внешним кодом движения', function () {
        $batch = bsjBatch($this);
        bsjFake();

        bsjRun($batch)->assertNotReleased();

        $post = bsjSent('POST')[0];
        expect(bsjQuantity($post))->toBe(10.0);
        expect($post->data()['name'])->toBe('Партия: 42');
        expect($post->data()['externalCode'])
            ->toBe(DocumentNaming::externalCode('movement', $batch->primaryMovement()->id));

        $fresh = $batch->fresh();
        expect($fresh->isSynced())->toBeTrue();
        expect($fresh->moysklad_processing_id)->toBe('move-new');
        expect($fresh->moysklad_processing_name)->toBe('Партия: 42');
        expect($fresh->primaryMovement()->moysklad_move_id)->toBe('move-new');
    });

    test('корректировка: PUT с новым количеством, новых перемещений нет', function () {
        $batch = bsjBatch($this, [], ['moysklad_move_id' => 'move-1']);
        app(RawMaterialBatchService::class)->adjust($batch, -1.0, null, false, null);
        bsjFake();

        bsjRun($batch);

        expect(bsjSent('POST'))->toBe([]);
        expect(bsjQuantity(bsjSent('PUT')[0]))->toBe(9.0);
        expect((float) $batch->primaryMovement()->quantity)->toBe(9.0);
    });

    test('повтор корректировки не применяет её дважды', function () {
        $batch = bsjBatch($this, [], ['moysklad_move_id' => 'move-1']);
        app(RawMaterialBatchService::class)->adjust($batch, -1.0, null, false, null);
        bsjFake();

        bsjRun($batch);
        bsjRun($batch, 2);

        $quantities = array_map(fn ($put) => bsjQuantity($put), bsjSent('PUT'));
        expect($quantities)->toBe([9.0, 9.0]);
    });

    test('корректировка после передачи считает от актуального количества', function () {
        $batch   = bsjBatch($this, [], ['moysklad_move_id' => 'move-1']);
        $service = app(RawMaterialBatchService::class);
        $service->transfer($batch, ['quantity' => 3.0, 'to_worker_id' => H::cutter()->id]);
        $service->adjust($batch->fresh(), 1.0, null, false, null);
        bsjFake();

        bsjRun($batch);

        expect(bsjQuantity(bsjSent('PUT')[0]))->toBe(8.0);
    });

    test('передача: у родителя меньше, у новой партии своё перемещение', function () {
        $batch = bsjBatch($this, [], ['moysklad_move_id' => 'move-1']);
        ['newBatch' => $child] = app(RawMaterialBatchService::class)
            ->transfer($batch, ['quantity' => 4.0, 'to_worker_id' => H::cutter()->id]);
        bsjFake();

        bsjRun($child);
        bsjRun($batch);

        expect(bsjQuantity(bsjSent('POST')[0]))->toBe(4.0);
        expect(bsjQuantity(bsjSent('PUT')[0]))->toBe(6.0);
    });

    test('всё передано: остаётся минимальное 0.001, МойСклад не принимает пустую позицию', function () {
        $batch = bsjBatch($this, [], ['moysklad_move_id' => 'move-1']);
        app(RawMaterialBatchService::class)->transfer($batch, ['quantity' => 10.0, 'to_worker_id' => H::cutter()->id]);
        bsjFake();

        bsjRun($batch);

        expect(bsjQuantity(bsjSent('PUT')[0]))->toBe(0.001);
    });

    test('правка до создания перемещения: POST с актуальными данными, а не потеря', function () {
        $batch = bsjBatch($this);
        $batch->update(['initial_quantity' => 7.5, 'remaining_quantity' => 7.5]);
        bsjFake();

        bsjRun($batch);

        expect(bsjQuantity(bsjSent('POST')[0]))->toBe(7.5);
    });

    test('смена товара: перечитываются остатки обоих товаров', function () {
        $batch = bsjBatch($this, [], ['moysklad_move_id' => 'move-1']);
        bsjFake();

        $stock = Mockery::mock(\App\Services\Moysklad\StockSyncService::class);
        $stock->shouldReceive('refreshProducts')->once()->with(['ms-raw', 'ms-old']);
        app()->instance(\App\Services\Moysklad\StockSyncService::class, $stock);

        bsjRun($batch, 1, ['ms-old']);
    });
});

describe('Возврат', function () {

    test('на склад-источник: исходное уменьшается, отдельного перемещения нет', function () {
        $batch = bsjBatch($this, [], ['moysklad_move_id' => 'move-1']);
        Queue::fake();
        $result = app(RawMaterialBatchService::class)->returnToStore($batch, ['quantity' => 2.0, 'to_store_id' => $this->source->id]);
        app(RawMaterialBatchService::class)->queueSync($result['newBatch']);
        app(RawMaterialBatchService::class)->queueSync($batch);

        Queue::assertPushed(SyncBatchToMoysklad::class, 1);
        Queue::assertPushed(SyncBatchToMoysklad::class, fn ($job) => $job->batch->is($batch));

        bsjFake();
        bsjRun($batch);

        expect(bsjSent('POST'))->toBe([]);
        expect(bsjQuantity(bsjSent('PUT')[0]))->toBe(8.0);
        // Локально «количество» партии при возврате не меняется
        expect((float) $batch->fresh()->initial_quantity)->toBe(10.0);
        expect((float) $batch->fresh()->remaining_quantity)->toBe(8.0);
    });

    test('партия 10, распилили 3, вернули 2 на склад-источник → 8', function () {
        $batch = bsjBatch($this, ['remaining_quantity' => 7.0], ['moysklad_move_id' => 'move-1']);
        app(RawMaterialBatchService::class)->returnToStore($batch, ['quantity' => 2.0, 'to_store_id' => $this->source->id]);
        bsjFake();

        bsjRun($batch);

        expect(bsjQuantity(bsjSent('PUT')[0]))->toBe(8.0);
    });

    test('вся партия вернулась на склад-источник → 0.001', function () {
        $batch = bsjBatch($this, [], ['moysklad_move_id' => 'move-1']);
        app(RawMaterialBatchService::class)->returnToStore($batch, ['quantity' => 10.0, 'to_store_id' => $this->source->id]);
        bsjFake();

        bsjRun($batch);

        expect(bsjQuantity(bsjSent('PUT')[0]))->toBe(0.001);
    });

    test('на другой склад: исходное не меняется, отдельное перемещение из цеха', function () {
        $batch = bsjBatch($this, [], ['moysklad_move_id' => 'move-1']);
        $result = app(RawMaterialBatchService::class)->returnToStore($batch, ['quantity' => 2.0, 'to_store_id' => $this->other->id]);
        bsjFake();

        bsjRun($result['newBatch']);
        bsjRun($batch);

        $post = bsjSent('POST')[0];
        expect(bsjQuantity($post))->toBe(2.0);
        expect($post->data()['name'])->toStartWith('Возврат партии: №');
        expect(bsjQuantity(bsjSent('PUT')[0]))->toBe(10.0);
        expect($result['newBatch']->fresh()->primaryMovement()->moysklad_move_id)->toBe('move-new');
    });

    test('старая партия с возвратом: при повторе исходное возвращается к полному количеству', function () {
        // Возврат до 4.0.2: своё перемещение возврата есть, исходное занижено; флага нет
        $batch = bsjBatch($this, ['remaining_quantity' => 8.0], ['moysklad_move_id' => 'move-1']);
        RawMaterialMovement::create([
            'batch_id'      => $batch->id,
            'from_store_id' => $this->shop->id,
            'to_store_id'   => $this->source->id,
            'movement_type' => 'return_to_store',
            'quantity'      => 2.0,
        ]);
        bsjFake();

        bsjRun($batch);

        expect(bsjQuantity(bsjSent('PUT')[0]))->toBe(10.0);
    });

    test('возврат, вошедший в исходное: своего перемещения нет, кнопки повтора нет', function () {
        $batch = bsjBatch($this, [], ['moysklad_move_id' => 'move-1']);
        ['newBatch' => $child] = app(RawMaterialBatchService::class)
            ->returnToStore($batch, ['quantity' => 2.0, 'to_store_id' => $this->source->id]);

        expect($child->moveBlockReason())->toBe('Возврат учтён в исходном перемещении партии');

        $this->actingAs(H::adminUser())
            ->get(route('raw-batches.show', $child))
            ->assertOk()
            ->assertDontSee(route('raw-batches.sync', $child));
    });
});

describe('Идемпотентность создания', function () {

    test('ответ на POST потерялся → повтор находит перемещение, второе не создаёт', function () {
        $batch = bsjBatch($this);
        bsjFake(['lookup' => Http::response(['rows' => [['id' => 'move-lost', 'name' => 'Партия: 42_01']]], 200)]);

        bsjRun($batch, 2);

        expect(bsjSent('POST'))->toBe([]);
        $put = bsjSent('PUT')[0];
        expect($put->url())->toEndWith('/entity/move/move-lost');
        expect($put->data()['name'])->toBe('Партия: 42_01');
        expect($batch->fresh()->moysklad_processing_id)->toBe('move-lost');
        expect($batch->fresh()->primaryMovement()->moysklad_move_id)->toBe('move-lost');
    });

    test('поиск не удался → POST не отправляется, задача повторится', function () {
        $batch = bsjBatch($this);
        bsjFake(['lookup' => Http::response(['errors' => [['error' => 'down']]], 503)]);

        bsjRun($batch)->assertReleased(30);

        expect(bsjSent('POST'))->toBe([]);
        expect($batch->fresh()->isSyncPending())->toBeTrue();
    });

    test('занятое имя: суффикс подбирается и попадает в номер партии', function () {
        $batch = bsjBatch($this);
        $taken = Http::response(['errors' => [['error' => 'Имя занято', 'parameter' => 'name']]], 412);
        $posts = 0;
        bsjFake(['post' => function () use (&$posts, $taken) {
            return ++$posts <= 2 ? $taken : Http::response(['id' => 'move-new'], 200);
        }]);

        bsjRun($batch);

        expect(array_map(fn ($post) => $post->data()['name'], bsjSent('POST')))
            ->toBe(['Партия: 42', 'Партия: 42_01', 'Партия: 42_02']);
        expect($batch->fresh()->batch_number)->toBe('42_02');
        expect($batch->fresh()->moysklad_processing_name)->toBe('Партия: 42_02');
    });

    test('PUT шлёт сохранённое имя: с суффиксом не упадёт на занятом', function () {
        $batch = bsjBatch($this, ['moysklad_processing_name' => 'Партия: 42_01'], ['moysklad_move_id' => 'move-1']);
        bsjFake();

        bsjRun($batch);

        expect(bsjSent('PUT')[0]->data()['name'])->toBe('Партия: 42_01');
    });

    test('без id у движения берётся id из партии', function () {
        $batch = bsjBatch($this, ['moysklad_processing_id' => 'move-legacy']);
        bsjFake();

        bsjRun($batch);

        expect(bsjSent('POST'))->toBe([]);
        expect(bsjSent('PUT')[0]->url())->toEndWith('/entity/move/move-legacy');
    });

    test('партия, созданная передачей, правит своё первичное движение', function () {
        $batch = bsjBatch($this, [], ['movement_type' => 'transfer_to_worker', 'moysklad_move_id' => 'move-tr']);
        bsjFake();

        bsjRun($batch);

        expect(bsjSent('PUT')[0]->url())->toEndWith('/entity/move/move-tr');
    });

    test('первичное движение — create, даже если передача раньше по дате', function () {
        $batch = bsjBatch($this, [], ['moysklad_move_id' => 'move-create', 'created_at' => now()]);
        RawMaterialMovement::create([
            'batch_id'      => $batch->id,
            'from_store_id' => $this->source->id,
            'to_store_id'   => $this->shop->id,
            'movement_type' => 'transfer_to_worker',
            'quantity'      => 1.0,
            'created_at'    => now()->subDay(),
        ]);

        expect($batch->primaryMovement()->moysklad_move_id)->toBe('move-create');
    });
});

describe('Повторы и ошибки', function () {

    test('5xx → повтор с паузой, партия «Ожидает»', function () {
        $batch = bsjBatch($this);
        bsjFake(['post' => Http::response(['errors' => [['error' => 'boom']]], 500)]);

        bsjRun($batch)->assertReleased(30);

        expect($batch->fresh()->isSyncPending())->toBeTrue();
    });

    test('таймаут ответа → повтор', function () {
        $batch = bsjBatch($this);
        bsjFake(['post' => fn () => throw new ConnectionException('cURL error 28: timed out')]);

        bsjRun($batch)->assertReleased();

        expect($batch->fresh()->isSyncPending())->toBeTrue();
    });

    test('МойСклад недоступен на обновлении → повтор, а не постоянная ошибка', function () {
        $batch = bsjBatch($this, [], ['moysklad_move_id' => 'move-1']);
        Http::fake(['*' => Http::response(['errors' => [['error' => 'down']]], 503)]);

        bsjRun($batch)->assertReleased();

        expect($batch->fresh()->isSyncPending())->toBeTrue();
    });

    test('4xx → сразу ошибка в карточке, без повтора', function () {
        $batch = bsjBatch($this, [], ['moysklad_move_id' => 'move-1']);
        bsjFake(['put' => Http::response(['errors' => [['error' => 'Заблокирован']]], 423)]);

        bsjRun($batch)->assertNotReleased();

        $fresh = $batch->fresh();
        expect($fresh->moysklad_sync_status)->toBe(RawMaterialBatch::SYNC_STATUS_NOT_SYNCED);
        expect($fresh->moysklad_sync_error)->toBe('Ошибка МойСклад: Заблокирован');
    });

    test('последняя попытка → ошибка, больше не повторяется', function () {
        $batch = bsjBatch($this);
        bsjFake(['post' => Http::response(['errors' => [['error' => 'boom']]], 500)]);

        bsjRun($batch, 6)->assertNotReleased();

        expect($batch->fresh()->moysklad_sync_error)->toBe('Ошибка МойСклад: boom');
    });

    test('падение воркера → failed() пишет ошибку', function () {
        $batch = bsjBatch($this);
        $batch->markSyncPending();

        (new SyncBatchToMoysklad($batch->fresh()))->failed(new RuntimeException('timeout'));

        expect($batch->fresh()->isSyncPending())->toBeFalse();
        expect($batch->fresh()->moysklad_sync_error)->toContain('timeout');
    });

    test('успех без id перемещения → ошибка, а не TypeError', function () {
        $batch = bsjBatch($this);
        bsjFake(['post' => Http::response(['name' => 'без id'], 200)]);

        bsjRun($batch);

        expect($batch->fresh()->moysklad_sync_error)->toBe('МойСклад не вернул id перемещения');
    });

    test('нет складов у движения → ошибка без запросов на перемещение', function () {
        $batch = bsjBatch($this, ['moysklad_processing_id' => 'move-1'], ['from_store_id' => null]);
        bsjFake();

        bsjRun($batch);

        expect(bsjSent('PUT'))->toBe([]);
        expect($batch->fresh()->moysklad_sync_error)->toBe('Нет складов перемещения');
    });

    test('партию удалили, пока задача ждала: задача снимается', function () {
        expect((new SyncBatchToMoysklad(bsjBatch($this)))->deleteWhenMissingModels)->toBeTrue();
    });
});

describe('Постановка в очередь', function () {

    beforeEach(fn () => Queue::fake());

    test('создание партии: ответ не ждёт МойСклад, партия «Ожидает»', function () {
        Http::fake();

        $response = $this->actingAs(H::adminUser())->post(route('raw-batches.store'), [
            'product_id'    => $this->raw->id,
            'quantity'      => 5,
            'from_store_id' => $this->source->id,
            'to_store_id'   => $this->shop->id,
            'worker_id'     => $this->worker->id,
            'department_id' => H::departmentWithModifiers()->id,
        ]);

        $response->assertRedirect();
        Http::assertNothingSent();

        $batch = RawMaterialBatch::sole();
        expect($batch->isSyncPending())->toBeTrue();
        Queue::assertPushed(SyncBatchToMoysklad::class, fn ($job) => $job->batch->is($batch));
    });

    test('передача: сперва новая партия, затем родитель', function () {
        $batch = bsjBatch($this, ['status' => RawMaterialBatch::STATUS_NEW]);

        $this->actingAs(H::adminUser())
            ->post(route('raw-batches.transfer', $batch), ['to_worker_id' => H::cutter()->id, 'quantity' => 4])
            ->assertRedirect();

        $order = Queue::pushedJobs()[SyncBatchToMoysklad::class];
        expect(array_map(fn ($entry) => $entry['job']->batch->id, $order))
            ->toBe([RawMaterialBatch::whereKeyNot($batch->id)->sole()->id, $batch->id]);
    });

    test('возврат на склад-источник: задача только у родителя', function () {
        $batch = bsjBatch($this, ['status' => RawMaterialBatch::STATUS_NEW]);

        $this->actingAs(H::adminUser())
            ->post(route('raw-batches.return', $batch), ['to_store_id' => $this->source->id, 'quantity' => 2])
            ->assertRedirect();

        Queue::assertPushed(SyncBatchToMoysklad::class, 1);
        Queue::assertPushed(SyncBatchToMoysklad::class, fn ($job) => $job->batch->is($batch));
    });

    test('возврат на другой склад: задачи у новой партии и у родителя', function () {
        $batch = bsjBatch($this, ['status' => RawMaterialBatch::STATUS_NEW]);

        $this->actingAs(H::adminUser())
            ->post(route('raw-batches.return', $batch), ['to_store_id' => $this->other->id, 'quantity' => 2])
            ->assertRedirect();

        Queue::assertPushed(SyncBatchToMoysklad::class, 2);
    });

    test('смена товара: прежний товар едет в задаче', function () {
        $batch = bsjBatch($this, ['status' => RawMaterialBatch::STATUS_NEW]);
        $new   = Product::factory()->create(['moysklad_id' => 'ms-new']);

        $this->actingAs(H::adminUser())
            ->put(route('raw-batches.update', $batch), ['product_id' => $new->id, 'quantity' => 10])
            ->assertRedirect();

        Queue::assertPushed(SyncBatchToMoysklad::class, fn ($job) => $job->alsoRefresh === ['ms-raw']);
    });

    test('товар без moysklad_id: не ставится и не остаётся в «Ожидает»', function () {
        $product = Product::factory()->create(['moysklad_id' => null]);
        $batch   = bsjBatch($this, ['product_id' => $product->id]);

        app(RawMaterialBatchService::class)->queueSync($batch);

        Queue::assertNothingPushed();
        expect($batch->fresh()->moysklad_sync_status)->toBeNull();
    });

    test('удаление новой партии: задача со значениями', function () {
        $batch      = bsjBatch($this, ['status' => RawMaterialBatch::STATUS_NEW], ['moysklad_move_id' => 'move-1']);
        $movementId = $batch->primaryMovement()->id;

        app(RawMaterialBatchService::class)->deleteNew($batch);

        expect(RawMaterialBatch::find($batch->id))->toBeNull();
        Queue::assertPushed(DeleteMoveInMoysklad::class, fn ($job) => $job->moveId === 'move-1'
            && $job->externalCode === DocumentNaming::externalCode('movement', $movementId)
            && $job->moyskladIds === ['ms-raw']);
    });

    test('удаление партии товара без moysklad_id: в МойСклад удалять нечего', function () {
        $product = Product::factory()->create(['moysklad_id' => null]);
        $batch   = bsjBatch($this, ['product_id' => $product->id, 'status' => RawMaterialBatch::STATUS_NEW]);

        app(RawMaterialBatchService::class)->deleteNew($batch);

        Queue::assertNothingPushed();
    });
});

describe('Удаление перемещения', function () {

    function bsjDelete(?string $moveId, int $attempt = 1): DeleteMoveInMoysklad
    {
        $job = (new DeleteMoveInMoysklad($moveId, 'movement_1', ['ms-raw']))->withFakeQueueInteractions();
        $job->job->attempts = $attempt;
        $job->handle(app(RawMaterialBatchSyncService::class));

        return $job;
    }

    test('с id: удаляет и перечитывает остатки', function () {
        bsjFake();

        bsjDelete('move-1')->assertNotFailed();

        expect(bsjSent('DELETE')[0]->url())->toEndWith('/entity/move/move-1');
        expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'report/stock/bystore'))->count())->toBe(1);
    });

    test('без id: находит по внешнему коду', function () {
        bsjFake(['lookup' => Http::response(['rows' => [['id' => 'move-found']]], 200)]);

        bsjDelete(null);

        expect(bsjSent('DELETE')[0]->url())->toEndWith('/entity/move/move-found');
    });

    test('без id и в МойСклад нет: успех без удаления', function () {
        bsjFake();

        $job = bsjDelete(null);

        $job->assertNotFailed();
        $job->assertNotReleased();
        expect(bsjSent('DELETE'))->toBe([]);
    });

    test('404: уже удалено прошлой попыткой — успех', function () {
        bsjFake(['delete' => Http::response(['errors' => [['error' => 'Не найдено']]], 404)]);

        bsjDelete('move-1')->assertNotFailed();
    });

    test('5xx → повтор, остатки не перечитываются', function () {
        bsjFake(['delete' => Http::response(['errors' => [['error' => 'boom']]], 500)]);

        bsjDelete('move-1')->assertReleased(30);

        expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'report/stock/bystore'))->count())->toBe(0);
    });

    test('постоянная ошибка и последняя попытка → failed', function () {
        bsjFake(['delete' => Http::response(['errors' => [['error' => 'Проведён']]], 412)]);
        bsjDelete('move-1')->assertFailed();

        bsjFake(['delete' => Http::response(['errors' => [['error' => 'boom']]], 500)]);
        bsjDelete('move-1', 6)->assertFailed();
    });
});
