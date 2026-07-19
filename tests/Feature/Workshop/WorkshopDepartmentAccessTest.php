<?php

use App\Models\Department;
use App\Models\Workshop;
use App\Models\WorkshopItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Str;
use Tests\Helpers\ReceptionTestHelper as H;

function makeWorkshopInDept(?Department $dept, string $tag): Workshop
{
    $store    = H::store('Склад ' . $tag);
    $packer   = Worker::create([
        'name'      => 'Упаковщик ' . $tag,
        'position' => 'Мастер',
    ]);
    $receiver = Worker::create([
        'name'      => 'Приёмщик ' . $tag,
        'position' => 'Мастер',
    ]);

    $product = Product::factory()->create([
        'name'        => 'Продукт ' . $tag,
        'sku'         => '04-01-' . $tag,
        'moysklad_id' => (string) Str::uuid(),
    ]);

    $packageProduct = Product::factory()->create([
        'name'        => 'Тара ' . $tag,
        'sku'         => '07-03-' . $tag,
        'moysklad_id' => (string) Str::uuid(),
    ]);

    $workshop = Workshop::create([
        'packer_id'     => $packer->id,
        'receiver_id'   => $receiver->id,
        'store_id'      => $store->id,
        'department_id' => $dept?->id,
        'status'        => Workshop::STATUS_ACTIVE,
    ]);

    WorkshopItem::create([
        'workshop_id' => $workshop->id,
        'product_id'  => $product->id,
        'role'        => WorkshopItem::ROLE_RAW,
        'quantity'    => 1.0,
    ]);

    WorkshopItem::create([
        'workshop_id' => $workshop->id,
        'product_id'  => $packageProduct->id,
        'role'        => WorkshopItem::ROLE_PACKAGE,
        'quantity'    => 1.0,
    ]);

    return $workshop;
}

function makeMasterPkg(Department $dept, string $name = 'Мастер PKG'): User
{
    \App\Models\DepartmentOperationSetting::updateOrCreate(
        ['department_id' => $dept->id, 'operation_key' => 'workshops'],
        ['enabled' => true, 'config' => ['positions' => ['Мастер']]],
    );
    $dept->forgetOperationsCache();

    $worker = Worker::create([
        'name'          => $name,
        'position'      => 'Мастер',
        'department_id' => $dept->id,
    ]);

    return User::factory()->create(['is_admin' => false, 'worker_id' => $worker->id]);
}

function makeMasterPkgNoDept(): User
{
    $worker = Worker::create([
        'name'      => 'Мастер PKG без отдела',
        'position' => 'Мастер',
    ]);

    return User::factory()->create(['is_admin' => false, 'worker_id' => $worker->id]);
}

// ──────────────────────────────────────────────────────────────────────────────

test('мастер видит только операции своего отдела', function () {
    $deptA = Department::create(['name' => 'Цех',     'code' => 'TSEH']);
    $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

    makeWorkshopInDept($deptA, 'PKG-OWN');
    makeWorkshopInDept($deptB, 'PKG-FOREIGN');

    $this->actingAs(makeMasterPkg($deptA))
        ->get(route('workshops.index'))
        ->assertStatus(200)
        ->assertSee('Продукт PKG-OWN')
        ->assertDontSee('Продукт PKG-FOREIGN');
});

test('мастер без отдела не имеет доступа к цеху — 403', function () {
    $deptA = Department::create(['name' => 'Цех', 'code' => 'TSEH']);
    makeWorkshopInDept($deptA, 'PKG-ANY');

    $this->actingAs(makeMasterPkgNoDept())
        ->get(route('workshops.index'))
        ->assertForbidden();
});

test('мастер может через фильтр увидеть операции чужого отдела', function () {
    $deptA = Department::create(['name' => 'Цех',     'code' => 'TSEH']);
    $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

    makeWorkshopInDept($deptA, 'PKG-MINE');
    makeWorkshopInDept($deptB, 'PKG-OTHER');

    $this->actingAs(makeMasterPkg($deptA))
        ->get(route('workshops.index', ['filter' => ['department_id' => [$deptB->id]]]))
        ->assertStatus(200)
        ->assertSee('Продукт PKG-OTHER')
        ->assertDontSee('Продукт PKG-MINE');
});

test('админ видит все операции независимо от отдела', function () {
    $deptA = Department::create(['name' => 'Цех',     'code' => 'TSEH']);
    $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

    makeWorkshopInDept($deptA, 'PKG-A');
    makeWorkshopInDept($deptB, 'PKG-B');
    makeWorkshopInDept(null,   'PKG-NULL');

    $this->actingAs(H::adminUser())
        ->get(route('workshops.index'))
        ->assertStatus(200)
        ->assertSee('Продукт PKG-A')
        ->assertSee('Продукт PKG-B')
        ->assertSee('Продукт PKG-NULL');
});

test('операция с department_id=NULL невидима мастеру', function () {
    $deptA = Department::create(['name' => 'Цех', 'code' => 'TSEH']);

    makeWorkshopInDept(null, 'PKG-HIDDEN-NULL');

    $this->actingAs(makeMasterPkg($deptA))
        ->get(route('workshops.index'))
        ->assertStatus(200)
        ->assertDontSee('Продукт PKG-HIDDEN-NULL');
});
