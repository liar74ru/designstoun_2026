<?php

use App\Models\Department;
use App\Models\RawMaterialBatch;
use Tests\Helpers\ReceptionTestHelper as H;
use Tests\Helpers\AccessTestHelper as Access;

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

// ──────────────────────────────────────────────────────────────────────────────

test('мастер видит только партии своего отдела', function () {
    $deptA = Department::create(['name' => 'Цех',     'code' => 'TSEH']);
    $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

    makeBatchInDept($deptA, 'BATCH-OWN');
    makeBatchInDept($deptB, 'BATCH-FOREIGN');

    $this->actingAs(Access::master($deptA, 'raw-batches'))
        ->get(route('raw-batches.index'))
        ->assertStatus(200)
        ->assertSee('BATCH-OWN')
        ->assertDontSee('BATCH-FOREIGN');
});

test('мастер без отдела не имеет доступа к партиям — 403', function () {
    $deptA = Department::create(['name' => 'Цех', 'code' => 'TSEH']);
    makeBatchInDept($deptA, 'BATCH-ANY');

    $this->actingAs(Access::masterWithoutDept())
        ->get(route('raw-batches.index'))
        ->assertForbidden();
});

test('мастер может через фильтр увидеть партии чужого отдела', function () {
    $deptA = Department::create(['name' => 'Цех',     'code' => 'TSEH']);
    $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

    makeBatchInDept($deptA, 'BATCH-MINE');
    makeBatchInDept($deptB, 'BATCH-OTHER');

    $this->actingAs(Access::master($deptA, 'raw-batches'))
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

    $this->actingAs(Access::master($deptA, 'raw-batches'))
        ->get(route('raw-batches.index'))
        ->assertStatus(200)
        ->assertDontSee('BATCH-HIDDEN-NULL');
});
