<?php

use App\Models\User;
use App\Models\Worker;

// ──────────────────────────────────────────────────────────────────────────────
// Страница логина
// ──────────────────────────────────────────────────────────────────────────────

test('страница входа отображается', function () {
    $this->get('/login')->assertStatus(200);
});

// ──────────────────────────────────────────────────────────────────────────────
// Вход по телефону
// ──────────────────────────────────────────────────────────────────────────────

test('пользователь может войти по номеру телефона', function () {
    User::factory()->create([
        'phone'    => '79991234567',
        'password' => bcrypt('password'),
    ]);

    $this->post('/login', ['login' => '79991234567', 'password' => 'password'])
        ->assertRedirect();

    $this->assertAuthenticated();
});

test('пользователь может войти с телефоном в формате +7 (999) 123-45-67', function () {
    User::factory()->create([
        'phone'    => '79991234567',
        'password' => bcrypt('password'),
    ]);

    $this->post('/login', ['login' => '+7 (999) 123-45-67', 'password' => 'password'])
        ->assertRedirect();

    $this->assertAuthenticated();
});

test('пользователь может войти по email', function () {
    User::factory()->create([
        'email'    => 'test@example.com',
        'password' => bcrypt('password'),
    ]);

    $this->post('/login', ['login' => 'test@example.com', 'password' => 'password'])
        ->assertRedirect();

    $this->assertAuthenticated();
});

test('вход с неверным паролем не авторизует', function () {
    User::factory()->create([
        'phone'    => '79991234567',
        'password' => bcrypt('correct'),
    ]);

    $this->post('/login', ['login' => '79991234567', 'password' => 'wrong']);

    $this->assertGuest();
});

test('вход несуществующего пользователя не авторизует', function () {
    $this->post('/login', ['login' => '70000000000', 'password' => 'password']);
    $this->assertGuest();
});

// ──────────────────────────────────────────────────────────────────────────────
// Архивные работники
// ──────────────────────────────────────────────────────────────────────────────

function loginUser(array $workerAttrs = [], array $userAttrs = []): User
{
    $worker = Worker::create($workerAttrs + ['name' => 'Иванов Иван', 'position' => 'Работник']);

    return User::factory()->create($userAttrs + [
        'phone'     => '79991234567',
        'worker_id' => $worker->id,
        'password'  => bcrypt('password'),
    ]);
}

test('работник в архиве не может войти', function () {
    loginUser(['archived_at' => now()]);

    $this->post('/login', ['login' => '79991234567', 'password' => 'password'])
        ->assertSessionHasErrors('login');

    $this->assertGuest();
});

test('после возврата из архива вход снова работает', function () {
    $user = loginUser(['archived_at' => now()]);
    $user->worker->restore();

    $this->post('/login', ['login' => '79991234567', 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
});

test('перевод в архив обрывает действующую сессию', function () {
    $user = loginUser();

    $this->actingAs($user)->get('/my-work')->assertOk();

    $user->worker->archive();

    $this->actingAs($user->fresh())->get('/my-work')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('login');

    $this->assertGuest();
});

test('админ в архиве не входит, если есть другой действующий админ', function () {
    loginUser(['archived_at' => now()], ['is_admin' => true]);
    User::factory()->create(['is_admin' => true]);

    $this->post('/login', ['login' => '79991234567', 'password' => 'password']);

    $this->assertGuest();
});

test('единственный админ в архиве может войти', function () {
    $user = loginUser(['archived_at' => now()], ['is_admin' => true]);

    $this->post('/login', ['login' => '79991234567', 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
});

test('админ в архиве может войти, если остальные админы тоже в архиве', function () {
    $user = loginUser(['archived_at' => now()], ['is_admin' => true]);
    loginUser(['archived_at' => now()], ['is_admin' => true, 'phone' => '79990000000']);

    $this->post('/login', ['login' => '79991234567', 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
});

// ──────────────────────────────────────────────────────────────────────────────
// Лимит попыток
// ──────────────────────────────────────────────────────────────────────────────

test('разные форматы одного телефона считаются одним логином', function () {
    User::factory()->create(['phone' => '79991234567', 'password' => bcrypt('password')]);

    foreach (['79991234567', '+79991234567', '+7 999 123-45-67', '7 (999) 1234567', '7-999-123-45-67'] as $format) {
        $this->post('/login', ['login' => $format, 'password' => 'wrong']);
    }

    $this->post('/login', ['login' => '+7 (999) 123-45-67', 'password' => 'password'])
        ->assertSessionHasErrors('login');

    $this->assertGuest();
});

test('с одного адреса не больше 30 попыток входа в минуту', function () {
    foreach (range(1, 30) as $i) {
        $this->post('/login', ['login' => '7000000' . $i, 'password' => 'wrong'])->assertRedirect();
    }

    $this->post('/login', ['login' => '70000099', 'password' => 'wrong'])->assertStatus(429);
});

// ──────────────────────────────────────────────────────────────────────────────
// Редиректы после входа
// ──────────────────────────────────────────────────────────────────────────────

test('после входа пользователь попадает на dashboard', function () {
    // Контроллер всегда редиректит на dashboard (intended),
    // роле-разграничение — на уровне самих страниц, не редиректа
    User::factory()->create([
        'phone'    => '79991234567',
        'is_admin' => true,
        'password' => bcrypt('password'),
    ]);

    $this->post('/login', ['login' => '79991234567', 'password' => 'password'])
        ->assertRedirect(route('home'));
});

test('работник после входа может перейти на my-work', function () {
    $worker = Worker::create(['name' => 'Иванов Иван', 'position' => 'Работник']);

    $user = User::factory()->create([
        'phone'     => '79991234567',
        'is_admin'  => false,
        'worker_id' => $worker->id,
        'password'  => bcrypt('password'),
    ]);

    // Входим
    $this->post('/login', ['login' => '79991234567', 'password' => 'password']);

    // Страница открывается в сессии, созданной входом, — без actingAs
    $this->assertAuthenticatedAs($user);
    $this->get('/my-work')->assertStatus(200);
});

// ──────────────────────────────────────────────────────────────────────────────
// Выход
// ──────────────────────────────────────────────────────────────────────────────

test('пользователь может выйти из системы', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post('/logout');
    $this->assertGuest();
});
