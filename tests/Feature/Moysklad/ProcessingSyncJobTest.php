<?php

use App\Jobs\DeleteProcessingInMoysklad;
use App\Jobs\SyncReceptionToMoysklad;
use App\Jobs\SyncWorkshopToMoysklad;
use App\Models\Setting;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use App\Models\Store;
use App\Models\Worker;
use App\Models\Workshop;
use App\Models\WorkshopItem;
use App\Models\Product;
use App\Services\StoneReceptionService;
use App\Services\WorkshopService;
use App\Support\DocumentNaming;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Helpers\ReceptionTestHelper as H;

/*
 * Отправка приёмок и цеха в МойСклад через очередь: повторы при временных сбоях, идемпотентность
 * создания и приведение статуса техоперации к статусу документа. Правила —
 * docs/moysklad-sync-pattern.md.
 */

const PSJ_DONE_HREF = 'https://ms.test/entity/processing/metadata/states/done';
const PSJ_WORK_HREF = 'https://ms.test/entity/processing/metadata/states/work';

beforeEach(function () {
    Cache::flush();
    config()->set('services.moysklad.token', 'test-token');
    config()->set('services.moysklad.base_url', 'https://ms.test');

    Setting::set('MOYSKLAD_DONE_STATE', 'Готово');
    Setting::set('MOYSKLAD_IN_WORK_STATE', 'В работе');

    $store = H::store();
    $batch = H::batch(H::product(['moysklad_id' => 'ms-raw']), $store, H::cutter(), 5.0);

    $this->reception = H::reception($batch, H::worker('Приёмщик'), H::cutter(), $store, 5.0);
    StoneReceptionItem::create([
        'stone_reception_id' => $this->reception->id,
        'product_id'         => H::product(['moysklad_id' => 'ms-out'])->id,
        'quantity'           => 2.0,
    ]);
});

/** Ответ на GET справочника: организация читается из rows, склад и товар — из meta. */
function psjEntity()
{
    $meta = ['href' => 'h', 'type' => 't', 'mediaType' => 'application/json'];

    return Http::response(['meta' => $meta, 'rows' => [['meta' => $meta]]], 200);
}

/**
 * Фейк МойСклад. Переопределения:
 *  lookup — ответ на поиск по externalCode, post — на создание,
 *  putItems / putState — на обновление позиций / статуса, delete — на удаление.
 */
function psjFake(array $responses = []): void
{
    Http::fake(function (Request $request) use ($responses) {
        $url = $request->url();

        if (str_contains($url, 'report/stock/bystore')) {
            return Http::response(['rows' => []], 200);
        }
        if (str_contains($url, '/entity/processing/metadata')) {
            return Http::response(['states' => [
                ['name' => 'В работе', 'meta' => ['href' => PSJ_WORK_HREF]],
                ['name' => 'Готово',   'meta' => ['href' => PSJ_DONE_HREF]],
            ]], 200);
        }
        if ($request->method() === 'DELETE') {
            return $responses['delete'] ?? Http::response(null, 200);
        }
        if ($request->method() === 'POST') {
            return $responses['post'] ?? Http::response(['id' => 'proc-new', 'name' => '26-44-ТО-01'], 200);
        }
        if ($request->method() === 'PUT') {
            return isset($request->data()['state'])
                ? ($responses['putState'] ?? Http::response(['id' => 'proc-1'], 200))
                : ($responses['putItems'] ?? Http::response(['id' => 'proc-1'], 200));
        }
        if (str_contains($url, 'filter=externalCode')) {
            return $responses['lookup'] ?? Http::response(['rows' => []], 200);
        }

        return psjEntity();
    });
}

function psjCount(string $method, ?callable $filter = null): int
{
    return Http::recorded(fn (Request $r) => $r->method() === $method && (!$filter || $filter($r)))->count();
}

function psjStates(): array
{
    return Http::recorded(fn (Request $r) => $r->method() === 'PUT' && isset($r->data()['state']))
        ->map(fn ($pair) => $pair[0]->data()['state']['meta']['href'])
        ->values()
        ->all();
}

/** Запустить задачу приёмки как попытку №$attempt. */
function psjRun(StoneReception $reception, int $attempt = 1): SyncReceptionToMoysklad
{
    $job = (new SyncReceptionToMoysklad($reception->fresh()))->withFakeQueueInteractions();
    $job->job->attempts = $attempt;
    $job->handle(app(\App\Services\Moysklad\StoneReceptionSyncService::class));

    return $job;
}

describe('Повторы', function () {

    test('5xx на создании → повтор с паузой, документ остаётся «Ожидает»', function () {
        psjFake(['post' => Http::response(['errors' => [['error' => 'boom']]], 500)]);

        $job = psjRun($this->reception);

        $job->assertReleased(30);
        expect($this->reception->fresh()->isSyncPending())->toBeTrue();
    });

    test('таймаут ответа → повтор', function () {
        Http::fake(function (Request $request) {
            if ($request->method() === 'POST') {
                throw new ConnectionException('cURL error 28: timed out');
            }
            if (str_contains($request->url(), 'filter=externalCode')) {
                return Http::response(['rows' => []], 200);
            }

            return psjEntity();
        });

        psjRun($this->reception)->assertReleased();

        expect($this->reception->fresh()->isSyncPending())->toBeTrue();
    });

    test('МойСклад недоступен на обновлении → повтор, а не постоянная ошибка', function () {
        // get() глотает сбой: без отдельного признака «не удалось получить склад» выглядело бы постоянной ошибкой
        $this->reception->update(['moysklad_processing_id' => 'proc-1']);
        Http::fake(['*' => Http::response(['errors' => [['error' => 'down']]], 503)]);

        psjRun($this->reception)->assertReleased();

        expect($this->reception->fresh()->isSyncPending())->toBeTrue();
    });

    test('4xx → сразу ошибка, без повтора', function () {
        psjFake(['post' => Http::response(['errors' => [['error' => 'Товар удалён']]], 412)]);

        $job = psjRun($this->reception);

        $job->assertNotReleased();
        $fresh = $this->reception->fresh();
        expect($fresh->moysklad_sync_status)->toBe(StoneReception::SYNC_STATUS_NOT_SYNCED);
        expect($fresh->moysklad_sync_error)->toBe('Ошибка МойСклад: Товар удалён');
    });

    test('последняя попытка → ошибка на документе, больше не повторяется', function () {
        psjFake(['post' => Http::response(['errors' => [['error' => 'boom']]], 500)]);

        $job = psjRun($this->reception, 6);

        $job->assertNotReleased();
        expect($this->reception->fresh()->moysklad_sync_error)->toBe('Ошибка МойСклад: boom');
    });

    test('падение воркера → failed() пишет ошибку, документ не зависает в «Ожидает»', function () {
        $this->reception->markSyncPending();

        (new SyncReceptionToMoysklad($this->reception->fresh()))->failed(new RuntimeException('timeout'));

        $fresh = $this->reception->fresh();
        expect($fresh->isSyncPending())->toBeFalse();
        expect($fresh->moysklad_sync_error)->toContain('timeout');
    });

    test('приёмку удалили, пока задача ждала: задача снимается', function () {
        $job = new SyncReceptionToMoysklad($this->reception);

        expect($job->deleteWhenMissingModels)->toBeTrue();
    });
});

describe('Идемпотентность создания', function () {

    test('POST содержит внешний код документа', function () {
        psjFake();

        psjRun($this->reception);

        $post = Http::recorded(fn (Request $r) => $r->method() === 'POST')->first()[0];
        expect($post->data()['externalCode'])->toBe(DocumentNaming::externalCode('reception', $this->reception->id));
    });

    test('ответ на POST потерялся → повтор находит техоперацию, второй не создаёт', function () {
        psjFake(['lookup' => Http::response(['rows' => [['id' => 'proc-lost', 'name' => '26-44-ТО-07']]], 200)]);

        psjRun($this->reception, 2)->assertNotReleased();

        expect(psjCount('POST'))->toBe(0);
        $fresh = $this->reception->fresh();
        expect($fresh->moysklad_processing_id)->toBe('proc-lost');
        expect($fresh->moysklad_processing_name)->toBe('26-44-ТО-07');
        expect($fresh->isSynced())->toBeTrue();
        expect(psjCount('PUT', fn (Request $r) => str_ends_with($r->url(), '/entity/processing/proc-lost')))->toBeGreaterThan(0);
    });

    test('поиск не удался → POST не отправляется, задача повторится', function () {
        psjFake(['lookup' => Http::response(['errors' => [['error' => 'down']]], 503)]);

        psjRun($this->reception)->assertReleased();

        expect(psjCount('POST'))->toBe(0);
    });

    test('вне прода код содержит окружение: локальная база не заденет прод-документы', function () {
        expect(DocumentNaming::externalCode('reception', 5))->toBe('testing_reception_5');

        app()->detectEnvironment(fn () => 'production');
        expect(DocumentNaming::externalCode('reception', 5))->toBe('reception_5');
        app()->detectEnvironment(fn () => 'testing');
    });
});

describe('Статус техоперации приводится к статусу документа', function () {

    test('новая активная приёмка: статус уходит в POST, отдельного PUT нет', function () {
        psjFake();

        psjRun($this->reception);

        expect(psjStates())->toBe([]);
        expect($this->reception->fresh()->moysklad_processing_id)->toBe('proc-new');
    });

    test('завершённая без техоперации: создаётся и сразу переводится в «Готово»', function () {
        $this->reception->update(['status' => StoneReception::STATUS_COMPLETED]);
        psjFake();

        psjRun($this->reception);

        expect(psjCount('POST'))->toBe(1);
        expect(psjStates())->toBe([PSJ_DONE_HREF]);
    });

    test('сброс: завершённая снова активна → техоперация возвращается в работу', function () {
        $this->reception->update(['moysklad_processing_id' => 'proc-1']);
        psjFake();

        psjRun($this->reception);

        expect(psjStates())->toBe([PSJ_WORK_HREF]);
    });

    test('ручной повтор у завершённой доводит техоперацию до «Готово»', function () {
        $this->reception->update(['moysklad_processing_id' => 'proc-1', 'status' => StoneReception::STATUS_COMPLETED]);
        $this->reception->markSyncError('Прошлая ошибка');
        psjFake();

        psjRun($this->reception);

        expect(psjStates())->toBe([PSJ_DONE_HREF]);
        expect($this->reception->fresh()->isSynced())->toBeTrue();
    });

    test('обновление позиций не прошло → статус не меняется, ошибка не затирается', function () {
        $this->reception->update(['moysklad_processing_id' => 'proc-1', 'status' => StoneReception::STATUS_COMPLETED]);
        psjFake(['putItems' => Http::response(['errors' => [['error' => 'Товар удалён']]], 412)]);

        psjRun($this->reception);

        expect(psjStates())->toBe([]);
        expect($this->reception->fresh()->moysklad_sync_error)->toBe('Ошибка МойСклад: Товар удалён');
    });

    test('смена статуса не прошла → ошибка на документе', function () {
        $this->reception->update(['moysklad_processing_id' => 'proc-1', 'status' => StoneReception::STATUS_COMPLETED]);
        psjFake(['putState' => Http::response(['errors' => [['error' => 'Заблокирован']]], 423)]);

        psjRun($this->reception);

        expect($this->reception->fresh()->moysklad_sync_error)->toBe('Ошибка МойСклад: Заблокирован');
    });

    test('статус «в работе» не настроен → правка активной приёмки не считается ошибкой', function () {
        Setting::set('MOYSKLAD_IN_WORK_STATE', '');
        $this->reception->update(['moysklad_processing_id' => 'proc-1']);
        psjFake();

        psjRun($this->reception);

        expect(psjStates())->toBe([]);
        expect($this->reception->fresh()->isSynced())->toBeTrue();
    });
});

describe('Цех', function () {

    beforeEach(function () {
        $this->workshop = Workshop::create([
            'packer_id'   => Worker::create(['name' => 'Работник', 'position' => 'Работник'])->id,
            'receiver_id' => Worker::create(['name' => 'Мастер', 'position' => 'Мастер'])->id,
            'store_id'    => Store::factory()->create()->id,
            'status'      => Workshop::STATUS_COMPLETED,
        ]);
        WorkshopItem::create([
            'workshop_id' => $this->workshop->id,
            'product_id'  => Product::factory()->create(['moysklad_id' => 'ms-w-out'])->id,
            'role'        => WorkshopItem::ROLE_PRODUCT,
            'quantity'    => 2.0,
        ]);
    });

    function psjRunWorkshop(Workshop $workshop, int $attempt = 1): SyncWorkshopToMoysklad
    {
        $job = (new SyncWorkshopToMoysklad($workshop->fresh()))->withFakeQueueInteractions();
        $job->job->attempts = $attempt;
        $job->handle(app(\App\Services\Moysklad\WorkshopSyncService::class));

        return $job;
    }

    test('закрытая операция: создаётся и переводится в «Готово», внешний код свой', function () {
        psjFake();

        psjRunWorkshop($this->workshop);

        $post = Http::recorded(fn (Request $r) => $r->method() === 'POST')->first()[0];
        expect($post->data()['externalCode'])->toBe(DocumentNaming::externalCode('workshop', $this->workshop->id));
        expect(psjStates())->toBe([PSJ_DONE_HREF]);
        expect($this->workshop->fresh()->isSynced())->toBeTrue();
    });

    test('обновление не прошло → статус не меняется и ошибка не затирается', function () {
        // Раньше закрытие цеха меняло статус и после неудачного обновления, затирая ошибку
        $this->workshop->update(['moysklad_processing_id' => 'proc-1']);
        psjFake(['putItems' => Http::response(['errors' => [['error' => 'Товар удалён']]], 412)]);

        psjRunWorkshop($this->workshop);

        expect(psjStates())->toBe([]);
        expect($this->workshop->fresh()->moysklad_sync_error)->toBe('Ошибка МойСклад: Товар удалён');
    });

    test('потерянный POST: повтор находит техоперацию', function () {
        psjFake(['lookup' => Http::response(['rows' => [['id' => 'proc-lost', 'name' => 'ЦЕХ-7']]], 200)]);

        psjRunWorkshop($this->workshop, 2);

        expect(psjCount('POST'))->toBe(0);
        expect($this->workshop->fresh()->moysklad_processing_id)->toBe('proc-lost');
    });

    test('5xx → повтор, документ «Ожидает»', function () {
        psjFake(['post' => Http::response(['errors' => [['error' => 'boom']]], 500)]);

        psjRunWorkshop($this->workshop)->assertReleased(30);

        expect($this->workshop->fresh()->isSyncPending())->toBeTrue();
    });
});

describe('Удаление техоперации', function () {

    function psjDelete(?string $processingId, array $ids = ['ms-a'], int $attempt = 1): DeleteProcessingInMoysklad
    {
        $job = (new DeleteProcessingInMoysklad($processingId, 'reception_1', $ids))->withFakeQueueInteractions();
        $job->job->attempts = $attempt;
        $job->handle(app(\App\Services\Moysklad\ProcessingDeleteService::class));

        return $job;
    }

    test('с id: удаляет и перечитывает остатки', function () {
        psjFake();

        psjDelete('proc-1')->assertNotFailed();

        expect(psjCount('DELETE', fn (Request $r) => str_ends_with($r->url(), '/entity/processing/proc-1')))->toBe(1);
        expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'report/stock/bystore'))->count())->toBe(1);
    });

    test('без id: находит по внешнему коду и удаляет', function () {
        psjFake(['lookup' => Http::response(['rows' => [['id' => 'proc-found']]], 200)]);

        psjDelete(null);

        expect(psjCount('DELETE', fn (Request $r) => str_ends_with($r->url(), '/entity/processing/proc-found')))->toBe(1);
    });

    test('без id и в МойСклад нет: ничего не удаляет, успех', function () {
        psjFake();

        $job = psjDelete(null);

        $job->assertNotFailed();
        $job->assertNotReleased();
        expect(psjCount('DELETE'))->toBe(0);
    });

    test('404: уже удалена прошлой попыткой — успех', function () {
        psjFake(['delete' => Http::response(['errors' => [['error' => 'Не найдено']]], 404)]);

        psjDelete('proc-1')->assertNotFailed();
    });

    test('5xx → повтор, остатки не перечитываются', function () {
        psjFake(['delete' => Http::response(['errors' => [['error' => 'boom']]], 500)]);

        psjDelete('proc-1')->assertReleased(30);

        expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'report/stock/bystore'))->count())->toBe(0);
    });

    test('постоянная ошибка или последняя попытка → failed, виден в логе и failed_jobs', function () {
        psjFake(['delete' => Http::response(['errors' => [['error' => 'Документ проведён']]], 412)]);
        psjDelete('proc-1')->assertFailed();

        psjFake(['delete' => Http::response(['errors' => [['error' => 'boom']]], 500)]);
        psjDelete('proc-1', ['ms-a'], 6)->assertFailed();
    });
});

describe('Постановка в очередь', function () {

    beforeEach(fn () => Queue::fake());

    test('завершение приёмки: локально сразу, документ «Ожидает», запросов в МойСклад нет', function () {
        Http::fake();

        app(StoneReceptionService::class)->markCompleted($this->reception);

        $fresh = $this->reception->fresh();
        expect($fresh->status)->toBe(StoneReception::STATUS_COMPLETED);
        expect($fresh->isSyncPending())->toBeTrue();
        Http::assertNothingSent();
        Queue::assertPushed(SyncReceptionToMoysklad::class);
    });

    test('приёмка без партии и техоперации: завершается, отправлять нечего', function () {
        $this->reception->update(['raw_material_batch_id' => null]);

        app(StoneReceptionService::class)->markCompleted($this->reception);

        expect($this->reception->fresh()->moysklad_sync_status)->toBeNull();
        Queue::assertNothingPushed();
    });

    test('сброс приёмки без техоперации в очередь не ставится', function () {
        $this->reception->update(['status' => StoneReception::STATUS_COMPLETED]);

        app(StoneReceptionService::class)->resetStatus($this->reception);

        Queue::assertNothingPushed();
    });

    test('удаление приёмки: значения вместо модели, с товарами для остатков', function () {
        $id = $this->reception->id;
        $this->reception->update(['moysklad_processing_id' => 'proc-1']);

        app(StoneReceptionService::class)->delete($this->reception);

        Queue::assertPushed(DeleteProcessingInMoysklad::class, fn ($job) => $job->processingId === 'proc-1'
            && $job->externalCode === DocumentNaming::externalCode('reception', $id)
            && collect($job->moyskladIds)->sort()->values()->all() === ['ms-out', 'ms-raw']);
    });

    test('ручной повтор цеха ставит задачу', function () {
        $workshop = Workshop::create([
            'packer_id'   => Worker::create(['name' => 'Работник', 'position' => 'Работник'])->id,
            'receiver_id' => Worker::create(['name' => 'Мастер', 'position' => 'Мастер'])->id,
            'store_id'    => Store::factory()->create()->id,
            'status'      => Workshop::STATUS_ACTIVE,
        ]);

        $result = app(WorkshopService::class)->syncToProcessing($workshop);

        expect($result['success'])->toBeTrue();
        expect($workshop->fresh()->isSyncPending())->toBeTrue();
        Queue::assertPushed(SyncWorkshopToMoysklad::class);
    });
});

describe('Бейдж «Ожидает»', function () {

    test('подпись и цвет отличаются от ошибки', function () {
        $this->reception->markSyncPending();

        $fresh = $this->reception->fresh();
        expect($fresh->syncStatusLabel())->toBe('Ожидает');
        expect($fresh->syncStatusBadgeClass())->toBe('bg-secondary');
        expect($fresh->isSynced())->toBeFalse();
        expect($fresh->hasSyncError())->toBeFalse();
    });

    test('карточка показывает ожидание, кнопка повтора остаётся', function () {
        $this->reception->markSyncPending();

        $this->actingAs(H::adminUser())
            ->get(route('stone-receptions.show', $this->reception))
            ->assertOk()
            ->assertSee('Ожидает отправки в МойСклад')
            ->assertSee(route('stone-receptions.sync', $this->reception));
    });
});
