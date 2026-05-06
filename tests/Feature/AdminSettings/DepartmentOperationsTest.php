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

function makeOpUser(Department $dept, string $position): User
{
    $worker = Worker::create([
        'name'          => $position . ' ' . $dept->name,
        'position'      => $position,
        'department_id' => $dept->id,
    ]);
    return User::factory()->create(['is_admin' => false, 'worker_id' => $worker->id]);
}

beforeEach(fn () => Cache::flush());

describe('PATCH /admin/departments/{department}/operations', function () {

    test('администратор сохраняет позиции для операций', function () {
        $dept = Department::create(['name' => 'Цех Тест', 'is_active' => true]);

        $this->actingAs(makeOpAdmin())
            ->patch(route('admin.departments.operations.update', $dept), [
                'operations' => [
                    'stone-receptions' => ['positions' => ['', 'Мастер']],
                    'raw-batches'      => ['positions' => ['', 'Мастер', 'Помощник мастера']],
                ],
            ])
            ->assertRedirect(route('admin.departments.show', $dept))
            ->assertSessionHas('success');

        $stoneSetting = DepartmentOperationSetting::where('department_id', $dept->id)
            ->where('operation_key', 'stone-receptions')->first();
        expect($stoneSetting->enabled)->toBeTrue();
        expect($stoneSetting->config['positions'])->toEqualCanonicalizing(['Мастер']);

        $rawSetting = DepartmentOperationSetting::where('department_id', $dept->id)
            ->where('operation_key', 'raw-batches')->first();
        expect($rawSetting->enabled)->toBeTrue();
        expect($rawSetting->config['positions'])->toEqualCanonicalizing(['Мастер', 'Помощник мастера']);
    });

    test('пустой список позиций выключает операцию', function () {
        $dept  = Department::create(['name' => 'Цех Тест', 'is_active' => true]);
        $admin = makeOpAdmin();

        $this->actingAs($admin)->patch(
            route('admin.departments.operations.update', $dept),
            ['operations' => ['stone-receptions' => ['positions' => ['', 'Мастер']]]]
        );

        $this->actingAs($admin)->patch(
            route('admin.departments.operations.update', $dept),
            ['operations' => ['stone-receptions' => ['positions' => ['']]]]
        );

        $setting = DepartmentOperationSetting::where('department_id', $dept->id)
            ->where('operation_key', 'stone-receptions')->first();
        expect($setting->enabled)->toBeFalse();
        expect($setting->config['positions'])->toBe([]);
    });

    test('недопустимые позиции в payload игнорируются', function () {
        $dept = Department::create(['name' => 'Цех Тест', 'is_active' => true]);

        $this->actingAs(makeOpAdmin())->patch(
            route('admin.departments.operations.update', $dept),
            ['operations' => [
                'stone-receptions' => ['positions' => ['', 'Мастер', 'Работник']],
            ]]
        )->assertRedirect();

        $setting = DepartmentOperationSetting::where('department_id', $dept->id)
            ->where('operation_key', 'stone-receptions')->first();
        expect($setting->config['positions'])->toEqualCanonicalizing(['Мастер']);
    });

    test('кэш позиций отдела сбрасывается после сохранения', function () {
        $dept = Department::create(['name' => 'Цех Тест', 'is_active' => true]);
        Cache::put(Department::operationPositionsCacheKey($dept->id, 'stone-receptions'), ['stale'], 300);

        $this->actingAs(makeOpAdmin())->patch(
            route('admin.departments.operations.update', $dept),
            ['operations' => ['stone-receptions' => ['positions' => ['', 'Мастер']]]]
        );

        expect(Cache::has(Department::operationPositionsCacheKey($dept->id, 'stone-receptions')))->toBeFalse();
    });

    test('мастер не может изменить операции отдела', function () {
        $dept = Department::create(['name' => 'Цех Тест', 'is_active' => true]);

        $this->actingAs(makeOpUser($dept, 'Мастер'))->patch(
            route('admin.departments.operations.update', $dept),
            ['operations' => ['stone-receptions' => ['positions' => ['', 'Мастер']]]]
        )->assertForbidden();

        expect(DepartmentOperationSetting::count())->toBe(0);
    });

});

describe('Header — видимость иконок по позиции и отделу', function () {

    test('админ видит все иконки реестра, даже без записей в БД', function () {
        $admin = makeOpAdmin();

        $response = $this->actingAs($admin)->get('/admin/settings');
        $response->assertOk();

        foreach (config('department_operations') as $op) {
            $response->assertSeeText($op['label']);
        }
    });

    test('работник видит "Выраб." всегда (always_visible), независимо от конфига', function () {
        $dept   = Department::create(['name' => 'Цех Тест', 'is_active' => true]);
        $worker = makeOpUser($dept, 'Работник');

        $this->actingAs($worker)
            ->get(route('worker.dashboard.by-id', ['workerId' => $worker->worker->id]))
            ->assertSeeText('Выраб.');
    });

    test('разнорабочий видит "Выраб." всегда', function () {
        $dept   = Department::create(['name' => 'Цех Тест', 'is_active' => true]);
        $worker = makeOpUser($dept, 'Разнорабочий');

        $this->actingAs($worker)
            ->get(route('worker.dashboard.by-id', ['workerId' => $worker->worker->id]))
            ->assertSeeText('Выраб.');
    });

    test('мастер с пустым конфигом видит только всегда-видимый Дашборд, без других иконок', function () {
        $dept   = Department::create(['name' => 'Цех Тест', 'is_active' => true]);
        $master = makeOpUser($dept, 'Мастер');

        $response = $this->actingAs($master)->followingRedirects()->get('/');

        $response->assertSee('title="Дашборд"', false); // master-dashboard всегда виден мастеру
        $response->assertDontSee('title="Приём"', false);
        $response->assertDontSee('title="Сырьё"', false);
    });

    test('мастер видит включённые в его отделе операции', function () {
        $dept   = Department::create(['name' => 'Цех Тест', 'is_active' => true]);
        $master = makeOpUser($dept, 'Мастер');
        $admin  = makeOpAdmin();

        $this->actingAs($admin)->patch(
            route('admin.departments.operations.update', $dept),
            ['operations' => [
                'stone-receptions' => ['positions' => ['', 'Мастер']],
                'master-dashboard' => ['positions' => ['', 'Мастер']],
            ]]
        );

        $response = $this->actingAs($master)->followingRedirects()->get(route('stone-receptions.logs'));
        $response->assertSee('title="Приём"', false);
        $response->assertSee('title="Дашборд"', false);
        $response->assertDontSee('title="Сырьё"', false);
        $response->assertDontSee('title="Упак."', false);
    });

    test('помощник мастера видит только разрешённое в его отделе', function () {
        $dept      = Department::create(['name' => 'Цех Тест', 'is_active' => true]);
        $assistant = makeOpUser($dept, 'Помощник мастера');
        $admin     = makeOpAdmin();

        $this->actingAs($admin)->patch(
            route('admin.departments.operations.update', $dept),
            ['operations' => [
                'stone-receptions' => ['positions' => ['', 'Мастер', 'Помощник мастера']],
                'raw-batches'      => ['positions' => ['', 'Мастер']],
            ]]
        );

        $response = $this->actingAs($assistant)->followingRedirects()->get(route('stone-receptions.logs'));
        $response->assertSee('title="Приём"', false);
        $response->assertDontSee('title="Сырьё"', false);
    });

    test('мастер не видит иконку, включённую в чужом отделе', function () {
        $deptA   = Department::create(['name' => 'Цех А', 'is_active' => true]);
        $deptB   = Department::create(['name' => 'Цех Б', 'is_active' => true]);
        $masterA = makeOpUser($deptA, 'Мастер');
        $admin   = makeOpAdmin();

        $this->actingAs($admin)->patch(
            route('admin.departments.operations.update', $deptB),
            ['operations' => ['stone-receptions' => ['positions' => ['', 'Мастер']]]]
        );

        $response = $this->actingAs($masterA)->followingRedirects()->get('/');
        $response->assertDontSee('title="Приём"', false);
    });

});
