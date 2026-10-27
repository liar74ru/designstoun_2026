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
use App\Services\WorkerDashboardService;
use Carbon\Carbon;

/**
 * Единая страница выработки: карточка на отдел документа, внутри — таблицы
 * по ролям (подписи «Как работник» / «Как мастер» — только когда в отделе обе роли);
 * суммы видят только сам человек и админ.
 */

function rolesReception(Department $dept, Worker $cutter, Worker $receiver, Product $tile, float $qty): void
{
    $reception = StoneReception::create([
        'receiver_id'   => $receiver->id,
        'cutter_id'     => $cutter->id,
        'store_id'      => Store::factory()->create()->id,
        'department_id' => $dept->id,
        'status'        => 'active',
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

function rolesWorkshop(Department $dept, Worker $packer, Worker $receiver, Product $tile, float $qty): void
{
    $workshop = Workshop::create([
        'packer_id'     => $packer->id,
        'receiver_id'   => $receiver->id,
        'store_id'      => Store::factory()->create()->id,
        'department_id' => $dept->id,
        'status'        => Workshop::STATUS_ACTIVE,
    ]);

    WorkshopItem::create([
        'workshop_id' => $workshop->id,
        'product_id'  => Product::factory()->create()->id,
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

function rolesDashboard(Worker $worker): array
{
    return app(WorkerDashboardService::class)->getDashboardData(
        $worker->id,
        Carbon::now()->subDay()->startOfDay(),
        Carbon::now()->addDay()->endOfDay(),
    );
}

test('работа в двух отделах за неделю — две карточки, общий итог равен их сумме', function () {
    $tseh   = Department::create(['name' => 'Цех', 'is_active' => true]);
    $market = Department::create(['name' => 'Маркетплейс', 'is_active' => true]);
    $cutter = Worker::create(['name' => 'Пильщик Двух Отделов', 'position' => 'Работник', 'department_id' => $tseh->id]);
    $master = Worker::create(['name' => 'Мастер Двух Отделов', 'position' => 'Мастер']);
    $tile   = Product::factory()->create();

    rolesReception($tseh, $cutter, $master, $tile, 10.0);
    rolesWorkshop($market, $cutter, $master, $tile, 3.0);

    $data = rolesDashboard($cutter);

    // Отделы отсортированы по имени.
    expect($data['departments']->pluck('department.name')->all())->toBe(['Маркетплейс', 'Цех']);

    [$marketRow, $tsehRow] = $data['departments']->all();

    expect((float) $marketRow['worker']['totalQuantity'])->toEqual(3.0)
        ->and((float) $marketRow['worker']['totalPay'])->toEqual(300.0)
        ->and($marketRow['master'])->toBeNull()
        ->and((float) $tsehRow['worker']['totalQuantity'])->toEqual(10.0)
        ->and((float) $tsehRow['worker']['totalPay'])->toEqual(1000.0)
        ->and((float) $data['totalWorkerPay'])->toEqual(1300.0)
        ->and((float) $data['totalMasterPay'])->toEqual(0.0)
        ->and((float) $data['totalPay'])->toEqual(1300.0);

    $this->actingAs(User::factory()->create(['worker_id' => $cutter->id, 'is_admin' => false]))
        ->get('/my-work')
        ->assertOk()
        ->assertSeeInOrder(['Маркетплейс', 'Цех'])
        ->assertDontSee('Как работник')
        ->assertDontSee('Как мастер')
        ->assertDontSee('база мастера');
});

test('мастер, который и принимал, и пилил, видит в отделе обе таблицы', function () {
    $tseh   = Department::create(['name' => 'Цех', 'is_active' => true]);
    $master = Worker::create(['name' => 'Мастер-Пильщик', 'position' => 'Мастер', 'department_id' => $tseh->id]);
    $cutter = Worker::create(['name' => 'Пильщик Цеха', 'position' => 'Работник', 'department_id' => $tseh->id]);
    $tile   = Product::factory()->create();

    rolesReception($tseh, $cutter, $master, $tile, 10.0); // принимал за пильщика
    rolesReception($tseh, $master, $cutter, $tile, 4.0);  // пилил сам

    $data = rolesDashboard($master);

    expect($data['departments'])->toHaveCount(1);

    $row = $data['departments']->first();

    expect((float) $row['worker']['totalQuantity'])->toEqual(4.0)
        ->and((float) $row['worker']['totalPay'])->toEqual(400.0)
        ->and((float) $row['master']['totalQuantity'])->toEqual(10.0)
        ->and((float) $row['master']['totalMasterPay'])->toEqual(500.0)
        ->and((float) $row['totalPay'])->toEqual(900.0)
        ->and((float) $data['totalPay'])->toEqual(900.0);

    $this->actingAs(User::factory()->create(['worker_id' => $master->id, 'is_admin' => false]))
        ->get('/my-work')
        ->assertOk()
        ->assertSeeInOrder(['Цех', 'Как работник', 'Как мастер'])
        ->assertSee('работник 400 ₽')
        ->assertSee('мастер 500 ₽');
});

test('запись, где человек и пильщик, и приёмщик, оплачивается в обеих ролях', function () {
    $tseh   = Department::create(['name' => 'Цех', 'is_active' => true]);
    $master = Worker::create(['name' => 'Мастер Сам Себе', 'position' => 'Мастер', 'department_id' => $tseh->id]);
    $tile   = Product::factory()->create();

    rolesReception($tseh, $master, $master, $tile, 2.0);

    $row = rolesDashboard($master)['departments']->first();

    expect((float) $row['worker']['totalPay'])->toEqual(200.0)
        ->and((float) $row['master']['totalMasterPay'])->toEqual(100.0)
        ->and((float) $row['totalPay'])->toEqual(300.0);
});

test('мастер на странице работника своего отдела видит м², но не суммы и ставки', function () {
    $tseh   = Department::create(['name' => 'Цех', 'is_active' => true]);
    $master = Worker::create(['name' => 'Мастер Смотрящий', 'position' => 'Мастер', 'department_id' => $tseh->id]);
    $cutter = Worker::create(['name' => 'Пильщик Под Присмотром', 'position' => 'Работник', 'department_id' => $tseh->id]);
    $tile   = Product::factory()->create();

    rolesReception($tseh, $cutter, $master, $tile, 7.0);

    $this->actingAs(User::factory()->create(['worker_id' => $master->id, 'is_admin' => false]))
        ->get(route('worker.dashboard.by-id', $cutter->id))
        ->assertOk()
        ->assertSee('7,000')
        ->assertDontSee('Итого к выплате')
        ->assertDontSee('Ставки отдела')
        ->assertDontSee('700 ₽');
});

test('админ на чужой странице видит суммы', function () {
    $tseh   = Department::create(['name' => 'Цех', 'is_active' => true]);
    $master = Worker::create(['name' => 'Мастер Для Админа', 'position' => 'Мастер', 'department_id' => $tseh->id]);
    $cutter = Worker::create(['name' => 'Пильщик Для Админа', 'position' => 'Работник', 'department_id' => $tseh->id]);
    $tile   = Product::factory()->create();

    rolesReception($tseh, $cutter, $master, $tile, 7.0);

    $this->actingAs(User::factory()->create(['worker_id' => null, 'is_admin' => true]))
        ->get(route('worker.dashboard.by-id', $cutter->id))
        ->assertOk()
        ->assertSee('Итого к выплате')
        ->assertSee('Ставки отдела')
        ->assertSee('пильщика')
        ->assertDontSee('база мастера')
        ->assertSee('700 ₽');
});
