<?php

use App\Models\Department;
use App\Models\DepartmentSetting;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerHourEntry;
use App\Services\SalaryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

/**
 * Часы ремонтных работ (WorkerHourService, WorkerHourEntryPolicy, docs/pay.md).
 *
 * Четверг после отсечки: выдач ещё не было, невыплаченная неделя — 23.10 – 29.10.
 */
beforeEach(function () {
    Cache::flush();
    $this->travelTo(Carbon::parse('2026-10-29 22:00:00'));

    $this->dept = Access::department('Резка');
    DepartmentSetting::create(['department_id' => $this->dept->id, 'key' => 'HOURLY_RATE', 'value' => '500']);

    $this->worker = Worker::create(['name' => 'Иванов', 'position' => 'Работник', 'department_id' => $this->dept->id]);
    $this->user   = User::factory()->create(['worker_id' => $this->worker->id, 'is_admin' => false]);
    $this->admin  = H::adminUser();
});

function addHours($test, User $as, Worker $worker, array $data = [])
{
    return $test->actingAs($as)->post(route('worker-hours.store', $worker), $data + [
        'work_date' => '2026-10-28',
        'hours'     => 2.5,
        'comment'   => 'Замена ленты',
    ]);
}

function hoursEntry(Worker $worker, array $attrs = []): WorkerHourEntry
{
    return $worker->hourEntries()->create($attrs + [
        'department_id' => $worker->department_id,
        'work_date'     => '2026-10-28',
        'hours'         => 2,
        'hourly_rate'   => 500,
        'amount'        => 1000,
    ]);
}

test('работник добавляет часы: сумма по ставке отдела', function () {
    addHours($this, $this->user, $this->worker)->assertRedirect()->assertSessionHasNoErrors();

    $entry = $this->worker->hourEntries()->sole();
    expect($entry->hours)->toBe(2.5)
        ->and($entry->hourly_rate)->toBe(500.0)
        ->and($entry->amount)->toBe(1250.0)
        ->and($entry->department_id)->toBe($this->dept->id)
        ->and($entry->comment)->toBe('Замена ленты')
        ->and($entry->created_by_user_id)->toBe($this->user->id);
});

test('комментарий необязателен', function () {
    addHours($this, $this->user, $this->worker, ['comment' => ''])->assertSessionHasNoErrors();

    expect($this->worker->hourEntries()->sole()->comment)->toBeNull();
});

test('ставка фиксируется в записи: новая ставка отдела не меняет сумму при правке', function () {
    addHours($this, $this->user, $this->worker);
    $entry = $this->worker->hourEntries()->sole();

    DepartmentSetting::where('department_id', $this->dept->id)->update(['value' => '800']);
    Department::find($this->dept->id)->forgetSettingsCache();

    $this->actingAs($this->user)
        ->put(route('worker-hours.update', $entry), ['work_date' => '2026-10-28', 'hours' => 3])
        ->assertRedirect(route('worker.dashboard'));

    expect($entry->fresh()->amount)->toBe(1500.0)
        ->and($entry->fresh()->hourly_rate)->toBe(500.0);
});

test('часы входят в начисление недели — копилку и выдачу', function () {
    addHours($this, $this->user, $this->worker);

    expect(app(SalaryService::class)->summary($this->worker)['accrued'])->toBe(1250.0);

    $this->actingAs($this->user)->get(route('worker.dashboard'))
        ->assertOk()
        ->assertSee('Ремонтные работы')
        ->assertSee('Замена ленты')
        // Плитка «Итого начислено» видна и без приёмок за период
        ->assertSeeInOrder(['Итого начислено', '1 250 ₽']);
});

test('дата вне невыплаченной недели или в будущем — ошибка', function (string $date) {
    addHours($this, $this->user, $this->worker, ['work_date' => $date])->assertSessionHasErrors('work_date');

    expect($this->worker->hourEntries()->count())->toBe(0);
})->with(['до недели' => '2026-10-22', 'завтра' => '2026-10-30']);

test('часы — от 0,25 до 24 с шагом 0,25', function (float $hours) {
    addHours($this, $this->user, $this->worker, ['hours' => $hours])->assertSessionHasErrors('hours');
})->with(['ноль' => 0, 'больше суток' => 24.25, 'не кратно 15 минутам' => 1.1]);

test('добавить часы другому нельзя — ни коллеге, ни мастеру', function (string $who) {
    $as = match ($who) {
        'коллега' => Access::userWithPosition('Работник', $this->dept),
        'мастер'  => Access::userWithPosition('Мастер', $this->dept),
    };

    addHours($this, $as, $this->worker)->assertForbidden();

    expect($this->worker->hourEntries()->count())->toBe(0);
})->with(['коллега', 'мастер']);

test('админ добавляет часы за работника, и в выданную неделю', function () {
    addHours($this, $this->admin, $this->worker, ['work_date' => '2026-10-15'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $entry = $this->worker->hourEntries()->sole();
    expect($entry->amount)->toBe(1250.0)
        ->and($entry->created_by_user_id)->toBe($this->admin->id);
});

test('мастер своего отдела правит и удаляет, чужого и коллега — нет', function () {
    $entry = hoursEntry($this->worker);

    $this->actingAs(Access::userWithPosition('Мастер', Access::department()))
        ->put(route('worker-hours.update', $entry), ['work_date' => '2026-10-28', 'hours' => 1])
        ->assertForbidden();
    $this->actingAs(Access::userWithPosition('Работник', $this->dept))
        ->delete(route('worker-hours.destroy', $entry))
        ->assertForbidden();

    $this->actingAs(Access::userWithPosition('Мастер', $this->dept))
        ->put(route('worker-hours.update', $entry), ['work_date' => '2026-10-28', 'hours' => 1])
        ->assertRedirect(route('worker.dashboard.by-id', $this->worker->id));

    expect($entry->fresh()->amount)->toBe(500.0);
});

test('после выдачи работник часы недели не правит и не удаляет, админ — может', function () {
    $entry = hoursEntry($this->worker);

    $this->actingAs($this->admin)
        ->post(route('salary.paid', $this->worker), ['paid_cash' => 1000])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->user)
        ->put(route('worker-hours.update', $entry), ['work_date' => '2026-10-28', 'hours' => 5])
        ->assertForbidden();
    $this->actingAs($this->user)->delete(route('worker-hours.destroy', $entry))->assertForbidden();

    $this->actingAs($this->admin)
        ->put(route('worker-hours.update', $entry), ['work_date' => '2026-10-28', 'hours' => 3])
        ->assertRedirect(route('worker.dashboard.by-id', $this->worker->id));

    expect($entry->fresh()->amount)->toBe(1500.0);
});

test('работник удаляет свою запись в невыплаченной неделе', function () {
    $entry = hoursEntry($this->worker);

    $this->actingAs($this->user)
        ->delete(route('worker-hours.destroy', $entry))
        ->assertRedirect();

    expect(WorkerHourEntry::find($entry->id))->toBeNull();
});

test('ставка не задана — часы учитываются без оплаты', function () {
    DepartmentSetting::where('department_id', $this->dept->id)->delete();
    Department::find($this->dept->id)->forgetSettingsCache();

    addHours($this, $this->user, $this->worker)->assertSessionHasNoErrors();

    expect($this->worker->hourEntries()->sole()->amount)->toBe(0.0);
});

test('блок часов виден и без записей: админу — с формой, мастеру — без', function () {
    $this->actingAs($this->admin)->get(route('worker.dashboard.by-id', $this->worker->id))
        ->assertOk()
        ->assertSee('За период часов нет')
        ->assertSee(route('worker-hours.store', $this->worker), false);

    $this->actingAs(Access::userWithPosition('Мастер', $this->dept))
        ->get(route('worker.dashboard.by-id', $this->worker->id))
        ->assertOk()
        ->assertSee('Ремонтные работы')
        ->assertDontSee(route('worker-hours.store', $this->worker), false);
});

test('«За всё время» — с первой выработки или часов по сегодня', function () {
    hoursEntry($this->worker, ['work_date' => '2026-10-27']);

    $this->actingAs($this->user)->get(route('worker.dashboard'))
        ->assertOk()
        ->assertSee('data-week="all" data-from="2026-10-27" data-to="2026-10-29"', false);
});

test('блок часов свёрнут, если за текущую неделю часов нет', function () {
    // Часы только за прошлую неделю — блок свёрнут
    hoursEntry($this->worker, ['work_date' => '2026-10-22']);
    $this->actingAs($this->user)->get(route('worker.dashboard'))
        ->assertOk()
        ->assertSee('id="worker-hours-body"  style="display:none"', false);

    // Появились часы в текущей неделе (Пт 23.10 – Чт 29.10) — раскрыт
    hoursEntry($this->worker, ['work_date' => '2026-10-28']);
    $this->actingAs($this->user)->get(route('worker.dashboard'))
        ->assertOk()
        ->assertDontSee('id="worker-hours-body"  style="display:none"', false);
});
