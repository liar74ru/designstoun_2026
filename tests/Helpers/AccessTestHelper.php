<?php

namespace Tests\Helpers;

use App\Models\Department;
use App\Models\DepartmentOperationSetting;
use App\Models\User;
use App\Models\Worker;

/**
 * Пользователи и права доступа для тестов: отдел, операция отдела, пользователь с позицией.
 *
 * Раньше в каждом модуле была своя копия «мастера с включённой операцией» — около
 * десятка функций, различавшихся только ключом операции. Админ — ReceptionTestHelper::adminUser().
 */
class AccessTestHelper
{
    /** Активный отдел; имя уникально, если не задано. */
    public static function department(?string $name = null): Department
    {
        return Department::create(['name' => $name ?? 'Цех ' . uniqid(), 'is_active' => true]);
    }

    /**
     * Включить отделу операции реестра для позиций (по умолчанию — для мастера).
     *
     * @param  string|array<int, string>  $operations  ключи config/department_operations.php
     * @param  array<int, string>  $positions
     */
    public static function allowOperation(Department $dept, string|array $operations, array $positions = ['Мастер']): void
    {
        foreach ((array) $operations as $key) {
            DepartmentOperationSetting::updateOrCreate(
                ['department_id' => $dept->id, 'operation_key' => $key],
                ['enabled' => true, 'config' => ['positions' => $positions]],
            );
        }

        $dept->forgetOperationsCache();
    }

    /** Пользователь-работник с позицией из Worker::POSITIONS; отдел — основной. */
    public static function userWithPosition(string $position, ?Department $dept = null, ?string $name = null): User
    {
        $worker = Worker::create([
            'name'          => $name ?? $position . ' ' . ($dept?->name ?? 'без отдела'),
            'position'      => $position,
            'department_id' => $dept?->id,
        ]);

        return User::factory()->create(['is_admin' => false, 'worker_id' => $worker->id]);
    }

    /** Мастер отдела с включёнными операциями. */
    public static function master(Department $dept, string|array $operations, ?string $name = null): User
    {
        self::allowOperation($dept, $operations);

        return self::userWithPosition('Мастер', $dept, $name);
    }

    /** Мастер без отдела — не видит ничего, кроме захардкоженного. */
    public static function masterWithoutDept(): User
    {
        return self::userWithPosition('Мастер');
    }
}
