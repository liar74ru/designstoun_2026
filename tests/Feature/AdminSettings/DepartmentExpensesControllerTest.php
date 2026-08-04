<?php

use App\Models\Department;
use App\Models\DepartmentExpense;
use App\Models\User;
use App\Models\Worker;
use App\Support\DepartmentSettings;
use Illuminate\Support\Facades\Cache;

// ══════════════════════════════════════════════════════════════════════════════
// PATCH /admin/departments/{department}/expenses
// ══════════════════════════════════════════════════════════════════════════════

function makeExpenseAdmin(): User
{
    return User::factory()->create(['is_admin' => true, 'worker_id' => null]);
}

beforeEach(function () {
    Cache::flush();
    $this->dept = Department::create(['name' => 'Отдел расходов', 'is_active' => true]);
});

function saveExpenses($test, Department $dept, array $expenses)
{
    return $test->actingAs(makeExpenseAdmin())
        ->patch(route('admin.departments.expenses.update', $dept), ['expenses' => $expenses]);
}

test('администратор сохраняет строки с сохранением порядка', function () {
    saveExpenses($this, $this->dept, [
        ['name' => 'Расход пилы', 'amount' => '50'],
        ['name' => 'Аренда',      'amount' => '35.50'],
        ['name' => 'Экскаватор',  'amount' => '500'],
    ])
        ->assertRedirect(route('admin.departments.show', $this->dept))
        ->assertSessionHas('success');

    $rows = $this->dept->expenses()->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('name')->all())->toBe(['Расход пилы', 'Аренда', 'Экскаватор'])
        ->and(DepartmentSettings::overheadPerUnit($this->dept->id))->toBe(585.50);
});

test('повторное сохранение полностью заменяет список', function () {
    saveExpenses($this, $this->dept, [
        ['name' => 'Старый 1', 'amount' => '10'],
        ['name' => 'Старый 2', 'amount' => '20'],
    ]);

    saveExpenses($this, $this->dept, [
        ['name' => 'Новый', 'amount' => '99'],
    ]);

    $rows = $this->dept->expenses()->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->name)->toBe('Новый');
});

test('пустое имя сохраняется как «Расход №N» с подряд идущей нумерацией', function () {
    saveExpenses($this, $this->dept, [
        ['name' => '',       'amount' => '10'],
        ['name' => 'Аренда', 'amount' => '20'],
        ['name' => '   ',    'amount' => '30'],
    ]);

    expect($this->dept->expenses()->pluck('name')->all())
        ->toBe(['Расход №1', 'Аренда', 'Расход №3']);
});

test('полностью пустая строка игнорируется и не сдвигает нумерацию', function () {
    saveExpenses($this, $this->dept, [
        ['name' => '', 'amount' => ''],
        ['name' => '', 'amount' => '10'],
        ['name' => '', 'amount' => ''],
        ['name' => '', 'amount' => '20'],
    ]);

    expect($this->dept->expenses()->pluck('name')->all())
        ->toBe(['Расход №1', 'Расход №2']);
});

test('пустая сумма при заданном имени сохраняется как 0', function () {
    saveExpenses($this, $this->dept, [
        ['name' => 'Пока не посчитали', 'amount' => ''],
    ]);

    expect((float) $this->dept->expenses()->first()->amount)->toBe(0.0)
        ->and(DepartmentSettings::overheadPerUnit($this->dept->id))->toBe(0.0);
});

test('пустой список очищает расходы отдела', function () {
    DepartmentExpense::create([
        'department_id' => $this->dept->id,
        'name'          => 'Аренда',
        'amount'        => 35,
    ]);

    $this->actingAs(makeExpenseAdmin())
        ->patch(route('admin.departments.expenses.update', $this->dept), [])
        ->assertRedirect(route('admin.departments.show', $this->dept));

    expect($this->dept->expenses()->count())->toBe(0)
        ->and(DepartmentSettings::overheadPerUnit($this->dept->id))->toBe(0.0);
});

test('отрицательная сумма отклоняется', function () {
    saveExpenses($this, $this->dept, [
        ['name' => 'Аренда', 'amount' => '-5'],
    ])->assertSessionHasErrors('expenses.0.amount');

    expect($this->dept->expenses()->count())->toBe(0);
});

test('нечисловая сумма отклоняется', function () {
    saveExpenses($this, $this->dept, [
        ['name' => 'Аренда', 'amount' => 'abc'],
    ])->assertSessionHasErrors('expenses.0.amount');
});

test('имя длиннее 100 символов отклоняется', function () {
    saveExpenses($this, $this->dept, [
        ['name' => str_repeat('я', 101), 'amount' => '10'],
    ])->assertSessionHasErrors('expenses.0.name');

    expect($this->dept->expenses()->count())->toBe(0);
});

test('не-админ получает 403', function () {
    $worker = Worker::create([
        'name'          => 'Мастер',
        'position'      => 'Мастер',
        'department_id' => $this->dept->id,
    ]);
    $user = User::factory()->create(['is_admin' => false, 'worker_id' => $worker->id]);

    $this->actingAs($user)
        ->patch(route('admin.departments.expenses.update', $this->dept), [
            'expenses' => [['name' => 'Аренда', 'amount' => '35']],
        ])
        ->assertForbidden();
});

test('кэш суммы сбрасывается после сохранения', function () {
    // прогреваем кэш пустой суммой
    expect(DepartmentSettings::overheadPerUnit($this->dept->id))->toBe(0.0);

    saveExpenses($this, $this->dept, [
        ['name' => 'Аренда', 'amount' => '35'],
    ]);

    expect(DepartmentSettings::overheadPerUnit($this->dept->id))->toBe(35.0);
});

test('расходы чужого отдела не затрагиваются', function () {
    $other = Department::create(['name' => 'Другой отдел', 'is_active' => true]);
    DepartmentExpense::create([
        'department_id' => $other->id,
        'name'          => 'Кран',
        'amount'        => 200,
    ]);

    saveExpenses($this, $this->dept, [
        ['name' => 'Аренда', 'amount' => '35'],
    ]);

    expect($other->expenses()->count())->toBe(1)
        ->and(DepartmentSettings::overheadPerUnit($other->id))->toBe(200.0);
});

test('удаление отдела удаляет его расходы', function () {
    DepartmentExpense::create([
        'department_id' => $this->dept->id,
        'name'          => 'Аренда',
        'amount'        => 35,
    ]);

    $this->dept->delete();

    expect(DepartmentExpense::where('department_id', $this->dept->id)->count())->toBe(0);
});
