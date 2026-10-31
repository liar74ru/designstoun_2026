<?php

use App\Models\Workshop;
use App\Models\Worker;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

/**
 * Фильтр «Работник» в списке цеха: короткий список — работники отделов
 * пользователя без уволенных, остальные — по чекбоксу «Показать всех работников».
 */

beforeEach(function () {
    $this->dept      = Access::department();
    $this->extraDept = Access::department();
    $this->otherDept = Access::department();
    $this->master    = Access::master($this->dept, 'workshops');
    $this->master->worker->departments()->attach($this->extraDept->id);

    $this->own     = workshopPacker($this->dept, 'Свой');
    $this->extra   = workshopPacker($this->extraDept, 'Из второго отдела');
    $this->foreign = workshopPacker($this->otherDept, 'Чужой');
    $this->fired   = workshopPacker($this->dept, 'Уволенный');
    $this->fired->archive();
});

/** Работник отдела с одной операцией: без документов в фильтр он не попадёт. */
function workshopPacker($dept, string $name): Worker
{
    $packer = Worker::create(['name' => $name, 'position' => 'Работник', 'department_id' => $dept->id]);

    Workshop::create([
        'packer_id'     => $packer->id,
        'receiver_id'   => $packer->id,
        'store_id'      => H::store('Склад ' . $name)->id,
        'department_id' => $dept->id,
        'status'        => Workshop::STATUS_ACTIVE,
    ]);

    return $packer;
}

test('мастеру по умолчанию — работники его отделов без уволенных и чужих', function () {
    $response = $this->actingAs($this->master)->get(route('workshops.index'))->assertOk();

    expect($response->viewData('cutterDefaultIds'))
        ->toEqualCanonicalizing([$this->own->id, $this->extra->id]);
});

test('полный список по-прежнему содержит чужих и уволенных', function () {
    $response = $this->actingAs($this->master)->get(route('workshops.index'))->assertOk();

    expect($response->viewData('filterPackers')->pluck('id')->all())
        ->toEqualCanonicalizing([$this->own->id, $this->extra->id, $this->foreign->id, $this->fired->id]);
});

test('админу по умолчанию — все неуволенные независимо от отдела', function () {
    $response = $this->actingAs(H::adminUser())->get(route('workshops.index'))->assertOk();

    expect($response->viewData('cutterDefaultIds'))
        ->toEqualCanonicalizing([$this->own->id, $this->extra->id, $this->foreign->id]);
});

test('чекбокс выключен, если работник выбран из короткого списка', function () {
    $response = $this->actingAs($this->master)
        ->get(route('workshops.index', ['filter' => ['packer_id' => $this->own->id]]))
        ->assertOk()
        ->assertSee('Показать всех работников');

    expect($response->getContent())->not->toMatch('/id="filter_all_workers"\s+checked/');
});

test('выбран уволенный: чекбокс включён и фильтр применяется', function () {
    $response = $this->actingAs($this->master)
        ->get(route('workshops.index', ['filter' => ['packer_id' => $this->fired->id]]))
        ->assertOk();

    expect($response->getContent())->toMatch('/id="filter_all_workers"\s+checked/');
    expect($response->viewData('workshops')->getCollection()->pluck('packer_id')->unique()->all())
        ->toBe([$this->fired->id]);
});
