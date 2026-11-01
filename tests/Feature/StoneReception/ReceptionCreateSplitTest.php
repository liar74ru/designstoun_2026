<?php

use App\Exceptions\InsufficientRawMaterialException;
use Illuminate\Support\Facades\Queue;
use App\Jobs\SyncBatchToMoysklad;
use App\Jobs\SyncReceptionToMoysklad;
use App\Models\Department;
use App\Models\RawMaterialBatch;
use App\Models\StoneReception;
use App\Services\RawMaterialBatchService;
use App\Services\StoneReceptionService;
use Tests\Helpers\ReceptionTestHelper as H;

/*
 * Создание приёмки на партии с завершёнными приёмками делит партию (split).
 * Закрытие прежней активной приёмки, split и сама приёмка — одна транзакция,
 * МойСклад — только после неё.
 */

/**
 * Партия 10 м³: завершённая приёмка на 3 и активная на 1 → остаток 6.
 *
 * @return array{batch: RawMaterialBatch, active: StoneReception, receiver: \App\Models\Worker}
 */
function rcsBatchWithHistory(): array
{
    $store    = H::store();
    $cutter   = H::cutter();
    $receiver = H::worker();
    $batch    = H::batch(H::product(), $store, $cutter, 10.0, ['batch_number' => 'B-01']);

    H::reception($batch, $receiver, $cutter, $store, 3.0, ['status' => StoneReception::STATUS_COMPLETED]);
    $active = H::reception($batch, $receiver, $cutter, $store, 1.0);

    return ['batch' => $batch->fresh(), 'active' => $active, 'receiver' => $receiver];
}

function rcsService(): StoneReceptionService
{
    return new StoneReceptionService(app(RawMaterialBatchService::class));
}

test('приёмка не сохранилась — партия не разделена, прежняя приёмка активна, МойСклад не вызван', function () {
    ['batch' => $batch, 'active' => $active, 'receiver' => $receiver] = rcsBatchWithHistory();

    Queue::fake();

    // 8 м³ при остатке 6: предварительную проверку контроллера обходим —
    // так выглядит приёмка, у которой остаток забрали между проверкой и сохранением
    $data = H::receptionPostData($receiver, $batch->currentWorker, $batch->currentStore, $batch, 8.0);

    expect(fn () => rcsService()->create($data, false))
        ->toThrow(InsufficientRawMaterialException::class);

    expect(RawMaterialBatch::count())->toBe(1);

    $batch->refresh();
    expect((float) $batch->initial_quantity)->toBe(10.0);
    expect((float) $batch->remaining_quantity)->toBe(6.0);
    expect($batch->status)->toBe(RawMaterialBatch::STATUS_IN_WORK);

    expect($active->fresh()->status)->toBe(StoneReception::STATUS_ACTIVE);
    expect(StoneReception::count())->toBe(2);
    Queue::assertNothingPushed();
});

test('разделение ставится в очередь после сохранения приёмки: дочерняя партия, затем родитель', function () {
    ['batch' => $parent, 'active' => $active, 'receiver' => $receiver] = rcsBatchWithHistory();

    Queue::fake();

    $data = H::receptionPostData($receiver, $parent->currentWorker, $parent->currentStore, $parent, 2.0);

    $reception = rcsService()->create($data, false);

    // Порядок задач: дочерняя партия, родитель, затем приёмка
    Queue::assertPushed(SyncReceptionToMoysklad::class, fn ($job) => $job->reception->is($reception));

    $child = RawMaterialBatch::whereKeyNot($parent->id)->sole();
    expect($reception->raw_material_batch_id)->toBe($child->id);
    expect((float) $child->remaining_quantity)->toBe(4.0);
    expect($parent->fresh()->status)->toBe(RawMaterialBatch::STATUS_USED);
    expect($active->fresh()->status)->toBe(StoneReception::STATUS_COMPLETED);

    expect(array_map(fn ($entry) => $entry['job']->batch->id, Queue::pushedJobs()[SyncBatchToMoysklad::class]))
        ->toBe([$child->id, $parent->id]);
});

test('партия без работника при разделении — понятная ошибка, приёмка не создана', function () {
    ['batch' => $batch, 'receiver' => $receiver] = rcsBatchWithHistory();
    $cutter = $batch->currentWorker;
    $store  = $batch->currentStore;
    $batch->update(['current_worker_id' => null]);

    Queue::fake();

    $this->actingAs(H::adminUser())
        ->post(route('stone-receptions.store'), H::receptionPostData($receiver, $cutter, $store, $batch, 1.0) + ['department_id' => Department::create(['name' => 'Распил'])->id])
        ->assertSessionHasErrors(['raw_material_batch_id' => 'У партии не назначен работник. Назначьте работника и повторите.']);

    expect(StoneReception::count())->toBe(2)
        ->and(RawMaterialBatch::count())->toBe(1);
});

test('сбой при создании приёмки: подробности только в логе, пользователю общий текст', function () {
    ['batch' => $batch, 'receiver' => $receiver] = rcsBatchWithHistory();
    $data = H::receptionPostData($receiver, $batch->currentWorker, $batch->currentStore, $batch, 1.0)
        + ['department_id' => Department::create(['name' => 'Распил'])->id];

    $this->partialMock(StoneReceptionService::class, fn ($mock) => $mock
        ->shouldReceive('create')->andThrow(new \RuntimeException('SQLSTATE секретная деталь')));

    $response = $this->actingAs(H::adminUser())->post(route('stone-receptions.store'), $data);

    $response->assertSessionHasErrors(['error' => 'Не удалось сохранить приёмку. Попробуйте ещё раз, если повторится — сообщите администратору.']);
    expect(session('errors')->first('error'))->not->toContain('SQLSTATE');
});

test('сбой при правке приёмки: подробности только в логе, пользователю общий текст', function () {
    ['batch' => $batch, 'active' => $active, 'receiver' => $receiver] = rcsBatchWithHistory();
    $data = H::receptionPostData($receiver, $batch->currentWorker, $batch->currentStore, $batch, 1.0)
        + ['department_id' => Department::create(['name' => 'Распил'])->id];

    $this->partialMock(StoneReceptionService::class, fn ($mock) => $mock
        ->shouldReceive('update')->andThrow(new \RuntimeException('SQLSTATE секретная деталь')));

    $this->actingAs(H::adminUser())->put(route('stone-receptions.update', $active), $data)
        ->assertSessionHasErrors(['error' => 'Не удалось сохранить приёмку. Попробуйте ещё раз, если повторится — сообщите администратору.']);
});
