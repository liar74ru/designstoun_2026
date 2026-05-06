<?php

use App\Models\Counterparty;
use App\Models\Department;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderItem;
use App\Models\User;
use App\Models\Worker;
use Tests\Helpers\ReceptionTestHelper as H;

function makeOrderInDept(?Department $dept, string $number, ?Worker $receiver = null): SupplierOrder
{
    $store = H::store();
    $cp    = Counterparty::create([
        'name'        => 'CP ' . $number,
        'moysklad_id' => (string) \Illuminate\Support\Str::uuid(),
    ]);

    $order = SupplierOrder::create([
        'number'          => $number,
        'store_id'        => $store->id,
        'counterparty_id' => $cp->id,
        'receiver_id'     => $receiver?->id,
        'department_id'   => $dept?->id,
        'status'          => SupplierOrder::STATUS_NEW,
    ]);

    SupplierOrderItem::create([
        'supplier_order_id' => $order->id,
        'product_id'        => H::product()->id,
        'quantity'          => 1.0,
    ]);

    return $order;
}

function makeMasterUserInDept(Department $dept): User
{
    \App\Models\DepartmentOperationSetting::updateOrCreate(
        ['department_id' => $dept->id, 'operation_key' => 'supplier-orders'],
        ['enabled' => true, 'config' => ['positions' => ['Мастер']]],
    );
    $dept->forgetOperationsCache();

    $worker = Worker::create([
        'name'          => 'Мастер ' . $dept->name,
        'position'      => 'Мастер',
        'department_id' => $dept->id,
    ]);

    return User::factory()->create(['is_admin' => false, 'worker_id' => $worker->id]);
}

function makeMasterUserWithoutDept(): User
{
    $worker = Worker::create([
        'name'      => 'Мастер Без Отдела',
        'position' => 'Мастер',
    ]);

    return User::factory()->create(['is_admin' => false, 'worker_id' => $worker->id]);
}

// ──────────────────────────────────────────────────────────────────────────────

test('мастер видит только поступления своего отдела', function () {
    $deptA = Department::create(['name' => 'Цех',     'code' => 'TSEH']);
    $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

    $orderOwn     = makeOrderInDept($deptA, 'OWN-01');
    $orderForeign = makeOrderInDept($deptB, 'FOREIGN-01');

    $this->actingAs(makeMasterUserInDept($deptA))
        ->get(route('supplier-orders.index'))
        ->assertStatus(200)
        ->assertSee('OWN-01')
        ->assertDontSee('FOREIGN-01');
});

test('мастер без отдела не имеет доступа к поступлениям — 403', function () {
    $deptA = Department::create(['name' => 'Цех', 'code' => 'TSEH']);
    makeOrderInDept($deptA, 'ANY-01');

    $this->actingAs(makeMasterUserWithoutDept())
        ->get(route('supplier-orders.index'))
        ->assertForbidden();
});

test('мастер может выбрать чужой отдел в фильтре и увидеть его поступления', function () {
    $deptA = Department::create(['name' => 'Цех',     'code' => 'TSEH']);
    $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

    makeOrderInDept($deptA, 'OWN-02');
    makeOrderInDept($deptB, 'FOREIGN-02');

    $this->actingAs(makeMasterUserInDept($deptA))
        ->get(route('supplier-orders.index', ['filter' => ['department_id' => [$deptB->id]]]))
        ->assertStatus(200)
        ->assertSee('FOREIGN-02')
        ->assertDontSee('OWN-02');
});

test('админ видит все поступления независимо от отдела', function () {
    $deptA = Department::create(['name' => 'Цех',     'code' => 'TSEH']);
    $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

    makeOrderInDept($deptA, 'A-01');
    makeOrderInDept($deptB, 'B-01');
    makeOrderInDept(null,   'NULL-01');

    $this->actingAs(H::adminUser())
        ->get(route('supplier-orders.index'))
        ->assertStatus(200)
        ->assertSee('A-01')
        ->assertSee('B-01')
        ->assertSee('NULL-01');
});

test('поступление с department_id=NULL невидимо мастеру', function () {
    $deptA = Department::create(['name' => 'Цех', 'code' => 'TSEH']);

    makeOrderInDept(null, 'NULL-02');

    $this->actingAs(makeMasterUserInDept($deptA))
        ->get(route('supplier-orders.index'))
        ->assertStatus(200)
        ->assertDontSee('NULL-02');
});
