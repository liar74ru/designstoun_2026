<?php

use App\Models\User;

// ══════════════════════════════════════════════════════════════════════════════
// SyncController::index()
// ══════════════════════════════════════════════════════════════════════════════

describe('SyncController::index()', function () {

    test('отображает страницу синхронизации для админа', function () {
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->get(route('sync.index'))
            ->assertSuccessful()
            ->assertViewIs('sync.index');
    });

    test('недоступна без авторизации', function () {
        $this->get(route('sync.index'))
            ->assertRedirect('/login');
    });

    test('недоступна для неадмина', function () {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get(route('sync.index'))
            ->assertForbidden();
    });
});
