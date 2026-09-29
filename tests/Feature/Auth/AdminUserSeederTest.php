<?php

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Support\Facades\Hash;

/*
 * Первый администратор: в production стандартного пароля нет — только ADMIN_PASSWORD.
 */

function seedAdmin(): void
{
    (new AdminUserSeeder())->run();
}

afterEach(fn () => app()->detectEnvironment(fn () => 'testing'));

test('в production без ADMIN_PASSWORD администратор не создаётся', function () {
    app()->detectEnvironment(fn () => 'production');
    config()->set('app.admin.password', null);

    expect(fn () => seedAdmin())->toThrow(RuntimeException::class, 'ADMIN_PASSWORD не задан');

    expect(User::count())->toBe(0);
});

test('в production администратор получает пароль из ADMIN_PASSWORD и телефон из ADMIN_PHONE', function () {
    app()->detectEnvironment(fn () => 'production');
    config()->set('app.admin.password', 'Str0ng-pass');
    config()->set('app.admin.phone', '89990001122');

    seedAdmin();

    $admin = User::sole();
    expect($admin->phone)->toBe('89990001122');
    expect($admin->is_admin)->toBeTrue();
    expect(Hash::check('Str0ng-pass', $admin->password))->toBeTrue();
    expect(Hash::check('12345678', $admin->password))->toBeFalse();
    expect($admin->worker->position)->toBe('Администратор');
});

test('короткий ADMIN_PASSWORD отклоняется', function () {
    config()->set('app.admin.password', 'short');

    expect(fn () => seedAdmin())->toThrow(RuntimeException::class, 'короче 8');

    expect(User::count())->toBe(0);
});

test('вне production без ADMIN_PASSWORD — пароль для локальной установки', function () {
    config()->set('app.admin.password', null);

    seedAdmin();

    expect(Hash::check('12345678', User::sole()->password))->toBeTrue();
});
