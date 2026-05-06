<?php

use App\Models\Department;
use App\Models\Packaging;
use App\Models\PackagingItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Str;
use Tests\Helpers\ReceptionTestHelper as H;

function makePackagingInDept(?Department $dept, string $tag): Packaging
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

    $packaging = Packaging::create([
        'packer_id'          => $packer->id,
        'receiver_id'        => $receiver->id,
        'store_id'           => $store->id,
        'department_id'      => $dept?->id,
        'package_product_id' => $packageProduct->id,
        'package_quantity'   => 1.0,
        'status'             => Packaging::STATUS_ACTIVE,
    ]);

    PackagingItem::create([
        'packaging_id' => $packaging->id,
        'product_id'   => $product->id,
        'quantity'     => 1.0,
    ]);

    return $packaging;
}

function makeMasterPkg(Department $dept, string $name = 'Мастер PKG'): User
{
    \App\Models\DepartmentOperationSetting::updateOrCreate(
        ['department_id' => $dept->id, 'operation_key' => 'packagings'],
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

test('мастер видит только упаковки своего отдела', function () {
    $deptA = Department::create(['name' => 'Цех',     'code' => 'TSEH']);
    $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

    makePackagingInDept($deptA, 'PKG-OWN');
    makePackagingInDept($deptB, 'PKG-FOREIGN');

    $this->actingAs(makeMasterPkg($deptA))
        ->get(route('packagings.index'))
        ->assertStatus(200)
        ->assertSee('Продукт PKG-OWN')
        ->assertDontSee('Продукт PKG-FOREIGN');
});

test('мастер без отдела не имеет доступа к упаковке — 403', function () {
    $deptA = Department::create(['name' => 'Цех', 'code' => 'TSEH']);
    makePackagingInDept($deptA, 'PKG-ANY');

    $this->actingAs(makeMasterPkgNoDept())
        ->get(route('packagings.index'))
        ->assertForbidden();
});

test('мастер может через фильтр увидеть упаковки чужого отдела', function () {
    $deptA = Department::create(['name' => 'Цех',     'code' => 'TSEH']);
    $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

    makePackagingInDept($deptA, 'PKG-MINE');
    makePackagingInDept($deptB, 'PKG-OTHER');

    $this->actingAs(makeMasterPkg($deptA))
        ->get(route('packagings.index', ['filter' => ['department_id' => [$deptB->id]]]))
        ->assertStatus(200)
        ->assertSee('Продукт PKG-OTHER')
        ->assertDontSee('Продукт PKG-MINE');
});

test('админ видит все упаковки независимо от отдела', function () {
    $deptA = Department::create(['name' => 'Цех',     'code' => 'TSEH']);
    $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

    makePackagingInDept($deptA, 'PKG-A');
    makePackagingInDept($deptB, 'PKG-B');
    makePackagingInDept(null,   'PKG-NULL');

    $this->actingAs(H::adminUser())
        ->get(route('packagings.index'))
        ->assertStatus(200)
        ->assertSee('Продукт PKG-A')
        ->assertSee('Продукт PKG-B')
        ->assertSee('Продукт PKG-NULL');
});

test('упаковка с department_id=NULL невидима мастеру', function () {
    $deptA = Department::create(['name' => 'Цех', 'code' => 'TSEH']);

    makePackagingInDept(null, 'PKG-HIDDEN-NULL');

    $this->actingAs(makeMasterPkg($deptA))
        ->get(route('packagings.index'))
        ->assertStatus(200)
        ->assertDontSee('Продукт PKG-HIDDEN-NULL');
});
