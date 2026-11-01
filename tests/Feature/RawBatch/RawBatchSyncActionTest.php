<?php

use App\Jobs\SyncBatchToMoysklad;
use App\Models\Product;
use App\Models\RawMaterialBatch;
use App\Models\RawMaterialMovement;
use Illuminate\Support\Facades\Queue;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

function rbsBatch(array $attrs = [], ?string $moyskladId = 'ms-raw'): RawMaterialBatch
{
    $store = H::store();
    $batch = H::batch(Product::factory()->create(['moysklad_id' => $moyskladId]), $store, H::cutter(), 20.0, array_merge(['batch_number' => 'RBS-1'], $attrs));

    RawMaterialMovement::create([
        'batch_id'      => $batch->id,
        'from_store_id' => $store->id,
        'to_store_id'   => $store->id,
        'movement_type' => 'create',
        'quantity'      => 20.0,
    ]);

    return $batch;
}

// ══════════════════════════════════════════════════════════════════════════════
// syncBatch()
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchController syncBatch()', function () {

    beforeEach(fn () => Queue::fake());

    test('ставит задачу в очередь, партия ждёт отправки', function () {
        $batch = rbsBatch();
        $batch->markSyncError('Прошлая ошибка');

        $this->actingAs(H::adminUser())
            ->from(route('raw-batches.show', $batch))
            ->post(route('raw-batches.sync', $batch))
            ->assertRedirect(route('raw-batches.show', $batch))
            ->assertSessionHas('success', 'Отправлено в МойСклад.');

        expect($batch->fresh()->isSyncPending())->toBeTrue()
            ->and($batch->fresh()->moysklad_sync_error)->toBeNull();
        Queue::assertPushed(SyncBatchToMoysklad::class, fn ($job) => $job->batch->is($batch));
    });

    test('товар без moysklad_id — флеш error, ошибка в карточке, задачи нет', function () {
        $batch = rbsBatch([], null);

        $this->actingAs(H::adminUser())
            ->post(route('raw-batches.sync', $batch))
            ->assertRedirect()
            ->assertSessionHas('error', 'Товар не синхронизирован с МойСклад');

        expect($batch->fresh()->moysklad_sync_error)->toBe('Товар не синхронизирован с МойСклад');
        Queue::assertNothingPushed();
    });

    test('404 для несуществующей партии — задачи нет', function () {
        $this->actingAs(H::adminUser())
            ->post(route('raw-batches.sync', 999999))
            ->assertNotFound();

        Queue::assertNothingPushed();
    });

    test('мастер отдела с операцией raw-batches может синхронизировать', function () {
        $dept  = Access::department();
        $batch = rbsBatch(['department_id' => $dept->id]);

        $this->actingAs(Access::master($dept, 'raw-batches'))
            ->post(route('raw-batches.sync', $batch))
            ->assertSessionHas('success');
    });

    test('мастер отдела без операции raw-batches — 403', function () {
        $batch = rbsBatch();

        $this->actingAs(Access::master(Access::department(), 'stone-receptions'))
            ->post(route('raw-batches.sync', $batch))
            ->assertForbidden();
    });

    test('мастер без отдела — 403', function () {
        $batch = rbsBatch();

        $this->actingAs(Access::masterWithoutDept())
            ->post(route('raw-batches.sync', $batch))
            ->assertForbidden();
    });

    test('работник — 403', function () {
        $batch = rbsBatch();

        $this->actingAs(Access::userWithPosition('Работник', Access::department()))
            ->post(route('raw-batches.sync', $batch))
            ->assertForbidden();
    });
});
