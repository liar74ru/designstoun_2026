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
        ->assertRedirect(route('dashboard'));
});

test('работник после входа может перейти на my-work', function () {
    $worker = Worker::create(['name' => 'Иванов Иван', 'position' => 'Пильщик']);

    $user = User::factory()->create([
        'phone'     => '79991234567',
        'is_admin'  => false,
        'worker_id' => $worker->id,
        'password'  => bcrypt('password'),
    ]);

    // Входим
    $this->post('/login', ['login' => '79991234567', 'password' => 'password']);

    // Работник может открыть свою страницу выработки
    $this->actingAs($user)->get('/my-work')->assertStatus(200);
});

// ──────────────────────────────────────────────────────────────────────────────
// Выход
// ──────────────────────────────────────────────────────────────────────────────

test('пользователь может выйти из системы', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post('/logout');
    $this->assertGuest();
});
