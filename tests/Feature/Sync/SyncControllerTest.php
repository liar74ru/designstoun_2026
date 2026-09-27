<?php

use App\Models\User;
use Tests\Helpers\ReceptionTestHelper as H;

// ══════════════════════════════════════════════════════════════════════════════
// SyncController::index()
// ══════════════════════════════════════════════════════════════════════════════

describe('SyncController::index()', function () {

    test('отображает страницу синхронизации для админа', function () {
        $user = H::adminUser();

        $this->actingAs($user)
            ->get(route('sync.index'))
            ->assertSuccessful()
            ->assertViewIs('sync.index');
    });

    test('недоступна для неадмина', function () {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get(route('sync.index'))
            ->assertForbidden();
    });
});
