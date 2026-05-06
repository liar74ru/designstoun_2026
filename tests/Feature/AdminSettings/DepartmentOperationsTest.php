<?php

use App\Models\Department;
use App\Models\DepartmentOperationSetting;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\Cache;

function makeOpAdmin(): User
{
    return User::factory()->create(['is_admin' => true, 'worker_id' => null]);
}

function makeOpMasterIn(Department $dept): User
{
    $worker = Worker::create([
        'name'          => 'Мастер ' . $dept->name,
        'positions'     => ['Мастер'],
        'department_id' => $dept->id,
    ]);
    return User::factory()->create(['is_admin' => false, 'worker_id' => $worker->id]);
}

function makeOpWorkerIn(Department $dept): User
{
    $worker = Worker::create([
        'name'          => 'Пильщик ' . $dept->name,
        'positions' => ['Работник'],
        'department_id' => $dept->id,
    ]);
    return User::factory()->create(['is_admin' => false, 'worker_id' => $worker->id]);
}

beforeEach(function () {
    Cache::flush();
});

describe('PATCH /admin/departments/{department}/operations', function () {

    test('администратор сохраняет включённые операции (upsert на каждый ключ реестра)', function () {
        $dept = Department::create(['name' => 'Цех Тест', 'is_active' => true]);

        $this->actingAs(makeOpAdmin())
            ->patch(route('admin.departments.operations.update', $dept), [
                'operations' => [
                    'stone-receptions' => '1',
                    'raw-batches'      => '1',
                ],
            ])
            ->assertRedirect(route('admin.departments.show', $dept))
            ->assertSessionHas('success');

        // на каждую операцию реестра — запись (включённые true, остальные false)
        $registry = config('department_operations');
        expect(DepartmentOperationSetting::where('department_id', $dept->id)->count())
            ->toBe(count($registry));

        expect($dept->enabledOperationKeys())
            ->toEqualCanonicalizing(['stone-receptions', 'raw-batches']);
    });

    test('повторное сохранение выключает ранее включённые', function () {
        $dept = Department::create(['name' => 'Цех Тест', 'is_active' => true]);
        $admin = makeOpAdmin();

        $this->actingAs($admin)->patch(
            route('admin.departments.operations.update', $dept),
            ['operations' => ['stone-receptions' => '1', 'raw-batches' => '1']]
        );

        $this->actingAs($admin)->patch(
            route('admin.departments.operations.update', $dept),
            ['operations' => ['raw-batches' => '1']]
        );

        expect($dept->enabledOperationKeys())->toEqualCanonicalizing(['raw-batches']);
    });

    test('невалидные ключи в payload игнорируются', function () {
        $dept = Department::create(['name' => 'Цех Тест', 'is_active' => true]);

        $this->actingAs(makeOpAdmin())->patch(
            route('admin.departments.operations.update', $dept),
            ['operations' => ['stone-receptions' => '1', 'unknown-op' => '1']]
        )->assertRedirect();

        expect(DepartmentOperationSetting::where('operation_key', 'unknown-op')->exists())
            ->toBeFalse();
        expect($dept->enabledOperationKeys())->toEqualCanonicalizing(['stone-receptions']);
    });

    test('кэш операций отдела сбрасывается после сохранения', function () {
        $dept = Department::create(['name' => 'Цех Тест', 'is_active' => true]);
        Cache::put(Department::operationsCacheKey($dept->id), ['stale-key'], 300);

        $this->actingAs(makeOpAdmin())->patch(
            route('admin.departments.operations.update', $dept),
            ['operations' => ['raw-batches' => '1']]
        );

        expect(Cache::has(Department::operationsCacheKey($dept->id)))->toBeFalse();
    });

    test('мастер не может изменить операции отдела', function () {
        $dept = Department::create(['name' => 'Цех Тест', 'is_active' => true]);

        $this->actingAs(makeOpMasterIn($dept))->patch(
            route('admin.departments.operations.update', $dept),
            ['operations' => ['raw-batches' => '1']]
        )->assertRedirect();

        expect(DepartmentOperationSetting::count())->toBe(0);
    });

});

describe('Header — видимость иконок по конфигу отдела', function () {

    test('админ видит все 11 иконок реестра, даже без записей в БД', function () {
        $admin = makeOpAdmin();

        $this->actingAs($admin)->get('/admin/settings')->assertOk();
        $response = $this->actingAs($admin)->get('/admin/settings');

        foreach (config('department_operations') as $op) {
            $response->assertSeeText($op['label']);
        }
    });

    test('рабочий видит "Выраб." всегда (always_visible), независимо от конфига', function () {
        $dept   = Department::create(['name' => 'Цех Тест', 'is_active' => true]);
        $worker = makeOpWorkerIn($dept);

        // конфиг отдела пуст — но worker-dashboard должен быть виден
        $this->actingAs($worker)
            ->get(route('worker.dashboard.by-id', ['workerId' => $worker->worker->id]))
            ->assertSeeText('Выраб.');
    });

    test('мастер с пустым конфигом не видит ни одной иконки реестра', function () {
        $dept   = Department::create(['name' => 'Цех Тест', 'is_active' => true]);
        $master = makeOpMasterIn($dept);

        $response = $this->actingAs($master)->followingRedirects()->get(route('stone-receptions.logs'));

        $response->assertDontSee('title="Дашборд"', false);
        $response->assertDontSee('title="Приём"', false);
    });

    test('мастер видит включённые в его отделе операции', function () {
        $dept   = Department::create(['name' => 'Цех Тест', 'is_active' => true]);
        $master = makeOpMasterIn($dept);
        $admin  = makeOpAdmin();

        $this->actingAs($admin)->patch(
            route('admin.departments.operations.update', $dept),
            ['operations' => ['stone-receptions' => '1', 'master-dashboard' => '1']]
        );

        $response = $this->actingAs($master)->followingRedirects()->get(route('stone-receptions.logs'));
        $response->assertSee('title="Приём"', false);
        $response->assertSee('title="Дашборд"', false);
        $response->assertDontSee('title="Сырьё"', false);
        $response->assertDontSee('title="Упак."', false);
    });

    test('мастер не видит иконку, включённую в чужом отделе', function () {
        $deptA = Department::create(['name' => 'Цех А', 'is_active' => true]);
        $deptB = Department::create(['name' => 'Цех Б', 'is_active' => true]);
        $masterA = makeOpMasterIn($deptA);
        $admin   = makeOpAdmin();

        $this->actingAs($admin)->patch(
            route('admin.departments.operations.update', $deptB),
            ['operations' => ['stone-receptions' => '1']]
        );

        $response = $this->actingAs($masterA)->followingRedirects()->get(route('stone-receptions.logs'));
        $response->assertDontSee('title="Приём"', false);
    });

});
