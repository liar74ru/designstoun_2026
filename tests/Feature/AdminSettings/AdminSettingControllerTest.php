<?php

use App\Models\Setting;
use App\Models\User;
use App\Models\Worker;

// ══════════════════════════════════════════════════════════════════════════════
// AdminSettingController
// ══════════════════════════════════════════════════════════════════════════════

function makeAdmin(): User
{
    return User::factory()->create(['is_admin' => true, 'worker_id' => null]);
}

function makeMaster(): User
{
    $worker = Worker::create(['name' => 'Мастер', 'position' => 'Мастер']);
    return User::factory()->create(['is_admin' => false, 'worker_id' => $worker->id]);
}

describe('GET /admin/settings', function () {

    test('администратор видит страницу настроек', function () {
        Setting::create(['key' => 'PIECE_RATE', 'value' => '390', 'label' => 'Ставка']);

        $this->actingAs(makeAdmin())
            ->get('/admin/settings')
            ->assertOk()
            ->assertSee('390');
    });

    test('гость перенаправляется на login', function () {
        $this->get('/admin/settings')
            ->assertRedirect('/login');
    });

    test('работник получает 403 на /admin/settings', function () {
        $worker = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
        $user   = User::factory()->create(['is_admin' => false, 'worker_id' => $worker->id]);

        $this->actingAs($user)
            ->get('/admin/settings')
            ->assertForbidden();
    });

    test('мастер получает 403 на /admin/settings', function () {
        $this->actingAs(makeMaster())
            ->get('/admin/settings')
            ->assertForbidden();
    });

});

describe('POST /admin/settings', function () {

    test('администратор сохраняет настройки', function () {
        Setting::create(['key' => 'PIECE_RATE', 'value' => '390', 'label' => 'Ставка']);

        $this->actingAs(makeAdmin())
            ->post('/admin/settings', [
                'settings' => [
                    ['key' => 'PIECE_RATE', 'value' => '450'],
                ],
            ])
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHas('success');

        expect(Setting::where('key', 'PIECE_RATE')->value('value'))->toBe('450');
    });

    test('значение не может быть отрицательным', function () {
        Setting::create(['key' => 'PIECE_RATE', 'value' => '390', 'label' => 'Ставка']);

        $this->actingAs(makeAdmin())
            ->post('/admin/settings', [
                'settings' => [
                    ['key' => 'PIECE_RATE', 'value' => '-10'],
                ],
            ])
            ->assertSessionHasErrors();
    });

    test('не-админ получает 403 при попытке сохранить настройки', function () {
        $worker = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
        $user   = User::factory()->create(['is_admin' => false, 'worker_id' => $worker->id]);

        $this->actingAs($user)
            ->post('/admin/settings', ['settings' => [['key' => 'X', 'value' => '1']]])
            ->assertForbidden();
    });

});
