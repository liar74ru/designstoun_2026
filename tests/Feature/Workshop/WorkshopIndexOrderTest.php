<?php

use App\Models\Workshop;
use App\Models\Worker;
use Tests\Helpers\ReceptionTestHelper as H;

/**
 * Список цеха: по умолчанию видны все статусы, порядок групп —
 * незавершённые → закрытые с ошибкой синхронизации → закрытые.
 * Внутри группы новые сверху.
 */

beforeEach(function () {
    $this->store  = H::store();
    $this->packer = Worker::create(['name' => 'Работник', 'position' => 'Мастер']);
});

function orderedWorkshop($ctx, string $status, ?string $syncStatus, string $createdAt): Workshop
{
    return Workshop::create([
        'packer_id'            => $ctx->packer->id,
        'receiver_id'          => $ctx->packer->id,
        'store_id'             => $ctx->store->id,
        'status'               => $status,
        'moysklad_sync_status' => $syncStatus,
        'created_at'           => $createdAt,
        'updated_at'           => $createdAt,
    ]);
}

/** Id операций на странице списка в порядке вывода. */
function listedWorkshopIds($ctx, array $query = []): array
{
    $ids = null;

    $ctx->actingAs(H::adminUser())
        ->get(route('workshops.index', $query))
        ->assertOk()
        ->assertViewHas('workshops', function ($workshops) use (&$ids) {
            $ids = $workshops->pluck('id')->all();

            return true;
        });

    return $ids;
}

test('без фильтра статуса видны и закрытые операции', function () {
    $active    = orderedWorkshop($this, Workshop::STATUS_ACTIVE, null, '2026-10-01 10:00:00');
    $completed = orderedWorkshop($this, Workshop::STATUS_COMPLETED, Workshop::SYNC_STATUS_SYNCED, '2026-10-02 10:00:00');

    expect(listedWorkshopIds($this))->toContain($active->id, $completed->id);
});

test('порядок: незавершённые → закрытые с ошибкой синхронизации → закрытые', function () {
    // Даты перемешаны: закрытые новее незавершённых, чтобы порядок по дате не совпал с ожидаемым
    $synced      = orderedWorkshop($this, Workshop::STATUS_COMPLETED, Workshop::SYNC_STATUS_SYNCED, '2026-10-05 10:00:00');
    $syncFailed  = orderedWorkshop($this, Workshop::STATUS_COMPLETED, Workshop::SYNC_STATUS_NOT_SYNCED, '2026-10-04 10:00:00');
    $error       = orderedWorkshop($this, Workshop::STATUS_ERROR, null, '2026-10-02 10:00:00');
    $active      = orderedWorkshop($this, Workshop::STATUS_ACTIVE, null, '2026-10-01 10:00:00');

    expect(listedWorkshopIds($this))->toBe([$error->id, $active->id, $syncFailed->id, $synced->id]);
});

test('внутри группы новые операции сверху', function () {
    $older = orderedWorkshop($this, Workshop::STATUS_COMPLETED, Workshop::SYNC_STATUS_SYNCED, '2026-10-01 10:00:00');
    $newer = orderedWorkshop($this, Workshop::STATUS_COMPLETED, Workshop::SYNC_STATUS_SYNCED, '2026-10-03 10:00:00');

    expect(listedWorkshopIds($this))->toBe([$newer->id, $older->id]);
});

test('порядок групп сохраняется при фильтре по статусу', function () {
    $synced     = orderedWorkshop($this, Workshop::STATUS_COMPLETED, Workshop::SYNC_STATUS_SYNCED, '2026-10-05 10:00:00');
    $syncFailed = orderedWorkshop($this, Workshop::STATUS_COMPLETED, Workshop::SYNC_STATUS_NOT_SYNCED, '2026-10-01 10:00:00');
    orderedWorkshop($this, Workshop::STATUS_ACTIVE, null, '2026-10-03 10:00:00');

    expect(listedWorkshopIds($this, ['filter' => ['status' => ['completed']]]))
        ->toBe([$syncFailed->id, $synced->id]);
});
