<?php

use App\Models\Department;
use App\Models\RawMaterialBatch;
use App\Models\User;
use App\Models\Worker;
use Tests\Helpers\ReceptionTestHelper as H;

function makeBatchInDept(?Department $dept, string $batchNumber): RawMaterialBatch
{
    $product = H::product();
    $store   = H::store();
    $cutter  = H::cutter();

    return H::batch($product, $store, $cutter, 25.0, [
        'batch_number'  => $batchNumber,
        'department_id' => $dept?->id,
    ]);
}

function makeMasterRB(Department $dept, string $name = 'Мастер RB'): User
{
    $worker = Worker::create([
        'name'          => $name,
        'positions'     => ['Мастер'],
        'department_id' => $dept->id,
    ]);

    return User::factory()->create(['is_admin' => false, 'worker_id' => $worker->id]);
}

function makeMasterRBNoDept(): User
{
    $worker = Worker::create([
        'name'      => 'Мастер RB без отдела',
        'positions' => ['Мастер'],
    ]);

    return User::factory()->create(['is_admin' => false, 'worker_id' => $worker->id]);
}

// ──────────────────────────────────────────────────────────────────────────────

test('мастер видит только партии своего отдела', function () {
    $deptA = Department::create(['name' => 'Цех',     'code' => 'TSEH']);
    $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

    makeBatchInDept($deptA, 'BATCH-OWN');
    makeBatchInDept($deptB, 'BATCH-FOREIGN');

    $this->actingAs(makeMasterRB($deptA))
        ->get(route('raw-batches.index'))
        ->assertStatus(200)
        ->assertSee('BATCH-OWN')
        ->assertDontSee('BATCH-FOREIGN');
});

test('мастер без отдела не видит ни одной партии', function () {
    $deptA = Department::create(['name' => 'Цех', 'code' => 'TSEH']);
    makeBatchInDept($deptA, 'BATCH-ANY');

    $this->actingAs(makeMasterRBNoDept())
        ->get(route('raw-batches.index'))
        ->assertStatus(200)
        ->assertDontSee('BATCH-ANY');
});

test('мастер может через фильтр увидеть партии чужого отдела', function () {
    $deptA = Department::create(['name' => 'Цех',     'code' => 'TSEH']);
    $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

    makeBatchInDept($deptA, 'BATCH-MINE');
    makeBatchInDept($deptB, 'BATCH-OTHER');

    $this->actingAs(makeMasterRB($deptA))
        ->get(route('raw-batches.index', ['filter' => ['department_id' => [$deptB->id]]]))
        ->assertStatus(200)
        ->assertSee('BATCH-OTHER')
        ->assertDontSee('BATCH-MINE');
});

test('админ видит все партии включая без отдела', function () {
    $deptA = Department::create(['name' => 'Цех',     'code' => 'TSEH']);
    $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

    makeBatchInDept($deptA, 'BATCH-A');
    makeBatchInDept($deptB, 'BATCH-B');
    makeBatchInDept(null,   'BATCH-NULL');

    $this->actingAs(H::adminUser())
        ->get(route('raw-batches.index'))
        ->assertStatus(200)
        ->assertSee('BATCH-A')
        ->assertSee('BATCH-B')
        ->assertSee('BATCH-NULL');
});

test('партия с department_id=NULL невидима мастеру', function () {
    $deptA = Department::create(['name' => 'Цех', 'code' => 'TSEH']);

    makeBatchInDept(null, 'BATCH-HIDDEN-NULL');

    $this->actingAs(makeMasterRB($deptA))
        ->get(route('raw-batches.index'))
        ->assertStatus(200)
        ->assertDontSee('BATCH-HIDDEN-NULL');
});
