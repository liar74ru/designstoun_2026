<?php

use App\Models\PiggyBankEntry;
use App\Models\User;
use App\Models\Worker;
use Tests\Helpers\ReceptionTestHelper as H;

// В пятницу период по умолчанию — прошлая неделя: уводим пятницу на четверг, как в WorkerDashboardTest.
beforeEach(function () {
    if (now()->isFriday()) {
        $this->travel(-1)->days();
    }

    $this->worker = Worker::create(['name' => 'Иванов', 'position' => 'Работник']);
    $this->user   = User::factory()->create(['worker_id' => $this->worker->id, 'is_admin' => false]);

    $this->worker->piggyBankEntries()->create([
        'amount'  => 12345,
        'type'    => PiggyBankEntry::TYPE_ADJUSTMENT,
        'comment' => 'Стартовый остаток',
    ]);
});

// ──────────────────────────────────────────────────────────────────────────────
// Переключение
// ──────────────────────────────────────────────────────────────────────────────

test('работник скрывает и снова показывает свой остаток копилки', function () {
    $this->actingAs($this->user)
        ->patch(route('salary.hide-balance', $this->worker), ['hide' => 1])
        ->assertRedirect();
    expect($this->worker->fresh()->hide_piggy_balance)->toBeTrue();

    $this->actingAs($this->user)
        ->patch(route('salary.hide-balance', $this->worker), ['hide' => 0])
        ->assertRedirect();
    expect($this->worker->fresh()->hide_piggy_balance)->toBeFalse();
});

test('чужому работнику переключить скрытие нельзя', function () {
    $other     = Worker::create(['name' => 'Петров', 'position' => 'Работник']);
    $otherUser = User::factory()->create(['worker_id' => $other->id, 'is_admin' => false]);

    $this->actingAs($otherUser)
        ->patch(route('salary.hide-balance', $this->worker), ['hide' => 1])
        ->assertForbidden();
    expect($this->worker->fresh()->hide_piggy_balance)->toBeFalse();
});

test('без значения hide запрос отклоняется', function () {
    $this->actingAs($this->user)
        ->patch(route('salary.hide-balance', $this->worker))
        ->assertSessionHasErrors('hide');
});

// ──────────────────────────────────────────────────────────────────────────────
// Отображение
// ──────────────────────────────────────────────────────────────────────────────

test('по умолчанию работник видит остаток и кнопку «скрыть»', function () {
    $this->actingAs($this->user)
        ->get('/my-work')
        ->assertOk()
        ->assertSee('12 345 ₽')
        ->assertSee('Скрыть остаток копилки')
        ->assertDontSee('***');
});

test('скрытый остаток работник видит как ***', function () {
    $this->worker->update(['hide_piggy_balance' => true]);

    $this->actingAs($this->user)
        ->get('/my-work')
        ->assertOk()
        ->assertSee('***')
        ->assertSee('Показать остаток копилки')
        ->assertDontSee('12 345 ₽');
});

test('администратор видит остаток, даже если работник его скрыл, и без кнопки-глаза', function () {
    $this->worker->update(['hide_piggy_balance' => true]);

    $this->actingAs(H::adminUser())
        ->get("/workers/{$this->worker->id}/dashboard")
        ->assertOk()
        ->assertSee('12 345 ₽')
        ->assertDontSee('***')
        ->assertDontSee(route('salary.hide-balance', $this->worker));
});
