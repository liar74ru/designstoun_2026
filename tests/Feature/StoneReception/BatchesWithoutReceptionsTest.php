<?php

use App\Models\Department;
use App\Models\RawMaterialBatch;
use App\Models\User;
use App\Models\Worker;
use App\Services\StoneReceptionService;
use Illuminate\Http\Request;
use Tests\Helpers\ReceptionTestHelper as H;

/**
 * Собрать GET-запрос к index с нужным пользователем и фильтрами.
 */
function batchesIndexRequest(User $user, array $filter = []): Request
{
    $request = Request::create('/stone-receptions', 'GET', $filter ? ['filter' => $filter] : []);
    $request->setUserResolver(fn() => $user);

    return $request;
}

function batchesService(): StoneReceptionService
{
    return app(StoneReceptionService::class);
}

// ══════════════════════════════════════════════════════════════════════════════
// StoneReceptionService — getBatchesWithoutReceptions()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionService::getBatchesWithoutReceptions()', function () {

    test('возвращает рабочую партию без приёмок', function () {
        $product = H::product(['name' => 'Гранит']);
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::batch($product, $store, $cutter, 50.0, ['batch_number' => 'NO-REC']);

        $result = batchesService()->getBatchesWithoutReceptions(batchesIndexRequest(H::adminUser()));

        expect($result->pluck('id'))->toContain($batch->id);
    });

    test('партия с приёмкой не попадает в список', function () {
        $product  = H::product(['name' => 'Гранит']);
        $store    = H::store();
        $cutter   = H::cutter();
        $receiver = H::worker('Приёмщик');
        $batch    = H::batch($product, $store, $cutter, 50.0, ['batch_number' => 'HAS-REC']);
        H::reception($batch, $receiver, $cutter, $store, 5.0);

        $result = batchesService()->getBatchesWithoutReceptions(batchesIndexRequest(H::adminUser()));

        expect($result->pluck('id'))->not->toContain($batch->id);
    });

    test('нерабочие партии (used/returned/archived) без приёмок не показываются', function () {
        $product = H::product(['name' => 'Гранит']);
        $store   = H::store();
        $cutter  = H::cutter();

        $used = H::batch($product, $store, $cutter, 50.0, [
            'batch_number' => 'USED', 'status' => RawMaterialBatch::STATUS_USED,
        ]);
        $returned = H::batch($product, $store, $cutter, 50.0, [
            'batch_number' => 'RET', 'status' => RawMaterialBatch::STATUS_RETURNED,
        ]);
        $archived = H::batch($product, $store, $cutter, 50.0, [
            'batch_number' => 'ARC', 'status' => RawMaterialBatch::STATUS_ARCHIVED,
        ]);

        $result = batchesService()->getBatchesWithoutReceptions(batchesIndexRequest(H::adminUser()));

        expect($result->pluck('id'))
            ->not->toContain($used->id)
            ->not->toContain($returned->id)
            ->not->toContain($archived->id);
    });

    test('включает все рабочие статусы: new, in_work, confirmed', function () {
        $product = H::product(['name' => 'Гранит']);
        $store   = H::store();
        $cutter  = H::cutter();

        $new = H::batch($product, $store, $cutter, 50.0, [
            'batch_number' => 'NEW', 'status' => RawMaterialBatch::STATUS_NEW,
        ]);
        $inWork = H::batch($product, $store, $cutter, 50.0, [
            'batch_number' => 'INW', 'status' => RawMaterialBatch::STATUS_IN_WORK,
        ]);
        $confirmed = H::batch($product, $store, $cutter, 50.0, [
            'batch_number' => 'CNF', 'status' => RawMaterialBatch::STATUS_CONFIRMED,
        ]);

        $ids = batchesService()->getBatchesWithoutReceptions(batchesIndexRequest(H::adminUser()))->pluck('id');

        expect($ids)->toContain($new->id)->toContain($inWork->id)->toContain($confirmed->id);
    });

    test('фильтр по сырью оставляет только партии выбранного продукта', function () {
        $granite = H::product(['name' => 'Гранит']);
        $marble  = H::product(['name' => 'Мрамор']);
        $store   = H::store();
        $cutter  = H::cutter();

        $graniteBatch = H::batch($granite, $store, $cutter, 50.0, ['batch_number' => 'GRAN']);
        $marbleBatch  = H::batch($marble,  $store, $cutter, 50.0, ['batch_number' => 'MARB']);

        $result = batchesService()->getBatchesWithoutReceptions(
            batchesIndexRequest(H::adminUser(), ['raw_product_id' => $granite->id])
        );

        expect($result->pluck('id'))
            ->toContain($graniteBatch->id)
            ->not->toContain($marbleBatch->id);
    });

    test('мастер видит только партии своего отдела', function () {
        $deptA = Department::create(['name' => 'Цех',      'code' => 'TSEH']);
        $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

        $product = H::product(['name' => 'Гранит']);
        $store   = H::store();
        $cutter  = H::cutter();

        $ownBatch     = H::batch($product, $store, $cutter, 50.0, ['batch_number' => 'OWN', 'department_id' => $deptA->id]);
        $foreignBatch = H::batch($product, $store, $cutter, 50.0, ['batch_number' => 'FOREIGN', 'department_id' => $deptB->id]);

        $master     = Worker::create(['name' => 'Мастер Цеха', 'position' => 'Мастер', 'department_id' => $deptA->id]);
        $masterUser = User::factory()->create(['is_admin' => false, 'worker_id' => $master->id]);

        $result = batchesService()->getBatchesWithoutReceptions(batchesIndexRequest($masterUser));

        expect($result->pluck('id'))
            ->toContain($ownBatch->id)
            ->not->toContain($foreignBatch->id);
    });

    test('через фильтр отдела мастер видит партии чужого отдела', function () {
        $deptA = Department::create(['name' => 'Цех',      'code' => 'TSEH']);
        $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

        $product = H::product(['name' => 'Гранит']);
        $store   = H::store();
        $cutter  = H::cutter();

        $ownBatch     = H::batch($product, $store, $cutter, 50.0, ['batch_number' => 'MINE', 'department_id' => $deptA->id]);
        $foreignBatch = H::batch($product, $store, $cutter, 50.0, ['batch_number' => 'OTHER', 'department_id' => $deptB->id]);

        $master     = Worker::create(['name' => 'Мастер Цеха', 'position' => 'Мастер', 'department_id' => $deptA->id]);
        $masterUser = User::factory()->create(['is_admin' => false, 'worker_id' => $master->id]);

        $result = batchesService()->getBatchesWithoutReceptions(
            batchesIndexRequest($masterUser, ['department_id' => [$deptB->id]])
        );

        expect($result->pluck('id'))
            ->toContain($foreignBatch->id)
            ->not->toContain($ownBatch->id);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Рендеринг раздела «По партиям» на index
// ══════════════════════════════════════════════════════════════════════════════

test('index показывает партию без приёмок с пометкой «приёмок нет» и кнопкой оформления', function () {
    $product = H::product(['name' => 'Гранит без приёмок']);
    $store   = H::store();
    $cutter  = H::cutter();
    $batch   = H::batch($product, $store, $cutter, 50.0, ['batch_number' => 'SHOW-ME']);

    $this->actingAs(H::adminUser())
        ->get(route('stone-receptions.index'))
        ->assertStatus(200)
        ->assertSee('приёмок нет')
        ->assertSee('Гранит без приёмок')
        ->assertSee(route('stone-receptions.create', [
            'cutter_id'             => $batch->current_worker_id,
            'raw_material_batch_id' => $batch->id,
        ]));
});
