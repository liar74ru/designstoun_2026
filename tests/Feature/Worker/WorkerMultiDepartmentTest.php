<?php

use App\Models\Department;
use App\Models\DepartmentOperationSetting;
use App\Models\User;
use App\Models\Worker;
use App\Services\WorkerService;
use App\Support\OperationAccessor;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\ReceptionTestHelper as H;

beforeEach(fn () => Cache::flush());

function mdDepartment(string $name, string $code): Department
{
    return Department::create(['name' => $name, 'code' => $code]);
}

function mdBatchInDept(Department $dept, string $batchNumber): void
{
    H::batch(H::product(), H::store(), H::cutter('Пильщик отдела '.$dept->code), 25.0, [
        'batch_number'  => $batchNumber,
        'department_id' => $dept->id,
    ]);
}

/** Работник в нескольких отделах: первый — основной */
function mdWorker(string $name, string $position, array $departments): Worker
{
    $worker = Worker::create([
        'name'          => $name,
        'position'      => $position,
        'department_id' => $departments[0]->id,
    ]);
    $worker->departments()->syncWithoutDetaching(collect($departments)->pluck('id')->all());

    return $worker->fresh();
}

function mdUser(Worker $worker): User
{
    return User::factory()->create(['is_admin' => false, 'worker_id' => $worker->id]);
}

function mdAllowMasterFor(Department $dept, string $opKey): void
{
    DepartmentOperationSetting::updateOrCreate(
        ['department_id' => $dept->id, 'operation_key' => $opKey],
        ['enabled' => true, 'config' => ['positions' => ['Мастер']]],
    );
    $dept->forgetOperationsCache();
}

test('accessibleDepartmentIds() возвращает все отделы работника', function () {
    $deptA = mdDepartment('Цех', 'TSEH');
    $deptB = mdDepartment('Галтовка', 'GALT');
    $user  = mdUser(mdWorker('Мастер Двух', 'Мастер', [$deptA, $deptB]));

    expect($user->accessibleDepartmentIds())->toEqualCanonicalizing([$deptA->id, $deptB->id]);
    expect($user->primaryDepartmentId())->toBe($deptA->id);
});

test('мастер видит записи всех своих отделов и не видит чужие', function () {
    $deptA = mdDepartment('Цех', 'TSEH');
    $deptB = mdDepartment('Галтовка', 'GALT');
    $deptC = mdDepartment('Полировка', 'POLI');

    $user = mdUser(mdWorker('Мастер Двух', 'Мастер', [$deptA, $deptB]));

    mdBatchInDept($deptA, 'BATCH-A');
    mdBatchInDept($deptB, 'BATCH-B');
    mdBatchInDept($deptC, 'BATCH-C');

    mdAllowMasterFor($deptA, 'raw-batches');

    $this->actingAs($user)
        ->get(route('raw-batches.index'))
        ->assertStatus(200)
        ->assertSee('BATCH-A')
        ->assertSee('BATCH-B')
        ->assertDontSee('BATCH-C');
});

test('операция доступна, если разрешена хотя бы в одном отделе работника', function () {
    $deptA = mdDepartment('Цех', 'TSEH');
    $deptB = mdDepartment('Галтовка', 'GALT');
    $user  = mdUser(mdWorker('Мастер Двух', 'Мастер', [$deptA, $deptB]));

    expect(OperationAccessor::canSee($user, 'workers'))->toBeFalse();

    mdAllowMasterFor($deptB, 'workers');

    expect(OperationAccessor::canSee($user->fresh(), 'workers'))->toBeTrue();
});

test('WorkerController@store сохраняет несколько отделов и основной', function () {
    $deptA = mdDepartment('Цех', 'TSEH');
    $deptB = mdDepartment('Галтовка', 'GALT');
    $admin = User::factory()->create(['is_admin' => true, 'worker_id' => null]);

    $this->actingAs($admin)->post(route('workers.store'), [
        'name'           => 'Пильщик Многостаночник',
        'position'       => 'Работник',
        'department_id'  => $deptA->id,
        'department_ids' => [$deptA->id, $deptB->id],
    ])->assertRedirect(route('workers.index'));

    $worker = Worker::where('name', 'Пильщик Многостаночник')->firstOrFail();

    expect($worker->department_id)->toBe($deptA->id);
    expect($worker->departmentIds())->toEqualCanonicalizing([$deptA->id, $deptB->id]);
});

test('основной отдел добавляется в pivot, даже если не отмечен чекбоксом', function () {
    $deptA  = mdDepartment('Цех', 'TSEH');
    $deptB  = mdDepartment('Галтовка', 'GALT');
    $worker = Worker::create(['name' => 'Работник', 'position' => 'Работник']);

    (new WorkerService())->syncDepartments($worker, [$deptB->id], $deptA->id);

    expect($worker->fresh()->departmentIds())->toEqualCanonicalizing([$deptA->id, $deptB->id]);
    expect($worker->fresh()->department_id)->toBe($deptA->id);
});

test('мастер видит дашборд работника, для которого его отдел не основной', function () {
    $deptA = mdDepartment('Цех', 'TSEH');
    $deptB = mdDepartment('Галтовка', 'GALT');

    $master = mdUser(mdWorker('Мастер Цеха', 'Мастер', [$deptB]));
    $cutter = mdWorker('Пильщик', 'Работник', [$deptA, $deptB]);

    mdAllowMasterFor($deptB, 'master-dashboard');

    $this->actingAs($master)
        ->get(route('master.dashboard.by-id', $cutter->id))
        ->assertStatus(200);
});
