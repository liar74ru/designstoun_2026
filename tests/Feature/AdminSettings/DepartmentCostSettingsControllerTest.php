<?php

use App\Models\Department;
use App\Models\DepartmentSetting;
use App\Models\User;
use App\Models\Worker;
use App\Support\DepartmentSettings;
use Illuminate\Support\Facades\Cache;

// ══════════════════════════════════════════════════════════════════════════════
// PATCH /admin/departments/{department}/cost-settings
// ══════════════════════════════════════════════════════════════════════════════

function makeCostAdmin(): User
{
    return User::factory()->create(['is_admin' => true, 'worker_id' => null]);
}

beforeEach(function () {
    Cache::flush();
    $this->dept = Department::create(['name' => 'Цех Себестоимость', 'is_active' => true]);
});

test('администратор сохраняет переопределения отдела', function () {
    $this->actingAs(makeCostAdmin())
        ->patch(route('admin.departments.cost-settings.update', $this->dept), [
            'settings' => [
                'MASTER_UNDERCUT_RATE' => '95',
                'MASTER_BASE_RATE'     => '150',
            ],
        ])
        ->assertRedirect(route('admin.departments.show', $this->dept))
        ->assertSessionHas('success');

    expect(DepartmentSettings::float($this->dept->id, 'MASTER_UNDERCUT_RATE'))->toBe(95.0);
    expect(DepartmentSettings::masterBaseRate($this->dept->id))->toBe(150.0);
});

test('пустое поле удаляет переопределение — значение снова наследуется', function () {
    DepartmentSetting::create([
        'department_id' => $this->dept->id,
        'key'           => 'MASTER_UNDERCUT_RATE',
        'value'         => '95',
    ]);

    $this->actingAs(makeCostAdmin())
        ->patch(route('admin.departments.cost-settings.update', $this->dept), [
            'settings' => ['MASTER_UNDERCUT_RATE' => ''],
        ])
        ->assertRedirect(route('admin.departments.show', $this->dept));

    expect(DepartmentSetting::where('department_id', $this->dept->id)->where('key', 'MASTER_UNDERCUT_RATE')->exists())
        ->toBeFalse();
});

test('ключ вне whitelist игнорируется (включая бывшие накладные)', function () {
    $this->actingAs(makeCostAdmin())
        ->patch(route('admin.departments.cost-settings.update', $this->dept), [
            'settings' => ['BLADE_WEAR' => '500', 'ELECTRICITY' => '95', 'HACK_KEY' => '1'],
        ])
        ->assertRedirect(route('admin.departments.show', $this->dept));

    expect(DepartmentSetting::where('department_id', $this->dept->id)->count())->toBe(0);
});

test('PIECE_RATE сохраняется как настройка отдела', function () {
    $this->actingAs(makeCostAdmin())
        ->patch(route('admin.departments.cost-settings.update', $this->dept), [
            'settings' => ['PIECE_RATE' => '420'],
        ])
        ->assertRedirect(route('admin.departments.show', $this->dept));

    expect(DepartmentSetting::where('department_id', $this->dept->id)->where('key', 'PIECE_RATE')->value('value'))
        ->toBe('420');
});

test('отрицательное значение отклоняется', function () {
    $this->actingAs(makeCostAdmin())
        ->patch(route('admin.departments.cost-settings.update', $this->dept), [
            'settings' => ['MASTER_UNDERCUT_RATE' => '-5'],
        ])
        ->assertSessionHasErrors('settings.MASTER_UNDERCUT_RATE');

    expect(DepartmentSetting::where('department_id', $this->dept->id)->count())->toBe(0);
});

test('нечисловое значение отклоняется', function () {
    $this->actingAs(makeCostAdmin())
        ->patch(route('admin.departments.cost-settings.update', $this->dept), [
            'settings' => ['MASTER_UNDERCUT_RATE' => 'abc'],
        ])
        ->assertSessionHasErrors('settings.MASTER_UNDERCUT_RATE');
});

test('не-админ получает 403', function () {
    $worker = Worker::create([
        'name'          => 'Мастер',
        'position'      => 'Мастер',
        'department_id' => $this->dept->id,
    ]);
    $user = User::factory()->create(['is_admin' => false, 'worker_id' => $worker->id]);

    $this->actingAs($user)
        ->patch(route('admin.departments.cost-settings.update', $this->dept), [
            'settings' => ['MASTER_UNDERCUT_RATE' => '95'],
        ])
        ->assertForbidden();
});

test('кэш отдела сбрасывается после сохранения', function () {
    // прогреваем кэш
    DepartmentSettings::float($this->dept->id, 'MASTER_UNDERCUT_RATE');

    $this->actingAs(makeCostAdmin())
        ->patch(route('admin.departments.cost-settings.update', $this->dept), [
            'settings' => ['MASTER_UNDERCUT_RATE' => '95'],
        ]);

    expect(DepartmentSettings::float($this->dept->id, 'MASTER_UNDERCUT_RATE'))->toBe(95.0);
});
