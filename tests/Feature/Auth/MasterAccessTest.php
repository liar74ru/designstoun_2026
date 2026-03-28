<?php

use App\Models\User;
use App\Models\Worker;
use App\Models\RawMaterialBatch;
use App\Models\Product;
use App\Models\Store;

// ──────────────────────────────────────────────────────────────────────────────
// Вспомогательная функция: создать пользователя-мастера
// ──────────────────────────────────────────────────────────────────────────────

function makeMasterUser(): User
{
    $worker = Worker::create(['name' => 'Мастер Тестов', 'position' => 'Мастер']);

    return User::factory()->create([
        'is_admin'  => false,
        'worker_id' => $worker->id,
    ]);
}

// ──────────────────────────────────────────────────────────────────────────────
// User::isMaster() — метод модели
// ──────────────────────────────────────────────────────────────────────────────

test('User::isMaster() возвращает true для должности Мастер', function () {
    $user = makeMasterUser();

    expect($user->isMaster())->toBeTrue();
});

test('User::isMaster() возвращает false для должности Пильщик', function () {
    $worker = Worker::create(['name' => 'Пильщиков', 'position' => 'Пильщик']);
    $user   = User::factory()->create(['worker_id' => $worker->id]);

    expect($user->isMaster())->toBeFalse();
});

test('User::isMaster() возвращает false для администратора без worker', function () {
    $user = User::factory()->create(['is_admin' => true, 'worker_id' => null]);

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
    $worker = Worker::create(['name' => 'Мастер Входов', 'position' => 'Мастер']);
    User::factory()->create([
        'phone'     => '79991110001',
        'is_admin'  => false,
        'worker_id' => $worker->id,
        'password'  => bcrypt('password'),
    ]);

    $this->post('/login', ['login' => '79991110001', 'password' => 'password'])
        ->assertRedirect(route('stone-receptions.logs'));
});

test('рабочий после входа попадает на дашборд, а не на журнал приёмок', function () {
    $worker = Worker::create(['name' => 'Пильщик Входов', 'position' => 'Пильщик']);
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
// MasterOnly middleware — страницы, доступные мастеру
// ──────────────────────────────────────────────────────────────────────────────

test('мастер может открыть журнал приёмок', function () {
    $user = makeMasterUser();

    $this->actingAs($user)
        ->get(route('stone-receptions.logs'))
        ->assertStatus(200);
});

test('мастер может открыть список приёмок', function () {
    $user = makeMasterUser();

    $this->actingAs($user)
        ->get(route('stone-receptions.index'))
        ->assertStatus(200);
});

test('мастер может открыть форму создания приёмки', function () {
    $user = makeMasterUser();

    $this->actingAs($user)
        ->get(route('stone-receptions.create'))
        ->assertStatus(200);
});

test('мастер может открыть список партий сырья', function () {
    $user = makeMasterUser();

    $this->actingAs($user)
        ->get(route('raw-batches.index'))
        ->assertStatus(200);
});

test('мастер может открыть форму создания партии', function () {
    $user = makeMasterUser();

    $this->actingAs($user)
        ->get(route('raw-batches.create'))
        ->assertStatus(200);
});

test('мастер может открыть страницу смены пароля', function () {
    $user = makeMasterUser();

    $this->actingAs($user)
        ->get(route('workers.edit-user', $user->worker))
        ->assertStatus(200);
});

// ──────────────────────────────────────────────────────────────────────────────
// MasterOnly middleware — страницы, НЕДОСТУПНЫЕ мастеру
// ──────────────────────────────────────────────────────────────────────────────

test('мастер не может открыть главную страницу — редирект на журнал приёмок', function () {
    $user = makeMasterUser();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertRedirect(route('stone-receptions.logs'));
});

test('мастер не может открыть список товаров — редирект на журнал приёмок', function () {
    $user = makeMasterUser();

    $this->actingAs($user)
        ->get(route('products.index'))
        ->assertRedirect(route('stone-receptions.logs'));
});

test('мастер не может открыть список заказов — редирект на журнал приёмок', function () {
    $user = makeMasterUser();

    $this->actingAs($user)
        ->get(route('orders.index'))
        ->assertRedirect(route('stone-receptions.logs'));
});

test('мастер не может открыть список работников — редирект на журнал приёмок', function () {
    $user = makeMasterUser();

    $this->actingAs($user)
        ->get(route('workers.index'))
        ->assertRedirect(route('stone-receptions.logs'));
});

test('мастер не может открыть список складов — редирект на журнал приёмок', function () {
    $user = makeMasterUser();

    $this->actingAs($user)
        ->get(route('stores.index'))
        ->assertRedirect(route('stone-receptions.logs'));
});

test('мастер не может открыть дашборд работника — редирект на журнал приёмок', function () {
    $user = makeMasterUser();

    $this->actingAs($user)
        ->get(route('worker.dashboard'))
        ->assertRedirect(route('stone-receptions.logs'));
});

// ──────────────────────────────────────────────────────────────────────────────
// MasterOnly middleware — другие роли не затронуты
// ──────────────────────────────────────────────────────────────────────────────

test('администратор не ограничен middleware мастера', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('products.index'))
        ->assertStatus(200);
});

test('рабочий не ограничен middleware мастера (его ограничивает WorkerOnly)', function () {
    $worker = Worker::create(['name' => 'Пильщиков Тест', 'position' => 'Пильщик']);
    $user   = User::factory()->create(['worker_id' => $worker->id, 'is_admin' => false]);

    // WorkerOnly должен редиректить на worker.dashboard, а не на stone-receptions.logs
    $this->actingAs($user)
        ->get(route('products.index'))
        ->assertRedirect(route('worker.dashboard'));
});

// ──────────────────────────────────────────────────────────────────────────────
// MasterOnly middleware — AJAX-эндпоинты доступны мастеру
// ──────────────────────────────────────────────────────────────────────────────

test('мастер может использовать AJAX-эндпоинт дерева товаров', function () {
    $user = makeMasterUser();

    $this->actingAs($user)
        ->getJson(route('api.products.tree'))
        ->assertStatus(200);
});

test('мастер может использовать AJAX-эндпоинт партий работника', function () {
    $cutter = Worker::create(['name' => 'Пильщик AJAX', 'position' => 'Пильщик']);
    $user   = makeMasterUser();

    $this->actingAs($user)
        ->getJson(route('api.worker.batches', $cutter))
        ->assertStatus(200);
});
