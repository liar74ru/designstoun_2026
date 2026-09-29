<?php

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

test('после сброса техоперация та же, операция активна и синхронизирована', function () {
    $sync = Mockery::mock(WorkshopSyncService::class);
    $sync->shouldReceive('reactivateProcessing')->once()->with('proc-1')->andReturn(['success' => true, 'message' => '']);

    $result = (new WorkshopService($sync))->resetStatus($this->workshop);

    expect($result)->toBeTrue();

    $workshop = $this->workshop->fresh();
    expect($workshop->status)->toBe(Workshop::STATUS_ACTIVE);
    expect($workshop->moysklad_processing_id)->toBe('proc-1');
    expect($workshop->moysklad_processing_name)->toBe('ЦЕХ-26-01');
    expect($workshop->moysklad_sync_status)->toBe(Workshop::SYNC_STATUS_SYNCED);
    expect($workshop->moysklad_sync_error)->toBeNull();
});

test('ошибка МойСклад при сбросе записывается в операцию, связь с техоперацией остаётся', function () {
    $sync = Mockery::mock(WorkshopSyncService::class);
    $sync->shouldReceive('reactivateProcessing')->once()->andReturn(['success' => false, 'message' => 'Статус не найден']);

    $result = (new WorkshopService($sync))->resetStatus($this->workshop);

    expect($result)->toContain('Статус не найден');

    $workshop = $this->workshop->fresh();
    expect($workshop->status)->toBe(Workshop::STATUS_ACTIVE);
    expect($workshop->moysklad_processing_id)->toBe('proc-1');
    expect($workshop->moysklad_sync_status)->toBe(Workshop::SYNC_STATUS_NOT_SYNCED);
    expect($workshop->moysklad_sync_error)->toBe('Статус не найден');
});

test('следующая синхронизация после сброса правит ту же техоперацию, а не создаёт новую', function () {
    // Настоящий syncWorkshop (выбор «создать / обновить»), подменены только запросы в МойСклад
    $stocks = Mockery::mock(StockSyncService::class);
    $stocks->shouldReceive('refreshProducts');
    $sync = Mockery::mock(WorkshopSyncService::class, [$stocks])->makePartial();
    $sync->shouldReceive('reactivateProcessing')->andReturn(['success' => true, 'message' => '']);
    $sync->shouldNotReceive('createProcessingForWorkshop');
    $sync->shouldReceive('updateProcessingProducts')->once()
        ->withArgs(fn (string $processingId) => $processingId === 'proc-1')
        ->andReturn(['success' => true, 'message' => '']);

    $service = new WorkshopService($sync);
    $service->resetStatus($this->workshop);
    $service->syncToProcessing($this->workshop->fresh());

    expect($this->workshop->fresh()->moysklad_processing_id)->toBe('proc-1');
});
