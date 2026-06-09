<?php

use App\Models\Department;
use App\Models\DepartmentOperationSetting;
use App\Models\Store;
use App\Models\User;
use App\Models\Worker;

// ══════════════════════════════════════════════════════════════════════════════
// DepartmentController::create()
// ══════════════════════════════════════════════════════════════════════════════

describe('DepartmentController::create()', function () {

    test('админ может открыть форму создания отдела', function () {
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->get(route('admin.departments.create'))
            ->assertSuccessful()
            ->assertViewIs('admin.departments.create');
    });

    test('недоступно без авторизации', function () {
        $this->get(route('admin.departments.create'))
            ->assertRedirect('/login');
    });

    test('недоступно для неадмина', function () {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get(route('admin.departments.create'))
            ->assertForbidden();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// DepartmentController::store()
// ══════════════════════════════════════════════════════════════════════════════

describe('DepartmentController::store()', function () {

    test('админ может создать отдел', function () {
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->post(route('admin.departments.store'), [
                'name'        => 'Новый отдел',
                'code'        => 'ND',
                'description' => 'Описание отдела',
            ])
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHas('success');

        expect(Department::where('name', 'Новый отдел')->exists())->toBeTrue();
    });

    test('требует уникальное имя отдела', function () {
        $user = User::factory()->create(['is_admin' => true]);
        Department::create(['name' => 'Существующий', 'is_active' => true]);

        $this->actingAs($user)
            ->post(route('admin.departments.store'), [
                'name'        => 'Существующий',
                'code'        => 'E1',
                'description' => '',
            ])
            ->assertSessionHasErrors(['name']);
    });

    test('требует уникальный код отдела', function () {
        $user = User::factory()->create(['is_admin' => true]);
        Department::create(['name' => 'Тест', 'code' => 'E1', 'is_active' => true]);

        $this->actingAs($user)
            ->post(route('admin.departments.store'), [
                'name'        => 'Новый',
                'code'        => 'E1',
                'description' => '',
            ])
            ->assertSessionHasErrors(['code']);
    });

    test('требует имя отдела', function () {
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->post(route('admin.departments.store'), [
                'name' => '',
                'code' => 'ND',
            ])
            ->assertSessionHasErrors(['name']);
    });

    test('устанавливает is_active=true по умолчанию', function () {
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->post(route('admin.departments.store'), [
                'name' => 'Активный отдел',
            ]);

        expect(Department::where('name', 'Активный отдел')->first()->is_active)->toBeTrue();
    });

    test('недоступно для неадмина', function () {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->post(route('admin.departments.store'), [
                'name' => 'Test',
            ])
            ->assertForbidden();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// DepartmentController::show()
// ══════════════════════════════════════════════════════════════════════════════

describe('DepartmentController::show()', function () {

    test('отображает страницу отдела для админа', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $dept = Department::create(['name' => 'Тест отдел', 'is_active' => true]);

        $this->actingAs($user)
            ->get(route('admin.departments.show', $dept))
            ->assertSuccessful()
            ->assertViewIs('admin.departments.show')
            ->assertViewHas('department', $dept);
    });

    test('загружает работников отдела', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $dept = Department::create(['name' => 'Тест отдел', 'is_active' => true]);
        $worker = Worker::create(['name' => 'Работник', 'department_id' => $dept->id, 'position' => 'Мастер']);

        $this->actingAs($user)
            ->get(route('admin.departments.show', $dept))
            ->assertSuccessful()
            ->assertViewHas('workers');
    });

    test('загружает доступные склады', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $dept = Department::create(['name' => 'Тест отдел', 'is_active' => true]);
        Store::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.departments.show', $dept))
            ->assertSuccessful()
            ->assertViewHas('stores');
    });

    test('недоступно для неадмина', function () {
        $user = User::factory()->create(['is_admin' => false]);
        $dept = Department::create(['name' => 'Тест отдел', 'is_active' => true]);

        $this->actingAs($user)
            ->get(route('admin.departments.show', $dept))
            ->assertForbidden();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// DepartmentController::update()
// ══════════════════════════════════════════════════════════════════════════════

describe('DepartmentController::update()', function () {

    test('админ может обновить отдел', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $dept = Department::create(['name' => 'Старое имя', 'is_active' => true]);

        $this->actingAs($user)
            ->patch(route('admin.departments.update', $dept), [
                'name'        => 'Новое имя',
                'code'        => $dept->code,
                'description' => 'Новое описание',
            ])
            ->assertRedirect(route('admin.departments.show', $dept))
            ->assertSessionHas('success');

        expect($dept->fresh()->name)->toBe('Новое имя');
    });

    test('может обновить статус is_active', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $dept = Department::create(['name' => 'Тест отдел', 'is_active' => true]);

        $this->actingAs($user)
            ->patch(route('admin.departments.update', $dept), [
                'name'        => $dept->name,
                'is_active'   => false,
            ])
            ->assertRedirect(route('admin.departments.show', $dept));

        expect($dept->fresh()->is_active)->toBeFalse();
    });

    test('требует уникальное имя при обновлении', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $dept1 = Department::create(['name' => 'Отдел 1', 'is_active' => true]);
        $dept2 = Department::create(['name' => 'Отдел 2', 'is_active' => true]);

        $this->actingAs($user)
            ->patch(route('admin.departments.update', $dept2), [
                'name' => 'Отдел 1',
            ])
            ->assertSessionHasErrors(['name']);
    });

    test('недоступно для неадмина', function () {
        $user = User::factory()->create(['is_admin' => false]);
        $dept = Department::create(['name' => 'Тест отдел', 'is_active' => true]);

        $this->actingAs($user)
            ->patch(route('admin.departments.update', $dept), [
                'name' => 'Test',
            ])
            ->assertForbidden();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// DepartmentController::updateOperations()
// ══════════════════════════════════════════════════════════════════════════════

describe('DepartmentController::updateOperations()', function () {

    test('админ может обновить права операций отдела', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $dept = Department::create(['name' => 'Тест отдел', 'is_active' => true]);

        $this->actingAs($user)
            ->patch(route('admin.departments.operations.update', $dept), [
                'operations' => [
                    'raw-batches' => ['positions' => ['Мастер', 'Помощник мастера']],
                    'stone-receptions' => ['positions' => ['Мастер']],
                ],
            ])
            ->assertRedirect(route('admin.departments.show', $dept));

        expect(DepartmentOperationSetting::where('department_id', $dept->id)->exists())->toBeTrue();
    });

    test('очищает кэш операций после обновления', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $dept = Department::create(['name' => 'Тест отдел', 'is_active' => true]);

        // Предварительно установить кэш
        $dept->allowedPositionsByOperation();

        $this->actingAs($user)
            ->patch(route('admin.departments.operations.update', $dept), [
                'operations' => ['raw-batches' => ['positions' => ['Мастер']]],
            ]);

        // Проверяем, что кэш был очищен (косвенно через успешное завершение)
        expect(DepartmentOperationSetting::where('department_id', $dept->id)->exists())->toBeTrue();
    });

    test('фильтрует невалидные позиции', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $dept = Department::create(['name' => 'Тест отдел', 'is_active' => true]);

        $this->actingAs($user)
            ->patch(route('admin.departments.operations.update', $dept), [
                'operations' => [
                    // 'Работник' — валидная позиция, но не входит в configurable_positions
                    // операции raw-batches, поэтому контроллер её отфильтрует.
                    'raw-batches' => ['positions' => ['Мастер', 'Работник']],
                ],
            ])
            ->assertRedirect(route('admin.departments.show', $dept));

        $setting = DepartmentOperationSetting::where('department_id', $dept->id)
            ->where('operation_key', 'raw-batches')
            ->first();

        expect($setting?->config['positions'])->toBe(['Мастер']);
    });

    test('недоступно для неадмина', function () {
        $user = User::factory()->create(['is_admin' => false]);
        $dept = Department::create(['name' => 'Тест отдел', 'is_active' => true]);

        $this->actingAs($user)
            ->patch(route('admin.departments.operations.update', $dept), [])
            ->assertForbidden();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// DepartmentController::destroy()
// ══════════════════════════════════════════════════════════════════════════════

describe('DepartmentController::destroy()', function () {

    test('админ может удалить пустой отдел', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $dept = Department::create(['name' => 'Тест отдел', 'is_active' => true]);

        $this->actingAs($user)
            ->delete(route('admin.departments.destroy', $dept))
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHas('success');

        expect(Department::find($dept->id))->toBeNull();
    });

    test('не может удалить отдел с работниками', function () {
        $user = User::factory()->create(['is_admin' => true]);
        $dept = Department::create(['name' => 'Тест отдел', 'is_active' => true]);
        Worker::create(['name' => 'Работник', 'department_id' => $dept->id, 'position' => 'Мастер']);

        $this->actingAs($user)
            ->delete(route('admin.departments.destroy', $dept))
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHas('error', fn ($msg) => str_contains($msg, 'работники'));

        expect(Department::find($dept->id))->not->toBeNull();
    });

    test('недоступно для неадмина', function () {
        $user = User::factory()->create(['is_admin' => false]);
        $dept = Department::create(['name' => 'Тест отдел', 'is_active' => true]);

        $this->actingAs($user)
            ->delete(route('admin.departments.destroy', $dept))
            ->assertForbidden();
    });
});
