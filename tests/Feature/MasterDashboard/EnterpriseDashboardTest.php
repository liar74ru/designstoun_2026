<?php

use App\Models\Department;
use App\Models\DepartmentOperationSetting;
use App\Models\Product;
use App\Models\RawMaterialBatch;
use App\Models\RawMaterialMovement;
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
use Illuminate\Support\Facades\Cache;

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

// ─── Эффективный отдел документа ─────────────────────────────────────────────
//
// department_id документа бывает пустым (создание админом, исторические записи),
// но отдел известен из связей: приёмка — партия → пильщик, цех — упаковщик.
// Такие документы должны попадать в отдел, а не пропадать у мастера.

/** Мастер с доступом к дашборду в перечисленных отделах (первый — основной). */
function entMaster(array $departments, string $name = 'Мастер отдела'): User
{
    Cache::flush();

    $worker = Worker::create([
        'name'          => $name,
        'position'      => 'Мастер',
        'department_id' => $departments[0]->id,
    ]);
    $worker->departments()->syncWithoutDetaching(collect($departments)->pluck('id')->all());

    foreach ($departments as $dept) {
        DepartmentOperationSetting::updateOrCreate(
            ['department_id' => $dept->id, 'operation_key' => 'enterprise-dashboard'],
            ['enabled' => true, 'config' => ['positions' => ['Мастер']]],
        );
        $dept->forgetOperationsCache();
    }

    return User::factory()->create(['is_admin' => false, 'worker_id' => $worker->fresh()->id]);
}

/** Приёмка с пустым department_id: отдел выводится из партии либо из пильщика. */
function entReceptionNoDept(?Department $batchDept, ?Department $cutterDept, float $qty, string $tag): void
{
    $store   = Store::factory()->create();
    $product = Product::factory()->create(['prod_cost_coeff' => 1.0]);
    $cutter  = Worker::create([
        'name'          => 'Пильщик ' . $tag,
        'position'      => 'Работник',
        'department_id' => $cutterDept?->id,
    ]);

    $batch = $batchDept === null ? null : RawMaterialBatch::create([
        'product_id'         => Product::factory()->create()->id,
        'initial_quantity'   => 100,
        'remaining_quantity' => 100,
        'current_store_id'   => $store->id,
        'current_worker_id'  => $cutter->id,
        'status'             => RawMaterialBatch::STATUS_IN_WORK,
        'batch_number'       => 'ENT-' . $tag,
        'department_id'      => $batchDept->id,
    ]);

    $reception = StoneReception::create([
        'receiver_id'           => $cutter->id,
        'cutter_id'             => $cutter->id,
        'store_id'              => $store->id,
        'department_id'         => null,
        'raw_material_batch_id' => $batch?->id,
        'raw_quantity_used'     => 0,   // created → updateStocks() пишет движение по партии
        'status'                => 'active',
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
        'receiver_id'        => $cutter->id,
        'type'               => ReceptionLog::TYPE_CREATED,
        'raw_quantity_delta' => 0,
    ]);

    ReceptionLogItem::create([
        'reception_log_id' => $log->id,
        'product_id'       => $product->id,
        'quantity_delta'   => $qty,
    ]);
}

/** Операция цеха с пустым department_id: отдел выводится из упаковщика. */
function entWorkshopNoDept(?Department $packerDept, float $qty, string $tag): void
{
    $store  = Store::factory()->create();
    $tile   = Product::factory()->create(['prod_cost_coeff' => 1.0]);
    $packer = Worker::create([
        'name'          => 'Упаковщик ' . $tag,
        'position'      => 'Работник',
        'department_id' => $packerDept?->id,
    ]);

    $workshop = Workshop::create([
        'packer_id'     => $packer->id,
        'receiver_id'   => $packer->id,
        'store_id'      => $store->id,
        'department_id' => null,
        'status'        => Workshop::STATUS_ACTIVE,
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
        'receiver_id'            => $packer->id,
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

/** Поступление сырья: партия + движение 'create' (вкладка «Сырьё»). */
function entIncomingRaw(?Department $dept, float $qty, string $tag): void
{
    $store  = Store::factory()->create();
    $worker = Worker::create(['name' => 'Приёмщик сырья ' . $tag, 'position' => 'Работник']);

    $batch = RawMaterialBatch::create([
        'product_id'         => Product::factory()->create()->id,
        'initial_quantity'   => $qty,
        'remaining_quantity' => $qty,
        'current_store_id'   => $store->id,
        'current_worker_id'  => $worker->id,
        'status'             => RawMaterialBatch::STATUS_IN_WORK,
        'batch_number'       => 'RAW-' . $tag,
        'department_id'      => $dept?->id,
    ]);

    RawMaterialMovement::create([
        'batch_id'      => $batch->id,
        'to_store_id'   => $store->id,
        'to_worker_id'  => $worker->id,
        'movement_type' => 'create',
        'quantity'      => $qty,
    ]);
}

/** Дашборд за всё время: пустой query редиректит на текущую неделю. */
function entDashboard($test, User $user, array $query = [])
{
    return $test->actingAs($user)->get(route('admin.enterprise-dashboard', array_merge([
        'date_from' => '2000-01-01',
        'date_to'   => '2100-01-01',
    ], $query)));
}

/** ['имя отдела' => объём] из viewData дашборда. */
function entTotals($response): array
{
    return $response->viewData('departments')
        ->mapWithKeys(fn ($row) => [
            $row['department']?->name ?? 'Без отдела' => (float) $row['totalQuantity'],
        ])
        ->all();
}

test('мастер видит приёмку без отдела, отдел которой определяется по партии сырья', function () {
    $dept = Department::create(['name' => 'Цех Е', 'is_active' => true]);

    // Пильщик без отдела — отдел может прийти только от партии.
    entReceptionNoDept($dept, null, 12.0, 'E');

    $response = entDashboard($this, entMaster([$dept]));

    $response->assertStatus(200);
    expect(entTotals($response))->toBe(['Цех Е' => 12.0]);
});

test('мастер видит приёмку без отдела, отдел которой определяется по пильщику', function () {
    $dept = Department::create(['name' => 'Цех Ж', 'is_active' => true]);

    // Партии нет — цепочка доходит до отдела пильщика.
    entReceptionNoDept(null, $dept, 8.0, 'ZH');

    $response = entDashboard($this, entMaster([$dept]));

    $response->assertStatus(200);
    expect(entTotals($response))->toBe(['Цех Ж' => 8.0]);
});

test('отдел партии приоритетнее отдела пильщика', function () {
    $batchDept  = Department::create(['name' => 'Отдел партии', 'is_active' => true]);
    $cutterDept = Department::create(['name' => 'Отдел пильщика', 'is_active' => true]);

    entReceptionNoDept($batchDept, $cutterDept, 6.0, 'PRIO');

    $response = entDashboard($this, entMaster([$batchDept]));

    $response->assertStatus(200);
    expect(entTotals($response))->toBe(['Отдел партии' => 6.0]);

    // Мастеру отдела пильщика документ не виден: эффективный отдел — отдел партии.
    $other = entDashboard($this, entMaster([$cutterDept], 'Мастер пильщиков'));
    expect(entTotals($other))->toBe([]);
});

test('мастер видит операцию цеха без отдела по отделу упаковщика', function () {
    $dept = Department::create(['name' => 'Цех З', 'is_active' => true]);

    entWorkshopNoDept($dept, 7.0, 'Z');

    $response = entDashboard($this, entMaster([$dept]));

    $response->assertStatus(200);
    expect(entTotals($response))->toBe(['Цех З' => 7.0]);
});

test('документы без определяемого отдела видны и мастеру, и админу', function () {
    $dept = Department::create(['name' => 'Цех И', 'is_active' => true]);

    entReceptionNoDept(null, null, 3.0, 'I');   // ни партии, ни отдела у пильщика
    entWorkshopNoDept(null, 2.0, 'I');          // упаковщик без отдела

    expect(entTotals(entDashboard($this, entMaster([$dept]))))->toBe(['Без отдела' => 5.0]);
    expect(entTotals(entDashboard($this, User::factory()->create(['is_admin' => true]))))
        ->toBe(['Без отдела' => 5.0]);
});

test('мастер не видит производство чужого отдела', function () {
    $deptA = Department::create(['name' => 'Свой отдел', 'is_active' => true]);
    $deptB = Department::create(['name' => 'Чужой отдел', 'is_active' => true]);

    makeEnterpriseReception($deptA, 'Приёмщик свой', 10.0);
    makeEnterpriseReception($deptB, 'Приёмщик чужой', 4.0);
    entReceptionNoDept($deptB, null, 5.0, 'FOREIGN');   // чужой отдел через партию
    entWorkshopNoDept($deptB, 6.0, 'FOREIGN');          // чужой отдел через упаковщика

    $response = entDashboard($this, entMaster([$deptA]));

    $response->assertStatus(200);
    expect(entTotals($response))->toBe(['Свой отдел' => 10.0]);
});

test('явный фильтр отдела не показывает документы без отдела', function () {
    $deptA = Department::create(['name' => 'Отдел фильтра А', 'is_active' => true]);
    $deptB = Department::create(['name' => 'Отдел фильтра Б', 'is_active' => true]);

    makeEnterpriseReception($deptA, 'Приёмщик ФА', 10.0);
    makeEnterpriseReception($deptB, 'Приёмщик ФБ', 4.0);
    entReceptionNoDept(null, null, 3.0, 'FILTER');

    $master = entMaster([$deptA, $deptB]);

    // Без фильтра — оба своих отдела плюс документ без отдела.
    expect(entTotals(entDashboard($this, $master)))
        ->toBe(['Отдел фильтра А' => 10.0, 'Отдел фильтра Б' => 4.0, 'Без отдела' => 3.0]);

    // С фильтром — строго выбранный отдел.
    $filtered = entDashboard($this, $master, ['filter' => ['department_id' => [$deptA->id]]]);
    expect(entTotals($filtered))->toBe(['Отдел фильтра А' => 10.0]);
});

test('вкладка «Сырьё»: мастер видит своё и безотдельное поступление, но не чужое', function () {
    $deptA = Department::create(['name' => 'Отдел сырья А', 'is_active' => true]);
    $deptB = Department::create(['name' => 'Отдел сырья Б', 'is_active' => true]);

    entIncomingRaw($deptA, 10.0, 'A');
    entIncomingRaw(null, 5.0, 'NONE');
    entIncomingRaw($deptB, 20.0, 'B');

    $response = entDashboard($this, entMaster([$deptA]));

    $response->assertStatus(200);
    expect((float) $response->viewData('incomingRawTotal'))->toEqual(15.0);

    // Админ видит всё.
    $admin = entDashboard($this, User::factory()->create(['is_admin' => true]));
    expect((float) $admin->viewData('incomingRawTotal'))->toEqual(35.0);
});

test('Workshop::effectiveDepartmentId() — свой отдел приоритетнее отдела упаковщика', function () {
    $own    = Department::create(['name' => 'Отдел операции', 'is_active' => true]);
    $packer = Worker::create([
        'name'          => 'Упаковщик чужого отдела',
        'position'      => 'Работник',
        'department_id' => Department::create(['name' => 'Отдел упаковщика', 'is_active' => true])->id,
    ]);

    $workshop = Workshop::create([
        'packer_id'     => $packer->id,
        'receiver_id'   => $packer->id,
        'store_id'      => Store::factory()->create()->id,
        'department_id' => $own->id,
        'status'        => Workshop::STATUS_ACTIVE,
    ]);

    expect($workshop->effectiveDepartmentId())->toBe($own->id);

    $workshop->update(['department_id' => null]);
    expect($workshop->fresh()->effectiveDepartmentId())->toBe($packer->department_id);

    $packer->update(['department_id' => null]);
    expect($workshop->fresh()->effectiveDepartmentId())->toBeNull();
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
