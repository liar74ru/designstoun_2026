<?php

use App\Models\Worker;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

/**
 * Фильтр «Работник» в списке приёмок: короткий список — работники отделов
 * пользователя без уволенных, остальные — по чекбоксу «Показать всех работников».
 */

beforeEach(function () {
    $this->dept      = Access::department();
    $this->extraDept = Access::department();
    $this->otherDept = Access::department();
    $this->master    = Access::master($this->dept, 'stone-receptions');
    $this->master->worker->departments()->attach($this->extraDept->id);

    $this->own     = receptionCutter($this->dept, 'Свой');
    $this->extra   = receptionCutter($this->extraDept, 'Из второго отдела');
    $this->foreign = receptionCutter($this->otherDept, 'Чужой');
    $this->fired   = receptionCutter($this->dept, 'Уволенный');
    $this->fired->archive();
});

/** Пильщик отдела с одной приёмкой: без документов в фильтр он не попадёт. */
function receptionCutter($dept, string $name): Worker
{
    $cutter = Worker::create(['name' => $name, 'position' => 'Работник', 'department_id' => $dept->id]);
    $store  = H::store('Склад ' . $name);
    $batch  = H::batch(H::product(), $store, $cutter, 50.0, ['batch_number' => 'BN-' . $cutter->id]);

    H::reception($batch, H::worker('Приёмщик ' . $name), $cutter, $store, 1.0, ['department_id' => $dept->id]);

    return $cutter;
}

test('мастеру по умолчанию — работники его отделов без уволенных и чужих', function () {
    $response = $this->actingAs($this->master)->get(route('stone-receptions.index'))->assertOk();

    expect($response->viewData('cutterDefaultIds'))
        ->toEqualCanonicalizing([$this->own->id, $this->extra->id]);
});

test('полный список по-прежнему содержит чужих и уволенных', function () {
    $response = $this->actingAs($this->master)->get(route('stone-receptions.index'))->assertOk();

    expect($response->viewData('filterCutters')->pluck('id')->all())
        ->toEqualCanonicalizing([$this->own->id, $this->extra->id, $this->foreign->id, $this->fired->id]);
});

test('админу по умолчанию — все неуволенные независимо от отдела', function () {
    $response = $this->actingAs(H::adminUser())->get(route('stone-receptions.index'))->assertOk();

    expect($response->viewData('cutterDefaultIds'))
        ->toEqualCanonicalizing([$this->own->id, $this->extra->id, $this->foreign->id]);
});

test('чекбокс выключен, если работник не выбран или выбран из короткого списка', function (?string $who) {
    $params = $who ? ['filter' => ['cutter_id' => $this->{$who}->id]] : [];

    $response = $this->actingAs($this->master)->get(route('stone-receptions.index', $params))
        ->assertOk()
        ->assertSee('Показать всех работников');

    expect($response->getContent())->not->toMatch('/id="filter_all_workers"\s+checked/');
})->with([null, 'own']);

test('выбран уволенный: чекбокс включён и фильтр применяется', function () {
    $response = $this->actingAs($this->master)
        ->get(route('stone-receptions.index', ['filter' => ['cutter_id' => $this->fired->id]]))
        ->assertOk();

    expect($response->getContent())->toMatch('/id="filter_all_workers"\s+checked/');
    expect($response->viewData('receptions')->getCollection()->pluck('cutter_id')->unique()->all())
        ->toBe([$this->fired->id]);
});

test('без отдела короткий список пуст', function () {
    expect(Worker::activeInDepartments([])->get())->toBeEmpty();
});

test('на странице без короткого списка чекбокса нет', function () {
    $this->actingAs(H::adminUser())->get(route('raw-batches.index'))
        ->assertOk()
        ->assertDontSee('Показать всех работников');
});
