<?php

use App\Models\Department;
use App\Models\Product;
use App\Models\ReceptionLog;
use App\Models\ReceptionLogItem;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use App\Models\Store;
use App\Models\User;
use App\Models\Worker;
use App\Models\Workshop;
use App\Models\WorkshopItem;
use App\Models\WorkshopLog;
use App\Models\WorkshopLogItem;

/**
 * Общий дашборд предприятия: агрегация всего производства за период по всем приёмкам,
 * с разбивкой по отделам. Только для админа.
 */

function makeEnterpriseReception(Department $dept, string $receiverName, float $qty, ?Product $product = null): void
{
    $store    = Store::factory()->create();
    $product  = $product ?? Product::factory()->create(['prod_cost_coeff' => 1.0]);
    $receiver = Worker::create(['name' => $receiverName, 'position' => 'Мастер', 'department_id' => $dept->id]);
    $cutter   = Worker::create(['name' => $receiverName . ' пильщик', 'position' => 'Работник', 'department_id' => $dept->id]);

    $reception = StoneReception::create([
        'receiver_id'   => $receiver->id,
        'cutter_id'     => $cutter->id,
        'store_id'      => $store->id,
        'department_id' => $dept->id,
        'status'        => 'active',
    ]);

    StoneReceptionItem::create([
        'stone_reception_id' => $reception->id,
        'product_id'         => $product->id,
        'quantity'           => $qty,
        'worker_cost_per_m2' => 100,
        'master_cost_per_m2' => 50,
    ]);

    $log = ReceptionLog::create([
        'stone_reception_id' => $reception->id,
        'cutter_id'          => $cutter->id,
        'receiver_id'        => $receiver->id,
        'type'               => ReceptionLog::TYPE_CREATED,
        'raw_quantity_delta' => 0,
    ]);

    ReceptionLogItem::create([
        'reception_log_id' => $log->id,
        'product_id'       => $product->id,
        'quantity_delta'   => $qty,
    ]);
}

function makeEnterpriseWorkshop(
    Department $dept,
    string $tag,
    float $qty,
    ?Product $rawProduct = null,
    ?Product $tileProduct = null
): void {
    $store    = Store::factory()->create();
    $raw      = $rawProduct ?? Product::factory()->create();
    $tile     = $tileProduct ?? Product::factory()->create(['prod_cost_coeff' => 1.0]);
    $packer   = Worker::create(['name' => 'Упаковщик ' . $tag, 'position' => 'Мастер', 'department_id' => $dept->id]);
    $receiver = Worker::create(['name' => 'Приёмщик цеха ' . $tag, 'position' => 'Мастер', 'department_id' => $dept->id]);

    $workshop = Workshop::create([
        'packer_id'     => $packer->id,
        'receiver_id'   => $receiver->id,
        'store_id'      => $store->id,
        'department_id' => $dept->id,
        'status'        => Workshop::STATUS_ACTIVE,
    ]);

    WorkshopItem::create([
        'workshop_id' => $workshop->id,
        'product_id'  => $raw->id,
        'role'        => WorkshopItem::ROLE_RAW,
        'quantity'    => 1.0,
    ]);

    WorkshopItem::create([
        'workshop_id'        => $workshop->id,
        'product_id'         => $tile->id,
        'role'               => WorkshopItem::ROLE_PRODUCT,
        'quantity'           => $qty,
        'worker_cost_per_m2' => 100,
        'master_cost_per_m2' => 50,
    ]);

    $log = WorkshopLog::create([
        'workshop_id'            => $workshop->id,
        'packer_id'              => $packer->id,
        'receiver_id'            => $receiver->id,
        'type'                   => WorkshopLog::TYPE_CREATED,
        'package_quantity_delta' => 0,
    ]);

    WorkshopLogItem::create([
        'workshop_log_id' => $log->id,
        'product_id'      => $tile->id,
        'role'            => WorkshopItem::ROLE_PRODUCT,
        'quantity_delta'  => $qty,
    ]);
}

test('не-администратор не имеет доступа к общему дашборду', function () {
    $worker = Worker::create(['name' => 'Мастер', 'position' => 'Мастер']);
    $user   = User::factory()->create(['worker_id' => $worker->id, 'is_admin' => false]);

    $this->actingAs($user)->get(route('admin.enterprise-dashboard'))->assertForbidden();
});

test('админ видит агрегированное производство по всем отделам', function () {
    $deptA = Department::create(['name' => 'Цех А', 'is_active' => true]);
    $deptB = Department::create(['name' => 'Цех Б', 'is_active' => true]);

    makeEnterpriseReception($deptA, 'Приёмщик А', 10.0);
    makeEnterpriseReception($deptB, 'Приёмщик Б', 5.0);

    $admin = User::factory()->create(['is_admin' => true]);

    // Голый заход редиректит на текущую неделю — следуем за редиректом.
    $this->actingAs($admin)->followingRedirects()->get(route('admin.enterprise-dashboard'))
        ->assertStatus(200)
        ->assertSee('Цех А')
        ->assertSee('Цех Б')
        ->assertSee('15,000')          // Σ м² = 10 + 5
        ->assertSee('1 500')           // ФОТ пильщиков = 15 * 100
        ->assertSee('750');            // ФОТ мастеров = 15 * 50
});

test('админ видит производство цеха на дашборде', function () {
    $dept = Department::create(['name' => 'Цех В', 'is_active' => true]);

    makeEnterpriseWorkshop($dept, 'В', 7.0);

    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->followingRedirects()->get(route('admin.enterprise-dashboard'))
        ->assertStatus(200)
        ->assertSee('Цех В')
        ->assertSee('7,000')           // Σ м² продукции цеха
        ->assertSee('700')             // ФОТ пильщиков = 7 * 100
        ->assertSee('350');            // ФОТ мастеров = 7 * 50
});

test('производство приёмок и цеха суммируется в таблице отдела', function () {
    $dept = Department::create(['name' => 'Цех Г', 'is_active' => true]);

    makeEnterpriseReception($dept, 'Приёмщик Г', 10.0);
    makeEnterpriseWorkshop($dept, 'Г', 5.0);

    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->followingRedirects()->get(route('admin.enterprise-dashboard'))
        ->assertStatus(200)
        ->assertSee('Цех Г')
        ->assertSee('15,000')          // Σ м² = 10 (приёмка) + 5 (цех)
        ->assertSee('1 500')           // ФОТ пильщиков = 15 * 100
        ->assertSee('750');            // ФОТ мастеров = 15 * 50
});

test('фильтр по камню отбирает цеха по сырьевым позициям', function () {
    $deptA = Department::create(['name' => 'Отдел камня А', 'is_active' => true]);
    $deptB = Department::create(['name' => 'Отдел камня Б', 'is_active' => true]);

    $stoneA = Product::factory()->create();
    $stoneB = Product::factory()->create();

    makeEnterpriseWorkshop($deptA, 'КА', 3.0, $stoneA);
    makeEnterpriseWorkshop($deptB, 'КБ', 4.0, $stoneB);

    $admin = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($admin)
        ->get(route('admin.enterprise-dashboard', ['filter' => ['raw_product_id' => $stoneA->id]]));

    $response->assertStatus(200);

    $departments = $response->viewData('departments');
    expect($departments)->toHaveCount(1)
        ->and($departments->first()['department']->name)->toBe('Отдел камня А')
        ->and((float) $departments->first()['totalQuantity'])->toEqual(3.0);
});

test('фильтр по продукту оставляет только строки выбранной плитки', function () {
    $deptA = Department::create(['name' => 'Отдел плитки А', 'is_active' => true]);
    $deptB = Department::create(['name' => 'Отдел плитки Б', 'is_active' => true]);

    $tileA = Product::factory()->create(['prod_cost_coeff' => 1.0]);
    $tileB = Product::factory()->create(['prod_cost_coeff' => 1.0]);

    makeEnterpriseReception($deptA, 'Приёмщик ПА', 10.0, $tileA);
    makeEnterpriseWorkshop($deptA, 'ПА', 5.0, null, $tileB);
    makeEnterpriseReception($deptB, 'Приёмщик ПБ', 4.0, $tileB);

    $admin = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($admin)
        ->get(route('admin.enterprise-dashboard', ['filter' => ['product_id' => $tileA->id]]));

    $response->assertStatus(200);

    $departments = $response->viewData('departments');
    expect($departments)->toHaveCount(1)
        ->and($departments->first()['department']->name)->toBe('Отдел плитки А')
        ->and((float) $departments->first()['totalQuantity'])->toEqual(10.0);

    $summary = $departments->first()['summary'];
    expect($summary)->toHaveCount(1)
        ->and($summary->first()['product']->id)->toBe($tileA->id);

    expect((float) $response->viewData('grandQuantity'))->toEqual(10.0);
});

test('строки одного товара из приёмки и цеха объединяются в одну', function () {
    $dept = Department::create(['name' => 'Цех Д', 'is_active' => true]);
    $tile = Product::factory()->create(['prod_cost_coeff' => 1.0]);

    makeEnterpriseReception($dept, 'Приёмщик Д', 10.0, $tile);
    makeEnterpriseWorkshop($dept, 'Д', 5.0, null, $tile);

    $admin = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($admin)->followingRedirects()->get(route('admin.enterprise-dashboard'));
    $response->assertStatus(200);

    $departments = $response->viewData('departments');
    expect($departments)->toHaveCount(1);

    $summary = $departments->first()['summary'];
    expect($summary)->toHaveCount(1)
        ->and((float) $summary->first()['quantity'])->toEqual(15.0)
        ->and((float) $summary->first()['pay'])->toEqual(1500.0)
        ->and((float) $summary->first()['masterPay'])->toEqual(750.0);
});
