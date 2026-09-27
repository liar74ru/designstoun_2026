<?php

use App\Models\RawMaterialBatch;
use App\Services\Moysklad\RawMaterialBatchSyncService;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

function rbsBatch(array $attrs = []): RawMaterialBatch
{
    return H::batch(H::product(), H::store(), H::cutter(), 20.0, array_merge(['batch_number' => 'RBS-1'], $attrs));
}

/** Подменить сервис синхронизации партий; МойСклад не вызывается. */
function rbsMockSync(?array $result, int $times = 1, ?callable $sideEffect = null): void
{
    $mock = Mockery::mock(RawMaterialBatchSyncService::class);
    $mock->shouldReceive('syncBatchMove')
        ->times($times)
        ->andReturnUsing(function (RawMaterialBatch $batch) use ($result, $sideEffect) {
            if ($sideEffect) {
                $sideEffect($batch);
            }

            return $result;
        });

    app()->instance(RawMaterialBatchSyncService::class, $mock);
}

// ══════════════════════════════════════════════════════════════════════════════
// syncBatch()
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchController syncBatch()', function () {

    test('успешная синхронизация — флеш success, вызывается для нужной партии', function () {
        $batch = rbsBatch();

        $mock = Mockery::mock(RawMaterialBatchSyncService::class);
        $mock->shouldReceive('syncBatchMove')
            ->once()
            ->withArgs(fn (RawMaterialBatch $b) => $b->is($batch))
            ->andReturn(['success' => true, 'code' => 'ok', 'message' => '']);
        app()->instance(RawMaterialBatchSyncService::class, $mock);

        $this->actingAs(H::adminUser())
            ->from(route('raw-batches.show', $batch))
            ->post(route('raw-batches.sync', $batch))
            ->assertRedirect(route('raw-batches.show', $batch))
            ->assertSessionHas('success', 'Партия синхронизирована с МойСклад.');
    });

    test('ошибка синхронизации — флеш error с сообщением сервиса', function () {
        $batch = rbsBatch();

        rbsMockSync(
            ['success' => false, 'code' => 'not_synced', 'message' => 'Товар не синхронизирован с МойСклад'],
            1,
            fn (RawMaterialBatch $b) => $b->markSyncError('Товар не синхронизирован с МойСклад'),
        );

        $this->actingAs(H::adminUser())
            ->post(route('raw-batches.sync', $batch))
            ->assertRedirect()
            ->assertSessionHas('error', 'Ошибка синхронизации: Товар не синхронизирован с МойСклад');

        expect($batch->fresh()->moysklad_sync_error)->toBe('Товар не синхронизирован с МойСклад');
    });

    test('404 для несуществующей партии — сервис не вызывается', function () {
        rbsMockSync(null, 0);

        $this->actingAs(H::adminUser())
            ->post(route('raw-batches.sync', 999999))
            ->assertNotFound();
    });

    test('мастер отдела с операцией raw-batches может синхронизировать', function () {
        $dept  = Access::department();
        $batch = rbsBatch(['department_id' => $dept->id]);

        rbsMockSync(['success' => true, 'code' => 'ok', 'message' => '']);

        $this->actingAs(Access::master($dept, 'raw-batches'))
            ->post(route('raw-batches.sync', $batch))
            ->assertSessionHas('success');
    });

    test('мастер отдела без операции raw-batches — 403', function () {
        $batch = rbsBatch();
        rbsMockSync(null, 0);

        $this->actingAs(Access::master(Access::department(), 'stone-receptions'))
            ->post(route('raw-batches.sync', $batch))
            ->assertForbidden();
    });

    test('мастер без отдела — 403', function () {
        $batch = rbsBatch();
        rbsMockSync(null, 0);

        $this->actingAs(Access::masterWithoutDept())
            ->post(route('raw-batches.sync', $batch))
            ->assertForbidden();
    });

    test('работник — 403', function () {
        $batch = rbsBatch();
        rbsMockSync(null, 0);

        $this->actingAs(Access::userWithPosition('Работник', Access::department()))
            ->post(route('raw-batches.sync', $batch))
            ->assertForbidden();
    });
});
