<?php

use App\Models\Department;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

beforeEach(fn () => Cache::flush());

// ──────────────────────────────────────────────────────────────────────────────
// Хелперы
// ──────────────────────────────────────────────────────────────────────────────

// ──────────────────────────────────────────────────────────────────────────────
// User::isMaster()
// ──────────────────────────────────────────────────────────────────────────────

test('User::isMaster() возвращает true для должности Мастер', function () {
    $user = Access::userWithPosition('Мастер', Access::department());
    expect($user->isMaster())->toBeTrue();
});

test('User::isMaster() возвращает false для должности Работник', function () {
    $worker = Worker::create(['name' => 'Пильщиков', 'position' => 'Работник']);
    $user   = User::factory()->create(['worker_id' => $worker->id]);
    expect($user->isMaster())->toBeFalse();
});

test('User::isMaster() возвращает false для администратора без worker', function () {
    $user = H::adminUser();
    expect($user->isMaster())->toBeFalse();
});

test('User::isMaster() возвращает false если worker_id не задан', function () {
    $user = User::factory()->create(['worker_id' => null]);
    expect($user->isMaster())->toBeFalse();
});

// ──────────────────────────────────────────────────────────────────────────────
// Редирект после входа
// ──────────────────────────────────────────────────────────────────────────────

test('мастер после входа попадает на журнал приёмок', function () {
    $dept   = Department::create(['name' => 'Цех 1', 'is_active' => true]);
    $worker = Worker::create(['name' => 'Мастер Входов', 'position' => 'Мастер', 'department_id' => $dept->id]);
    User::factory()->create([
        'phone'     => '79991110001',
        'is_admin'  => false,
        'worker_id' => $worker->id,
        'password'  => bcrypt('password'),
    ]);

    $this->post('/login', ['login' => '79991110001', 'password' => 'password'])
        ->assertRedirect(route('stone-receptions.logs'));
});

test('рабочий после входа попадает на дашборд', function () {
    $worker = Worker::create(['name' => 'Пильщик Входов', 'position' => 'Работник']);
    User::factory()->create([
        'phone'     => '79991110002',
        'is_admin'  => false,
        'worker_id' => $worker->id,
        'password'  => bcrypt('password'),
    ]);

    $this->post('/login', ['login' => '79991110002', 'password' => 'password'])
        ->assertRedirect(route('worker.dashboard'));
});

// ──────────────────────────────────────────────────────────────────────────────
// Доступ мастера через Policy: операции его отдела
// ──────────────────────────────────────────────────────────────────────────────

test('мастер с разрешённой операцией видит журнал приёмок', function () {
    $dept = Department::create(['name' => 'Цех Тест', 'is_active' => true]);
    Access::allowOperation($dept, ['stone-receptions']);
    $user = Access::userWithPosition('Мастер', $dept);

    $this->actingAs($user)
        ->get(route('stone-receptions.logs'))
        ->assertRedirect(route('stone-receptions.index', ['view' => 'logs']));
});

test('мастер с разрешённой операцией видит список приёмок', function () {
    $dept = Department::create(['name' => 'Цех Тест', 'is_active' => true]);
    Access::allowOperation($dept, ['stone-receptions']);
    $user = Access::userWithPosition('Мастер', $dept);

    $this->actingAs($user)
        ->get(route('stone-receptions.index'))
        ->assertStatus(200);
});

test('мастер с разрешённой операцией видит список партий', function () {
    $dept = Department::create(['name' => 'Цех Тест', 'is_active' => true]);
    Access::allowOperation($dept, ['raw-batches']);
    $user = Access::userWithPosition('Мастер', $dept);

    $this->actingAs($user)
        ->get(route('raw-batches.index'))
        ->assertStatus(200);
});

test('мастер без операции в отделе получает 403', function () {
    $dept = Department::create(['name' => 'Цех Тест', 'is_active' => true]);
    $user = Access::userWithPosition('Мастер', $dept); // никакие операции не разрешены

    $this->actingAs($user)
        ->get(route('stone-receptions.index'))
        ->assertForbidden();
});

// ──────────────────────────────────────────────────────────────────────────────
// Запрещённые админ-only страницы для мастера
// ──────────────────────────────────────────────────────────────────────────────

test('мастер без разрешения не видит список товаров — 403', function () {
    $user = Access::userWithPosition('Мастер', Access::department());

    $this->actingAs($user)
        ->get(route('products.index'))
        ->assertForbidden();
});

test('мастер с разрешением на products видит список товаров', function () {
    $dept = Department::create(['name' => 'Цех Тест', 'is_active' => true]);
    Access::allowOperation($dept, ['products']);
    $user = Access::userWithPosition('Мастер', $dept);

    $this->actingAs($user)
        ->get(route('products.index'))
        ->assertStatus(200);
});

test('мастер без разрешения не видит список заказов — 403', function () {
    $user = Access::userWithPosition('Мастер', Access::department());

    $this->actingAs($user)
        ->get(route('orders.index'))
        ->assertForbidden();
});

test('мастер не может открыть список складов — 403', function () {
    $user = Access::userWithPosition('Мастер', Access::department());

    $this->actingAs($user)
        ->get(route('stores.index'))
        ->assertForbidden();
});

test('мастер не может открыть worker.dashboard (не его операция) — 403', function () {
    $user = Access::userWithPosition('Мастер', Access::department());

    $this->actingAs($user)
        ->get(route('worker.dashboard'))
        ->assertForbidden();
});

// ──────────────────────────────────────────────────────────────────────────────
// home redirect
// ──────────────────────────────────────────────────────────────────────────────

test('мастер при заходе на главную редиректится на дашборд мастера', function () {
    $user = Access::userWithPosition('Мастер', Access::department());

    $this->actingAs($user)
        ->get(route('home'))
        ->assertRedirect(route('master.dashboard'));
});

// ──────────────────────────────────────────────────────────────────────────────
// Другие роли
// ──────────────────────────────────────────────────────────────────────────────

test('администратор видит products', function () {
    $admin = H::adminUser();

    $this->actingAs($admin)
        ->get(route('products.index'))
        ->assertStatus(200);
});

test('рабочий не видит products — 403', function () {
    $worker = Worker::create(['name' => 'Пильщиков Тест', 'position' => 'Работник']);
    $user   = User::factory()->create(['worker_id' => $worker->id, 'is_admin' => false]);

    $this->actingAs($user)
        ->get(route('products.index'))
        ->assertForbidden();
});

// ──────────────────────────────────────────────────────────────────────────────
// AJAX-эндпоинты
// ──────────────────────────────────────────────────────────────────────────────

test('мастер с правами на приёмки может использовать AJAX api.worker.batches', function () {
    $dept   = Department::create(['name' => 'Цех AJAX', 'is_active' => true]);
    Access::allowOperation($dept, ['stone-receptions']);
    $user   = Access::userWithPosition('Мастер', $dept);
    $cutter = Worker::create(['name' => 'Пильщик AJAX', 'position' => 'Работник']);

    $this->actingAs($user)
        ->getJson(route('api.worker.batches', $cutter))
        ->assertStatus(200);
});
