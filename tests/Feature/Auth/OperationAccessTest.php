<?php

use App\Models\Department;
use App\Models\DepartmentOperationSetting;
use App\Models\User;
use App\Models\Worker;
use App\Support\OperationAccessor;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

function opUserWith(string $position, ?Department $dept = null): User
{
    $worker = Worker::create([
        'name'          => $position . ' Тест',
        'position'      => $position,
        'department_id' => $dept?->id,
    ]);
    return User::factory()->create(['is_admin' => false, 'worker_id' => $worker->id]);
}

function opAdminUser(): User
{
    return User::factory()->create(['is_admin' => true, 'worker_id' => null]);
}

function opAllowMasterFor(Department $dept, string $opKey): void
{
    DepartmentOperationSetting::updateOrCreate(
        ['department_id' => $dept->id, 'operation_key' => $opKey],
        ['enabled' => true, 'config' => ['positions' => ['Мастер']]],
    );
    $dept->forgetOperationsCache();
}

describe('OperationAccessor::canSee()', function () {

    test('админ видит любую операцию реестра', function () {
        $admin = opAdminUser();
        foreach (array_keys(config('department_operations')) as $key) {
            expect(OperationAccessor::canSee($admin, $key))->toBeTrue();
        }
    });

    test('null-пользователь не видит ничего', function () {
        expect(OperationAccessor::canSee(null, 'stone-receptions'))->toBeFalse();
    });

    test('admin_only-операция недоступна не-админу (admin-settings)', function () {
        $dept = Department::create(['name' => 'Цех', 'is_active' => true]);
        $user = opUserWith('Мастер', $dept);
        expect(OperationAccessor::canSee($user, 'admin-settings'))->toBeFalse();
    });

    test('configurable products/orders доступны мастеру с разрешением', function () {
        $dept = Department::create(['name' => 'Цех', 'is_active' => true]);
        $user = opUserWith('Мастер', $dept);

        opAllowMasterFor($dept, 'products');
        expect(OperationAccessor::canSee($user, 'products'))->toBeTrue();

        // orders без разрешения — не виден
        expect(OperationAccessor::canSee($user, 'orders'))->toBeFalse();
    });

    test('Работник видит worker-dashboard всегда (always_visible)', function () {
        $dept = Department::create(['name' => 'Цех', 'is_active' => true]);
        $user = opUserWith('Работник', $dept);
        expect(OperationAccessor::canSee($user, 'worker-dashboard'))->toBeTrue();
    });

    test('Разнорабочий видит worker-dashboard всегда', function () {
        $dept = Department::create(['name' => 'Цех', 'is_active' => true]);
        $user = opUserWith('Разнорабочий', $dept);
        expect(OperationAccessor::canSee($user, 'worker-dashboard'))->toBeTrue();
    });

    test('Работник не видит configurable-операции', function () {
        $dept = Department::create(['name' => 'Цех', 'is_active' => true]);
        $user = opUserWith('Работник', $dept);
        opAllowMasterFor($dept, 'stone-receptions');
        expect(OperationAccessor::canSee($user, 'stone-receptions'))->toBeFalse();
    });

    test('Мастер с разрешением в отделе видит операцию', function () {
        $dept = Department::create(['name' => 'Цех', 'is_active' => true]);
        $user = opUserWith('Мастер', $dept);
        opAllowMasterFor($dept, 'stone-receptions');
        expect(OperationAccessor::canSee($user, 'stone-receptions'))->toBeTrue();
    });

    test('Мастер без разрешения в отделе не видит операцию', function () {
        $dept = Department::create(['name' => 'Цех', 'is_active' => true]);
        $user = opUserWith('Мастер', $dept);
        expect(OperationAccessor::canSee($user, 'stone-receptions'))->toBeFalse();
    });

    test('Мастер без отдела видит only positions_always_visible, не configurable', function () {
        $user = opUserWith('Мастер', null);
        expect(OperationAccessor::canSee($user, 'stone-receptions'))->toBeFalse();
        // master-dashboard в positions_always_visible — виден всегда
        expect(OperationAccessor::canSee($user, 'master-dashboard'))->toBeTrue();
    });

    test('Помощник мастера видит операцию только если разрешено для его позиции', function () {
        $dept = Department::create(['name' => 'Цех', 'is_active' => true]);
        $assistant = opUserWith('Помощник мастера', $dept);

        DepartmentOperationSetting::create([
            'department_id' => $dept->id,
            'operation_key' => 'stone-receptions',
            'enabled'       => true,
            'config'        => ['positions' => ['Мастер']],
        ]);
        expect(OperationAccessor::canSee($assistant, 'stone-receptions'))->toBeFalse();

        DepartmentOperationSetting::where('department_id', $dept->id)
            ->where('operation_key', 'stone-receptions')
            ->update(['config' => json_encode(['positions' => ['Мастер', 'Помощник мастера']])]);
        $dept->forgetOperationsCache();

        expect(OperationAccessor::canSee($assistant->fresh(), 'stone-receptions'))->toBeTrue();
    });

    test('Мастер не видит операции включённые только в чужом отделе', function () {
        $deptA = Department::create(['name' => 'A', 'is_active' => true]);
        $deptB = Department::create(['name' => 'B', 'is_active' => true]);
        $userA = opUserWith('Мастер', $deptA);
        opAllowMasterFor($deptB, 'stone-receptions');

        expect(OperationAccessor::canSee($userA, 'stone-receptions'))->toBeFalse();
    });

});
