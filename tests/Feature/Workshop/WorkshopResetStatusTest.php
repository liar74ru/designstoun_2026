<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Queue;
use App\Jobs\SyncWorkshopToMoysklad;
use App\Models\Store;
use App\Models\Worker;
use App\Models\Workshop;
use App\Services\Moysklad\StockSyncService;
use App\Services\Moysklad\WorkshopSyncService;
use App\Services\WorkshopService;

/*
 * Сброс статуса закрытой операции цеха: техоперация в МойСклад переводится обратно
 * «в работу», и связь с ней сохраняется — иначе следующая синхронизация создала бы
 * вторую техоперацию, и остатки в МойСклад задвоились бы.
 */

beforeEach(function () {
    $this->workshop = Workshop::create([
        'packer_id'                => Worker::create(['name' => 'Работник', 'position' => 'Работник'])->id,
        'receiver_id'              => Worker::create(['name' => 'Мастер', 'position' => 'Мастер'])->id,
        'store_id'                 => Store::factory()->create()->id,
        'status'                   => Workshop::STATUS_COMPLETED,
        'moysklad_processing_id'   => 'proc-1',
        'moysklad_processing_name' => 'ЦЕХ-26-01',
        'moysklad_sync_status'     => Workshop::SYNC_STATUS_SYNCED,
    ]);
});

test('после сброса техоперация та же, операция активна и ждёт отправки', function () {
    Queue::fake();

    (new WorkshopService())->resetStatus($this->workshop);

    $workshop = $this->workshop->fresh();
    expect($workshop->status)->toBe(Workshop::STATUS_ACTIVE);
    expect($workshop->moysklad_processing_id)->toBe('proc-1');
    expect($workshop->moysklad_processing_name)->toBe('ЦЕХ-26-01');
    expect($workshop->isSyncPending())->toBeTrue();
    Queue::assertPushed(SyncWorkshopToMoysklad::class, fn ($job) => $job->workshop->is($this->workshop));
});

test('задача после сброса правит ту же техоперацию и возвращает её в работу', function () {
    // Настоящий syncWorkshop (выбор «создать / обновить»), подменены только запросы в МойСклад
    $stocks = Mockery::mock(StockSyncService::class);
    $stocks->shouldReceive('refreshProducts');
    $sync = Mockery::mock(WorkshopSyncService::class, [$stocks])->makePartial();
    $sync->shouldReceive('reactivateProcessing')->once()->with('proc-1')->andReturn(['success' => true, 'message' => '']);
    $sync->shouldNotReceive('createProcessingForWorkshop');
    $sync->shouldReceive('updateProcessingProducts')->once()
        ->withArgs(fn (string $processingId) => $processingId === 'proc-1')
        ->andReturn(['success' => true, 'message' => '']);
    app()->instance(WorkshopSyncService::class, $sync);
    Setting::set('MOYSKLAD_IN_WORK_STATE', 'В работе');

    (new WorkshopService())->resetStatus($this->workshop);

    expect($this->workshop->fresh()->moysklad_processing_id)->toBe('proc-1');
    expect($this->workshop->fresh()->isSynced())->toBeTrue();
});
