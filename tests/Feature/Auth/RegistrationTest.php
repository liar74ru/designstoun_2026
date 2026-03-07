<?php

use App\Models\User;
use App\Models\Worker;

test('registration screen can be rendered', function () {
    $this->get('/register')->assertStatus(200);
});

test('новый пользователь может зарегистрироваться', function () {
    $response = $this->post('/register', [
        'name'                  => 'Test User',
        'phone'                 => '79001234567',
        'password'              => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    // Первый пользователь — admin, редирект на dashboard
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('при регистрации аккаунт привязывается к работнику по телефону', function () {
    $worker = Worker::create([
        'name'  => 'Иванов Иван',
        'phone' => '79991234567',
    ]);

    // Создаём первого пользователя (он станет admin)
    User::factory()->create(['is_admin' => true]);

    $this->post('/register', [
        'name'                  => 'Иванов Иван',
        'phone'                 => '79991234567',
        'password'              => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('phone', '79991234567')->first();
    expect($user->worker_id)->toBe($worker->id);
});

test('работник после регистрации попадает на свою страницу выработки', function () {
    $worker = Worker::create([
        'name'  => 'Петров Пётр',
        'phone' => '79991234567',
    ]);

    // Создаём первого пользователя чтобы регистрирующийся не стал admin
    User::factory()->create(['is_admin' => true]);

    $response = $this->post('/register', [
        'name'                  => 'Петров Пётр',
        'phone'                 => '79991234567',
        'password'              => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('worker.dashboard'));
});
