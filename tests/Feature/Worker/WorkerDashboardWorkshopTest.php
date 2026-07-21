<?php

use App\Models\Department;
use App\Models\Product;
use App\Models\ReceptionLog;
use App\Models\ReceptionLogItem;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use App\Models\Store;
use App\Models\Worker;
use App\Models\Workshop;
use App\Models\WorkshopItem;
use App\Models\WorkshopLog;
use App\Models\WorkshopLogItem;
use App\Services\WorkerDashboardService;
use Carbon\Carbon;

/**
 * Дашборд работника: выработка цеха (WorkshopLog) учитывается в сводке
 * и в «Итого к выплате» — для работника (packer_id) и мастера (receiver_id).
 */

function makeDashboardWorkshop(Worker $packer, Worker $receiver, Product $tile, float $qty): void
{
    $dept  = $packer->department ?? Department::create(['name' => 'Цех ' . $packer->id, 'is_active' => true]);
    $store = Store::factory()->create();
    $raw   = Product::factory()->create();

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

function makeDashboardReception(Worker $cutter, Worker $receiver, Product $tile, float $qty): void
{
    $store = Store::factory()->create();

    $reception = StoneReception::create([
        'receiver_id' => $receiver->id,
        'cutter_id'   => $cutter->id,
        'store_id'    => $store->id,
        'status'      => 'active',
    ]);

    StoneReceptionItem::create([
        'stone_reception_id' => $reception->id,
        'product_id'         => $tile->id,
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
        'product_id'       => $tile->id,
        'quantity_delta'   => $qty,
    ]);
}

function dashboardWeekRange(): array
{
    return [Carbon::now()->subDay()->startOfDay(), Carbon::now()->addDay()->endOfDay()];
}

test('выработка цеха работника (packer_id) попадает в сводку и итог', function () {
    $packer   = Worker::create(['name' => 'Упаковщик Цеха', 'position' => 'Работник']);
    $receiver = Worker::create(['name' => 'Приёмщик Цеха', 'position' => 'Мастер']);
    $tile     = Product::factory()->create(['prod_cost_coeff' => 1.0]);

    makeDashboardWorkshop($packer, $receiver, $tile, 7.0);

    [$from, $to] = dashboardWeekRange();
    $data = app(WorkerDashboardService::class)->getDashboardData($packer->id, false, $from, $to);

    expect($data['summary'])->toHaveCount(1)
        ->and($data['summary']->first()['product']->id)->toBe($tile->id)
        ->and((float) $data['summary']->first()['quantity'])->toEqual(7.0)
        ->and((float) $data['totalPay'])->toEqual(700.0);
});

test('приёмка камня и цех по одному товару объединяются в одну строку', function () {
    $worker   = Worker::create(['name' => 'Универсал', 'position' => 'Работник']);
    $receiver = Worker::create(['name' => 'Мастер Универсала', 'position' => 'Мастер']);
    $tile     = Product::factory()->create(['prod_cost_coeff' => 1.0]);

    makeDashboardReception($worker, $receiver, $tile, 10.0);
    makeDashboardWorkshop($worker, $receiver, $tile, 5.0);

    [$from, $to] = dashboardWeekRange();
    $data = app(WorkerDashboardService::class)->getDashboardData($worker->id, false, $from, $to);

    expect($data['summary'])->toHaveCount(1)
        ->and((float) $data['summary']->first()['quantity'])->toEqual(15.0)
        ->and((float) $data['totalPay'])->toEqual(1500.0);
});

test('мастер-приёмщик цеха (receiver_id) получает выработку цеха в свой итог', function () {
    $packer   = Worker::create(['name' => 'Упаковщик Мастера', 'position' => 'Работник']);
    $receiver = Worker::create(['name' => 'Мастер Цеха', 'position' => 'Мастер']);
    $tile     = Product::factory()->create(['prod_cost_coeff' => 1.0]);

    makeDashboardWorkshop($packer, $receiver, $tile, 4.0);

    [$from, $to] = dashboardWeekRange();
    $data = app(WorkerDashboardService::class)->getDashboardData($receiver->id, true, $from, $to);

    expect($data['summary'])->toHaveCount(1)
        ->and((float) $data['summary']->first()['quantity'])->toEqual(4.0)
        ->and((float) $data['totalMasterPay'])->toEqual(200.0);
});

test('чужие цеховые логи (другой packer_id) не попадают в дашборд работника', function () {
    $packer   = Worker::create(['name' => 'Упаковщик Свой', 'position' => 'Работник']);
    $other    = Worker::create(['name' => 'Упаковщик Чужой', 'position' => 'Работник']);
    $receiver = Worker::create(['name' => 'Приёмщик Чужого', 'position' => 'Мастер']);
    $tile     = Product::factory()->create(['prod_cost_coeff' => 1.0]);

    makeDashboardWorkshop($other, $receiver, $tile, 6.0);

    [$from, $to] = dashboardWeekRange();
    $data = app(WorkerDashboardService::class)->getDashboardData($packer->id, false, $from, $to);

    expect($data['summary'])->toBeEmpty()
        ->and((float) $data['totalPay'])->toEqual(0.0);
});
