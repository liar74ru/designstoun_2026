<?php

use App\Models\Department;
use App\Models\User;
use App\Models\Worker;

// ──────────────────────────────────────────────────────────────────────────────
// Вспомогательные функции
// ──────────────────────────────────────────────────────────────────────────────

function makeDepartment(string $name = 'Цех'): Department
{
    return Department::create(['name' => $name, 'code' => strtoupper($name)]);
}

function makeMasterInDept(Department $dept): User
{
    $worker = Worker::create([
        'name'          => 'Мастер Цехов',
        'position'      => 'Мастер',
        'department_id' => $dept->id,
    ]);

    return User::factory()->create([
        'is_admin'  => false,
        'worker_id' => $worker->id,
    ]);
}

function makeCutterInDept(Department $dept): Worker
{
    return Worker::create([
        'name'          => 'Пильщик Тестов',
        'position'      => 'Пильщик',
        'department_id' => $dept->id,
    ]);
}

// ──────────────────────────────────────────────────────────────────────────────
// WorkerController::index — фильтрация списка по отделу мастера
// ──────────────────────────────────────────────────────────────────────────────

test('мастер с отделом видит только работников своего отдела', function () {
    $dept1 = makeDepartment('Цех');
    $dept2 = makeDepartment('Склад');

    $master  = makeMasterInDept($dept1);

    $cutter1 = Worker::create([
        'name'          => 'Пильщик Свой',
        'position'      => 'Пильщик',
        'department_id' => $dept1->id,
    ]);
    $cutter2 = Worker::create([
        'name'          => 'Пильщик Чужой',
        'position'      => 'Пильщик',
        'department_id' => $dept2->id,
    ]);

    $this->actingAs($master)
        ->get(route('workers.index'))
        ->assertStatus(200)
        ->assertSee($cutter1->name)
        ->assertDontSee($cutter2->name);
});

test('мастер с отделом видит себя в списке', function () {
    $dept   = makeDepartment('Цех');
    $master = makeMasterInDept($dept);

    $this->actingAs($master)
        ->get(route('workers.index'))
        ->assertStatus(200)
        ->assertSee($master->worker->name);
});

test('мастер без отдела видит всех работников кроме админов', function () {
    $worker = Worker::create(['name' => 'Мастер Без Отдела', 'position' => 'Мастер']);
    $master = User::factory()->create(['is_admin' => false, 'worker_id' => $worker->id]);

    $cutter = Worker::create(['name' => 'Пильщик Любой', 'position' => 'Пильщик']);

    $adminWorker = Worker::create(['name' => 'Директор Главный', 'position' => 'Директор']);
    User::factory()->create(['is_admin' => true, 'worker_id' => $adminWorker->id]);

    $this->actingAs($master)
        ->get(route('workers.index'))
        ->assertStatus(200)
        ->assertSee($cutter->name)
        ->assertDontSee($adminWorker->name);
});

test('мастер не видит карточку работника с аккаунтом администратора', function () {
    $dept   = makeDepartment('Цех');
    $master = makeMasterInDept($dept);

    $adminWorker = Worker::create([
        'name'          => 'Директор Цеховой',
        'position'      => 'Директор',
        'department_id' => $dept->id,
    ]);
    User::factory()->create(['is_admin' => true, 'worker_id' => $adminWorker->id]);

    $this->actingAs($master)
        ->get(route('workers.index'))
        ->assertStatus(200)
        ->assertDontSee($adminWorker->name);
});

// ──────────────────────────────────────────────────────────────────────────────
// WorkerDashboardController — доступ мастера к дашборду работника
// ──────────────────────────────────────────────────────────────────────────────

test('мастер с отделом может открыть дашборд пильщика из своего отдела', function () {
    $dept   = makeDepartment('Цех');
    $master = makeMasterInDept($dept);
    $cutter = makeCutterInDept($dept);

    $this->actingAs($master)
        ->get(route('worker.dashboard.by-id', $cutter->id))
        ->assertStatus(200);
});

test('мастер с отделом не может открыть дашборд пильщика из другого отдела', function () {
    $dept1  = makeDepartment('Цех');
    $dept2  = makeDepartment('Склад');
    $master = makeMasterInDept($dept1);
    $cutter = makeCutterInDept($dept2);

    $this->actingAs($master)
        ->get(route('worker.dashboard.by-id', $cutter->id))
        ->assertStatus(403);
});

test('мастер без отдела может открыть дашборд любого пильщика', function () {
    $dept2  = makeDepartment('Склад');
    $worker = Worker::create(['name' => 'Мастер Без Отдела', 'position' => 'Мастер']);
    $master = User::factory()->create(['is_admin' => false, 'worker_id' => $worker->id]);
    $cutter = makeCutterInDept($dept2);

    $this->actingAs($master)
        ->get(route('worker.dashboard.by-id', $cutter->id))
        ->assertStatus(200);
});

test('дашборд мастера показывает имя просматриваемого работника', function () {
    $dept   = makeDepartment('Цех');
    $master = makeMasterInDept($dept);
    $cutter = makeCutterInDept($dept);

    $this->actingAs($master)
        ->get(route('worker.dashboard.by-id', $cutter->id))
        ->assertStatus(200)
        ->assertSee($cutter->name);
});
