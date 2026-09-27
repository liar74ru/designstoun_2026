<?php

use App\Models\Department;
use App\Models\StoneReception;
use Tests\Helpers\ReceptionTestHelper as H;
use Tests\Helpers\AccessTestHelper as Access;

function makeReceptionInDept(?Department $dept, string $tag): StoneReception
{
    $product  = H::product();
    $store    = H::store('Склад ' . $tag);
    $cutter   = H::cutter('Пильщик ' . $tag);
    $receiver = H::worker('Приёмщик ' . $tag);
    $batch    = H::batch($product, $store, $cutter, 50.0, ['batch_number' => 'BN-' . $tag]);

    return H::reception($batch, $receiver, $cutter, $store, 1.0, [
        'department_id' => $dept?->id,
    ]);
}

// ──────────────────────────────────────────────────────────────────────────────

test('мастер видит только приёмки своего отдела', function () {
    $deptA = Department::create(['name' => 'Цех',     'code' => 'TSEH']);
    $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

    makeReceptionInDept($deptA, 'OWN-SR');
    makeReceptionInDept($deptB, 'FOREIGN-SR');

    $this->actingAs(Access::master($deptA, 'stone-receptions'))
        ->get(route('stone-receptions.index'))
        ->assertStatus(200)
        ->assertSee('Приёмщик OWN-SR')
        ->assertDontSee('Приёмщик FOREIGN-SR');
});

test('мастер без отдела не имеет доступа к приёмкам — 403', function () {
    $deptA = Department::create(['name' => 'Цех', 'code' => 'TSEH']);
    makeReceptionInDept($deptA, 'ANY-SR');

    $this->actingAs(Access::masterWithoutDept())
        ->get(route('stone-receptions.index'))
        ->assertForbidden();
});

test('мастер может через фильтр увидеть приёмки чужого отдела', function () {
    $deptA = Department::create(['name' => 'Цех',     'code' => 'TSEH']);
    $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

    makeReceptionInDept($deptA, 'MINE-SR');
    makeReceptionInDept($deptB, 'OTHER-SR');

    $this->actingAs(Access::master($deptA, 'stone-receptions'))
        ->get(route('stone-receptions.index', ['filter' => ['department_id' => [$deptB->id]]]))
        ->assertStatus(200)
        ->assertSee('Приёмщик OTHER-SR')
        ->assertDontSee('Приёмщик MINE-SR');
});

test('админ видит все приёмки', function () {
    $deptA = Department::create(['name' => 'Цех',     'code' => 'TSEH']);
    $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

    makeReceptionInDept($deptA, 'A-SR');
    makeReceptionInDept($deptB, 'B-SR');
    makeReceptionInDept(null,   'NULL-SR');

    $this->actingAs(H::adminUser())
        ->get(route('stone-receptions.index'))
        ->assertStatus(200)
        ->assertSee('Приёмщик A-SR')
        ->assertSee('Приёмщик B-SR')
        ->assertSee('Приёмщик NULL-SR');
});

test('приёмка с department_id=NULL невидима мастеру', function () {
    $deptA = Department::create(['name' => 'Цех', 'code' => 'TSEH']);

    makeReceptionInDept(null, 'NULL-HIDDEN-SR');

    $this->actingAs(Access::master($deptA, 'stone-receptions'))
        ->get(route('stone-receptions.index'))
        ->assertStatus(200)
        ->assertDontSee('Приёмщик NULL-HIDDEN-SR');
});
