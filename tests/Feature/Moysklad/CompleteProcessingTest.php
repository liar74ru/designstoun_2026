<?php

use App\Models\RawMaterialBatch;
use App\Models\Setting;
use App\Models\StoneReception;
use App\Services\Moysklad\StoneReceptionSyncService;
use App\Services\Moysklad\WorkshopSyncService;
use App\Services\StoneReceptionService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\ReceptionTestHelper as H;

// ══════════════════════════════════════════════════════════════════════════════
// HandlesProcessingSync — completeProcessing() / reactivateProcessing()
// Имя статуса берётся из Setting MOYSKLAD_DONE_STATE / MOYSKLAD_IN_WORK_STATE,
// href — из /entity/processing/metadata.
// ══════════════════════════════════════════════════════════════════════════════

const CPT_DONE_HREF    = 'https://api.moysklad.ru/api/remap/1.2/entity/processing/metadata/states/done-uuid';
const CPT_IN_WORK_HREF = 'https://api.moysklad.ru/api/remap/1.2/entity/processing/metadata/states/work-uuid';

beforeEach(function () {
    Cache::flush();
    config()->set('services.moysklad.token', 'test-token');
    config()->set('services.moysklad.base_url', 'https://api.moysklad.ru/api/remap/1.2');

    Setting::set('MOYSKLAD_DONE_STATE', 'Готово');
    Setting::set('MOYSKLAD_IN_WORK_STATE', 'В работе');
});

function cptFake($putResponse = null, bool $metadataOk = true): void
{
    Http::fake(function (Request $request) use ($putResponse, $metadataOk) {
        if (str_contains($request->url(), '/entity/processing/metadata')) {
            return $metadataOk
                ? Http::response(['states' => [
                    ['name' => 'В работе', 'meta' => ['href' => CPT_IN_WORK_HREF]],
                    ['name' => 'Готово',   'meta' => ['href' => CPT_DONE_HREF]],
                ]], 200)
                : Http::response(['errors' => [['error' => 'Сервис недоступен']]], 503);
        }
        if ($request->method() === 'PUT') {
            return $putResponse ?? Http::response(['id' => 'proc-1'], 200);
        }

        return Http::response(['errors' => [['error' => 'unexpected ' . $request->url()]]], 500);
    });
}

function cptSentState(): ?array
{
    $put = Http::recorded(fn (Request $r) => $r->method() === 'PUT')->first();

    return $put ? $put[0]->data()['state'] : null;
}

describe('HandlesProcessingSync::completeProcessing()', function () {

    test('переводит техоперацию в завершающий статус: PUT только со state', function (string $serviceClass) {
        cptFake();

        $result = app($serviceClass)->completeProcessing('proc-1');

        expect($result)->toBe(['success' => true, 'code' => '', 'message' => 'Статус техоперации обновлён']);

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/entity/processing/proc-1')
            && array_keys($r->data()) === ['state']);

        expect(cptSentState())->toBe(['meta' => [
            'href'      => CPT_DONE_HREF,
            'type'      => 'state',
            'mediaType' => 'application/json',
        ]]);
    })->with([
        'приёмка' => StoneReceptionSyncService::class,
        'цех'     => WorkshopSyncService::class,
    ]);

    test('метаданные статусов загружаются один раз на экземпляр сервиса', function () {
        cptFake();

        $service = app(StoneReceptionSyncService::class);
        $service->completeProcessing('proc-1');
        $service->completeProcessing('proc-2');

        expect(Http::recorded(fn (Request $r) => str_contains($r->url(), '/metadata')))->toHaveCount(1);
        expect(Http::recorded(fn (Request $r) => $r->method() === 'PUT'))->toHaveCount(2);
    });

    test('ошибка МойСклад → api_error с текстом из errors[0].error', function () {
        cptFake(Http::response(['errors' => [['error' => 'Статус недоступен для документа']]], 412));

        $result = app(StoneReceptionSyncService::class)->completeProcessing('proc-1');

        expect($result)->toBe([
            'success' => false,
            'code'    => 'api_error',
            'message' => 'Ошибка МойСклад: Статус недоступен для документа',
        ]);
    });

    test('ошибка без поля error → берётся title', function () {
        cptFake(Http::response(['errors' => [['title' => 'Только заголовок']]], 400));

        $result = app(StoneReceptionSyncService::class)->completeProcessing('proc-1');

        expect($result['message'])->toBe('Ошибка МойСклад: Только заголовок');
    });

    test('статус с таким именем не найден → exception, PUT не отправляется', function () {
        Setting::set('MOYSKLAD_DONE_STATE', 'Несуществующий');
        cptFake();

        $result = app(StoneReceptionSyncService::class)->completeProcessing('proc-1');

        expect($result['code'])->toBe('exception');
        expect($result['message'])->toBe('Ошибка: Статус «Несуществующий» не найден в МойСклад');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
    });

    test('метаданные не загрузились → статус не найден', function () {
        cptFake(null, metadataOk: false);

        $result = app(WorkshopSyncService::class)->completeProcessing('proc-1');

        expect($result['success'])->toBeFalse();
        expect($result['message'])->toBe('Ошибка: Статус «Готово» не найден в МойСклад');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
    });

    test('имя статуса не задано в настройках → exception, запросы не уходят', function () {
        Setting::set('MOYSKLAD_DONE_STATE', '');
        Http::fake();

        $result = app(StoneReceptionSyncService::class)->completeProcessing('proc-1');

        expect($result['code'])->toBe('exception');
        expect($result['message'])->toBe('Ошибка: Не задано имя статуса для контекста completeProcessing');
        Http::assertNothingSent();
    });

    test('без токена → exception, запросы не уходят', function () {
        config()->set('services.moysklad.token', '');
        Http::fake();

        $result = app(StoneReceptionSyncService::class)->completeProcessing('proc-1');

        expect($result['message'])->toBe('Ошибка: MoySklad токен не установлен');
        Http::assertNothingSent();
    });
});

describe('HandlesProcessingSync::reactivateProcessing()', function () {

    test('возвращает техоперацию в статус «В работе»', function () {
        cptFake();

        $result = app(WorkshopSyncService::class)->reactivateProcessing('proc-1');

        expect($result['success'])->toBeTrue();
        expect(cptSentState()['meta']['href'])->toBe(CPT_IN_WORK_HREF);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// StoneReceptionService::closeBatch() — партия закрыта → техоперации завершаются
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionService::closeBatch() → completeProcessing()', function () {

    beforeEach(function () {
        $store    = H::store();
        $receiver = H::worker('Приёмщик');
        $cutter   = H::cutter();
        $raw      = H::product(['name' => 'Сырьё']);

        $this->batch     = H::batch($raw, $store, $cutter, 5.0);
        $this->reception = H::reception($this->batch, $receiver, $cutter, $store, 5.0, [
            'moysklad_processing_id' => 'proc-1',
            'moysklad_sync_status'   => StoneReception::SYNC_STATUS_SYNCED,
        ]);
    });

    test('израсходованная партия → used, приёмка completed, техоперация в «Готово»', function () {
        cptFake();

        expect(app(StoneReceptionService::class)->closeBatch($this->batch->fresh()))->toBeTrue();

        expect($this->batch->fresh()->status)->toBe(RawMaterialBatch::STATUS_USED);

        $reception = $this->reception->fresh();
        expect($reception->status)->toBe(StoneReception::STATUS_COMPLETED);
        expect($reception->isSynced())->toBeTrue();
        expect(cptSentState()['meta']['href'])->toBe(CPT_DONE_HREF);
    });

    test('ошибка МойСклад → приёмка завершена локально, ошибка в moysklad_sync_error', function () {
        cptFake(Http::response(['errors' => [['error' => 'Документ заблокирован']]], 423));

        app(StoneReceptionService::class)->closeBatch($this->batch->fresh());

        $reception = $this->reception->fresh();
        expect($reception->status)->toBe(StoneReception::STATUS_COMPLETED);
        expect($reception->moysklad_sync_status)->toBe(StoneReception::SYNC_STATUS_NOT_SYNCED);
        expect($reception->moysklad_sync_error)->toBe('Ошибка МойСклад: Документ заблокирован');
    });

    test('приёмка с незакрытой ошибкой синхронизации не завершается в МойСклад', function () {
        $this->reception->markSyncError('позиции не доехали');
        Http::fake();

        app(StoneReceptionService::class)->closeBatch($this->batch->fresh());

        Http::assertNothingSent();
        expect($this->reception->fresh()->moysklad_sync_error)->toBe('позиции не доехали');
    });
});
