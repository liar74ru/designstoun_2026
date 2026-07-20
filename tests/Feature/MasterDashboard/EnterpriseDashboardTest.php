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

/**
 * Общий дашборд предприятия: агрегация всего производства за период по всем приёмкам,
 * с разбивкой по отделам. Только для админа.
 */

function makeEnterpriseReception(Department $dept, string $receiverName, float $qty): void
{
    $store    = Store::factory()->create();
    $product  = Product::factory()->create(['prod_cost_coeff' => 1.0]);
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
