<?php

use App\Models\PiggyBankEntry;
use App\Models\Product;
use App\Models\ReceptionLog;
use App\Models\ReceptionLogItem;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use App\Models\Store;
use App\Models\User;
use App\Models\Worker;
use App\Services\SalaryService;
use Carbon\Carbon;
use Tests\Helpers\ReceptionTestHelper as H;

// Начисление «сейчас»: приёмка на 100 м², ставка задаёт сумму. Возвращает позицию — её ставку
// правят тесты перерасчёта.
function salaryAccrue(Worker $worker, float $amount): StoneReceptionItem
{
    $receiver = Worker::create(['name' => 'Мастер', 'position' => 'Мастер']);
    $product  = Product::factory()->create();

    $reception = StoneReception::create([
        'cutter_id'   => $worker->id,
        'receiver_id' => $receiver->id,
        'store_id'    => Store::factory()->create()->id,
        'status'      => 'in_work',
    ]);

    $item = StoneReceptionItem::create([
        'stone_reception_id' => $reception->id,
        'product_id'         => $product->id,
        'quantity'           => 100,
        'worker_cost_per_m2' => $amount / 100,
        'master_cost_per_m2' => 0,
    ]);

    $log = ReceptionLog::create([
        'stone_reception_id' => $reception->id,
        'cutter_id'          => $worker->id,
        'receiver_id'        => $receiver->id,
        'type'               => ReceptionLog::TYPE_CREATED,
        'raw_quantity_delta' => 0,
    ]);
    ReceptionLogItem::create([
        'reception_log_id' => $log->id,
        'product_id'       => $product->id,
        'quantity_delta'   => 100,
    ]);

    return $item;
}

// Четверг после отсечки: неделя 23.10 – 29.10 открыта для выдачи.
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-29 22:00:00'));

    $this->worker = Worker::create(['name' => 'Иванов', 'position' => 'Работник']);
    $this->user   = User::factory()->create(['worker_id' => $this->worker->id, 'is_admin' => false]);
    $this->admin  = H::adminUser();
});

// ──────────────────────────────────────────────────────────────────────────────
// Выдача и копилка
// ──────────────────────────────────────────────────────────────────────────────

test('выдали меньше начисленного: остаток уходит в копилку', function () {
    salaryAccrue($this->worker, 37000);

    $this->actingAs($this->admin)
        ->post(route('salary.paid', $this->worker), ['paid_cash' => 31000, 'paid_tax' => 1237])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $payout = $this->worker->salaryPayouts()->sole();
    expect((float) $payout->accrued)->toBe(37000.0)
        ->and((float) $payout->paid_cash)->toBe(31000.0)
        ->and($payout->paid_card)->toBeNull()
        ->and((float) $payout->paid_tax)->toBe(1237.0)
        ->and((float) $payout->paid_total)->toBe(32237.0)
        ->and((float) $payout->to_bank)->toBe(4763.0);

    $entry = $this->worker->piggyBankEntries()->sole();
    expect((float) $entry->amount)->toBe(4763.0)
        ->and($entry->type)->toBe(PiggyBankEntry::TYPE_PAYOUT)
        ->and($entry->salary_payout_id)->toBe($payout->id);
});

test('выдали больше начисленного: разница берётся из копилки', function () {
    salaryAccrue($this->worker, 37000);

    $this->actingAs($this->admin)
        ->post(route('salary.paid', $this->worker), ['paid_card' => 40000]);

    expect((float) $this->worker->salaryPayouts()->sole()->to_bank)->toBe(-3000.0)
        ->and(app(SalaryService::class)->piggyBalance($this->worker))->toBe(-3000.0);
});

test('выдали ровно начисленное: проводки в копилку нет', function () {
    salaryAccrue($this->worker, 37000);

    $this->actingAs($this->admin)
        ->post(route('salary.paid', $this->worker), ['paid_cash' => 30000, 'paid_card' => 7000]);

    expect($this->worker->salaryPayouts()->count())->toBe(1)
        ->and($this->worker->piggyBankEntries()->count())->toBe(0);
});

test('выдача без сумм закрывает неделю, всё начисленное уходит в копилку', function () {
    salaryAccrue($this->worker, 37000);

    $this->actingAs($this->admin)->post(route('salary.paid', $this->worker));

    expect((float) $this->worker->salaryPayouts()->sole()->paid_total)->toBe(0.0)
        ->and(app(SalaryService::class)->piggyBalance($this->worker))->toBe(37000.0);
});

test('повторная выдача за ту же неделю отклоняется', function () {
    salaryAccrue($this->worker, 37000);

    $this->actingAs($this->admin)->post(route('salary.paid', $this->worker), ['paid_cash' => 33000]);
    $this->actingAs($this->admin)
        ->post(route('salary.paid', $this->worker), ['paid_cash' => 33000])
        ->assertSessionHasErrors('paid');

    expect($this->worker->salaryPayouts()->count())->toBe(1)
        ->and($this->worker->piggyBankEntries()->count())->toBe(1);
});

test('выдача запоминает запрошенную сумму и сбрасывает её у работника', function () {
    salaryAccrue($this->worker, 37000);
    $this->worker->update(['requested_payout' => 30000]);

    $this->actingAs($this->admin)->post(route('salary.paid', $this->worker), ['paid_cash' => 30000]);

    expect((float) $this->worker->salaryPayouts()->sole()->requested_amount)->toBe(30000.0)
        ->and($this->worker->fresh()->requested_payout)->toBeNull();
});

test('не выданные недели складываются в следующую выдачу', function () {
    salaryAccrue($this->worker, 10000);
    $this->actingAs($this->admin)->post(route('salary.paid', $this->worker), ['paid_cash' => 10000]);

    $this->travelTo(Carbon::parse('2026-11-05 22:00:00'));
    salaryAccrue($this->worker, 20000);

    $this->travelTo(Carbon::parse('2026-11-12 22:00:00'));
    salaryAccrue($this->worker, 5000);
    $this->actingAs($this->admin)->post(route('salary.paid', $this->worker), ['paid_cash' => 25000]);

    $payout = $this->worker->salaryPayouts()->latest('period_to')->first();
    expect((float) $payout->accrued)->toBe(25000.0)
        ->and($payout->period_from->toDateString())->toBe('2026-10-30')
        ->and($payout->period_to->toDateString())->toBe('2026-11-13');
});

test('отрицательная сумма выдачи отклоняется', function () {
    $this->actingAs($this->admin)
        ->post(route('salary.paid', $this->worker), ['paid_cash' => -1])
        ->assertSessionHasErrors('paid_cash');

    expect($this->worker->salaryPayouts()->count())->toBe(0);
});

// ──────────────────────────────────────────────────────────────────────────────
// Перерасчёт выданных недель
// ──────────────────────────────────────────────────────────────────────────────

test('перерасчёт выданной недели уходит в копилку при следующей выдаче и только один раз', function () {
    $item = salaryAccrue($this->worker, 37000);
    $this->actingAs($this->admin)->post(route('salary.paid', $this->worker), ['paid_cash' => 37000]);

    $item->update(['worker_cost_per_m2' => 400]); // 40 000 вместо 37 000

    $this->travelTo(Carbon::parse('2026-11-05 22:00:00'));
    expect(app(SalaryService::class)->recalc($this->worker))->toBe(3000.0);

    $this->actingAs($this->admin)->post(route('salary.paid', $this->worker));

    $payouts = $this->worker->salaryPayouts()->orderBy('period_from')->get();
    expect((float) $payouts[0]->accounted)->toBe(40000.0)
        ->and((float) $payouts[1]->recalc)->toBe(3000.0)
        ->and((float) $payouts[1]->to_bank)->toBe(3000.0)
        ->and(app(SalaryService::class)->recalc($this->worker))->toBe(0.0);
});

test('перерасчёт старше четырёх недель не учитывается', function () {
    $item = salaryAccrue($this->worker, 37000);
    $this->actingAs($this->admin)->post(route('salary.paid', $this->worker), ['paid_cash' => 37000]);

    $item->update(['worker_cost_per_m2' => 400]);

    $this->travelTo(Carbon::parse('2026-12-03 22:00:00'));

    expect(app(SalaryService::class)->recalc($this->worker))->toBe(0.0);
});

// ──────────────────────────────────────────────────────────────────────────────
// «Хочу получить»
// ──────────────────────────────────────────────────────────────────────────────

test('работник сохраняет сумму кратную шагу и возвращает её к значению по умолчанию', function () {
    salaryAccrue($this->worker, 37000);

    $this->actingAs($this->user)
        ->patch(route('salary.request', $this->worker), ['amount' => 30500])
        ->assertSessionHasNoErrors();
    expect((float) $this->worker->fresh()->requested_payout)->toBe(30500.0);

    $this->actingAs($this->user)
        ->patch(route('salary.request', $this->worker), ['amount' => 30500, 'reset' => 1])
        ->assertSessionHasNoErrors();
    expect($this->worker->fresh()->requested_payout)->toBeNull();
});

test('работник не может сохранить сумму не кратную шагу', function () {
    salaryAccrue($this->worker, 37000);

    $this->actingAs($this->user)
        ->patch(route('salary.request', $this->worker), ['amount' => 30250])
        ->assertSessionHasErrors('amount');

    expect($this->worker->fresh()->requested_payout)->toBeNull();
});

test('работнику доступно не больше начисленного плюс копилка', function () {
    salaryAccrue($this->worker, 37000);
    app(SalaryService::class)->adjustPiggy($this->worker, 12500, 'Стартовый остаток', $this->admin);

    $this->actingAs($this->user)
        ->patch(route('salary.request', $this->worker), ['amount' => 50000])
        ->assertSessionHasErrors('amount');

    $this->actingAs($this->user)
        ->patch(route('salary.request', $this->worker), ['amount' => 49500])
        ->assertSessionHasNoErrors();
});

test('скрытый остаток не раскрывается через текст ошибки о пределе', function () {
    salaryAccrue($this->worker, 37000);
    $this->worker->update(['hide_piggy_balance' => true]);

    $this->actingAs($this->user)
        ->patch(route('salary.request', $this->worker), ['amount' => 50000])
        ->assertSessionHasErrors(['amount' => 'Не больше допустимого (начислено + копилка).']);
});

test('без начислений работник сумму не выбирает', function () {
    $this->actingAs($this->user)
        ->patch(route('salary.request', $this->worker), ['amount' => 500])
        ->assertSessionHasErrors('amount');
});

test('после выдачи работник меняет сумму только с четверга 21:00', function () {
    salaryAccrue($this->worker, 37000);
    $this->actingAs($this->admin)->post(route('salary.paid', $this->worker), ['paid_cash' => 33000]);

    $this->travelTo(Carbon::parse('2026-11-05 20:59:00'));
    salaryAccrue($this->worker, 10000);

    $this->actingAs($this->user)
        ->patch(route('salary.request', $this->worker), ['amount' => 5000])
        ->assertSessionHasErrors('amount');

    $this->travelTo(Carbon::parse('2026-11-05 21:00:00'));

    $this->actingAs($this->user)
        ->patch(route('salary.request', $this->worker), ['amount' => 5000])
        ->assertSessionHasNoErrors();
});

test('администратор задаёт сумму без шага и предела', function () {
    $this->actingAs($this->admin)
        ->patch(route('salary.request', $this->worker), ['amount' => 99250])
        ->assertSessionHasNoErrors();

    expect((float) $this->worker->fresh()->requested_payout)->toBe(99250.0);
});

// ──────────────────────────────────────────────────────────────────────────────
// Сумма по умолчанию и корректировка копилки
// ──────────────────────────────────────────────────────────────────────────────

test('сумма к выдаче по умолчанию', function (float $accrued, float $expected) {
    expect(app(SalaryService::class)->defaultToPay($accrued))->toBe($expected);
})->with([
    'минус 10% до тысячи'          => [37000, 33000],
    'округление вверх'             => [37450, 34000],
    'не больше начисленного'       => [900, 0],
    'нет начислений'               => [0, 0],
    'отрицательное начисленное'    => [-500, 0],
]);

test('администратор проводит корректировку копилки со знаком', function () {
    $this->actingAs($this->admin)
        ->post(route('salary.piggy-adjust', $this->worker), ['piggy_amount' => -5000, 'piggy_comment' => 'Аванс'])
        ->assertSessionHasNoErrors();

    $entry = $this->worker->piggyBankEntries()->sole();
    expect((float) $entry->amount)->toBe(-5000.0)
        ->and($entry->type)->toBe(PiggyBankEntry::TYPE_ADJUSTMENT)
        ->and($entry->comment)->toBe('Аванс')
        ->and(app(SalaryService::class)->piggyBalance($this->worker))->toBe(-5000.0);
});

test('корректировка копилки без комментария отклоняется', function () {
    $this->actingAs($this->admin)
        ->post(route('salary.piggy-adjust', $this->worker), ['piggy_amount' => 1000])
        ->assertSessionHasErrors('piggy_comment');

    expect($this->worker->piggyBankEntries()->count())->toBe(0);
});

// ──────────────────────────────────────────────────────────────────────────────
// Доступ
// ──────────────────────────────────────────────────────────────────────────────

test('работник не может отметить выдачу и скорректировать копилку', function () {
    salaryAccrue($this->worker, 37000);

    $this->actingAs($this->user)
        ->post(route('salary.paid', $this->worker), ['paid_cash' => 37000])
        ->assertForbidden();
    $this->actingAs($this->user)
        ->post(route('salary.piggy-adjust', $this->worker), ['piggy_amount' => 1000, 'piggy_comment' => 'Себе'])
        ->assertForbidden();

    expect($this->worker->salaryPayouts()->count())->toBe(0)
        ->and($this->worker->piggyBankEntries()->count())->toBe(0);
});

test('работник не может менять сумму к выдаче другого работника', function () {
    $other     = Worker::create(['name' => 'Петров', 'position' => 'Работник']);
    $otherUser = User::factory()->create(['worker_id' => $other->id, 'is_admin' => false]);

    $this->actingAs($otherUser)
        ->patch(route('salary.request', $this->worker), ['amount' => 500])
        ->assertForbidden();

    expect($this->worker->fresh()->requested_payout)->toBeNull();
});

// ──────────────────────────────────────────────────────────────────────────────
// Форма выдачи
// ──────────────────────────────────────────────────────────────────────────────

test('администратор видит форму выдачи с данными для расчёта наличных', function () {
    salaryAccrue($this->worker, 37000);
    app(SalaryService::class)->adjustPiggy($this->worker, 12500, 'Стартовый остаток', $this->admin);

    $this->actingAs($this->admin)
        ->get("/workers/{$this->worker->id}/dashboard")
        ->assertOk()
        ->assertSee(route('salary.paid', $this->worker))
        ->assertSee('data-gross="37000"', false)
        ->assertSee('data-balance="12500"', false)
        ->assertSee('name="paid_cash"', false)
        ->assertSee('id="payout-piggy"', false);
});

test('работник формы выдачи не видит', function () {
    salaryAccrue($this->worker, 37000);

    $this->actingAs($this->user)
        ->get('/my-work')
        ->assertOk()
        ->assertDontSee(route('salary.paid', $this->worker))
        ->assertDontSee('id="payout-piggy"', false);
});

test('после выдачи формы нет до следующей отсечки', function () {
    salaryAccrue($this->worker, 37000);
    $this->actingAs($this->admin)->post(route('salary.paid', $this->worker), ['paid_cash' => 33000]);

    $this->actingAs($this->admin)
        ->get("/workers/{$this->worker->id}/dashboard")
        ->assertOk()
        ->assertDontSee(route('salary.paid', $this->worker));
});

test('скрытый остаток на своей странице администратора не попадает в разметку формы', function () {
    salaryAccrue($this->worker, 37000);
    app(SalaryService::class)->adjustPiggy($this->worker, 12500, 'Стартовый остаток', $this->admin);
    $this->worker->update(['hide_piggy_balance' => true]);
    $ownAdmin = User::factory()->create(['worker_id' => $this->worker->id, 'is_admin' => true]);

    $this->actingAs($ownAdmin)
        ->get("/workers/{$this->worker->id}/dashboard")
        ->assertOk()
        ->assertSee('id="payout-piggy"', false)
        ->assertDontSee('data-balance', false);
});
