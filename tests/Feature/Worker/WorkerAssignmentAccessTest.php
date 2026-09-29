<?php

use App\Models\Worker;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

/*
 * Назначение отделов и должностей в карточке работника.
 * Не-админ с see-workers не должен выдавать себе или подчинённым права
 * в чужих отделах и мастерские должности.
 */

beforeEach(fn () => Cache::flush());

function waWorker(string $name, string $position, array $departments): Worker
{
    $worker = Worker::create([
        'name'          => $name,
        'position'      => $position,
        'department_id' => $departments[0]->id,
    ]);
    $worker->departments()->syncWithoutDetaching(collect($departments)->pluck('id')->all());

    return $worker->fresh();
}

describe('Создание работника мастером', function () {

    test('нельзя создать работника в чужом отделе', function () {
        $own     = Access::department('Свой');
        $foreign = Access::department('Чужой');
        $master  = Access::master($own, 'workers');

        $this->actingAs($master)->post(route('workers.store'), [
            'name'           => 'Новый Работник',
            'position'       => 'Работник',
            'department_id'  => $foreign->id,
            'department_ids' => [$own->id, $foreign->id],
        ])->assertSessionHasErrors(['department_id', 'department_ids.1']);

        expect(Worker::where('name', 'Новый Работник')->exists())->toBeFalse();
    });

    test('нельзя создать работника с мастерской должностью', function (string $position) {
        $own    = Access::department('Свой');
        $master = Access::master($own, 'workers');

        $this->actingAs($master)->post(route('workers.store'), [
            'name'           => 'Новый Мастер',
            'position'       => $position,
            'department_id'  => $own->id,
            'department_ids' => [$own->id],
        ])->assertSessionHasErrors('position');

        expect(Worker::where('name', 'Новый Мастер')->exists())->toBeFalse();
    })->with(Worker::MASTER_POSITIONS);

    test('рядового работника своего отдела создать можно', function () {
        $own    = Access::department('Свой');
        $master = Access::master($own, 'workers');

        $this->actingAs($master)->post(route('workers.store'), [
            'name'           => 'Новый Работник',
            'position'       => 'Разнорабочий',
            'department_id'  => $own->id,
            'department_ids' => [$own->id],
        ])->assertRedirect(route('workers.index'));

        $worker = Worker::where('name', 'Новый Работник')->firstOrFail();
        expect($worker->position)->toBe('Разнорабочий');
        expect($worker->departmentIds())->toBe([$own->id]);
    });

    test('форма создания показывает только свои отделы и рядовые должности', function () {
        $own     = Access::department('Свой отдел');
        $foreign = Access::department('Чужой отдел');
        $master  = Access::master($own, 'workers');

        $response = $this->actingAs($master)->get(route('workers.create'))->assertOk();

        expect($response->viewData('departments')->pluck('id')->all())->toBe([$own->id]);
        expect($response->viewData('positions'))->toBe(['Работник', 'Разнорабочий']);
        $response->assertDontSee('Чужой отдел');
    });
});

describe('Правка чужой карточки мастером', function () {

    test('нельзя добавить работнику чужой отдел', function () {
        $own     = Access::department('Свой');
        $foreign = Access::department('Чужой');
        $master  = Access::master($own, 'workers');
        $worker  = waWorker('Пильщик', 'Работник', [$own]);

        $this->actingAs($master)->put(route('workers.update', $worker), [
            'name'           => 'Пильщик',
            'position'       => 'Работник',
            'department_id'  => $own->id,
            'department_ids' => [$own->id, $foreign->id],
        ])->assertSessionHasErrors('department_ids.1');

        expect($worker->fresh()->departmentIds())->toBe([$own->id]);
    });

    test('нельзя повысить работника до мастерской должности', function () {
        $own    = Access::department('Свой');
        $master = Access::master($own, 'workers');
        $worker = waWorker('Пильщик', 'Работник', [$own]);

        $this->actingAs($master)->put(route('workers.update', $worker), [
            'name'           => 'Пильщик',
            'position'       => 'Мастер',
            'department_id'  => $own->id,
            'department_ids' => [$own->id],
        ])->assertSessionHasErrors('position');

        expect($worker->fresh()->position)->toBe('Работник');
    });

    test('текущую мастерскую должность коллеги можно оставить', function () {
        $own       = Access::department('Свой');
        $master    = Access::master($own, 'workers');
        $colleague = waWorker('Помощник', 'Помощник мастера', [$own]);

        $this->actingAs($master)->put(route('workers.update', $colleague), [
            'name'           => 'Помощник Переименован',
            'position'       => 'Помощник мастера',
            'department_id'  => $own->id,
            'department_ids' => [$own->id],
        ])->assertRedirect(route('workers.index'));

        expect($colleague->fresh()->name)->toBe('Помощник Переименован');
        expect($colleague->fresh()->position)->toBe('Помощник мастера');
    });

    test('чужие отделы работника, включая основной, сохраняются', function () {
        $own     = Access::department('Свой');
        $second  = Access::department('Свой второй');
        $foreign = Access::department('Чужой');
        $master  = Access::master($own, 'workers');
        $master->worker->departments()->syncWithoutDetaching([$second->id]);

        // Основной отдел работника — чужой для мастера
        $worker = waWorker('Пильщик', 'Работник', [$foreign, $own]);

        $this->actingAs($master)->put(route('workers.update', $worker), [
            'name'           => 'Пильщик',
            'position'       => 'Работник',
            'department_ids' => [$second->id],
        ])->assertRedirect(route('workers.index'));

        $worker->refresh();
        expect($worker->department_id)->toBe($foreign->id);
        expect($worker->departmentIds())->toEqualCanonicalizing([$foreign->id, $second->id]);
    });

    test('форма правки показывает чужие отделы работника только для чтения', function () {
        $own     = Access::department('Свой');
        $foreign = Access::department('Чужой цех');
        $master  = Access::master($own, 'workers');
        $worker  = waWorker('Пильщик', 'Работник', [$own, $foreign]);

        $response = $this->actingAs($master)->get(route('workers.edit', $worker))->assertOk();

        expect($response->viewData('foreignDepartments')->pluck('id')->all())->toBe([$foreign->id]);
        $response->assertSee('Также состоит в отделах: Чужой цех', false);
        $response->assertSee('id="dept-' . $own->id . '"', false);
        $response->assertDontSee('id="dept-' . $foreign->id . '"', false);
    });
});

describe('Своя карточка не-админа', function () {

    test('отделы и должность из запроса игнорируются, остальное сохраняется', function () {
        $own     = Access::department('Свой');
        $foreign = Access::department('Чужой');
        $master  = Access::master($own, 'workers');
        $self    = $master->worker;

        $this->actingAs($master)->put(route('workers.update', $self), [
            'name'           => 'Мастер Переименован',
            'position'       => 'Администратор',
            'department_id'  => $foreign->id,
            'department_ids' => [$own->id, $foreign->id],
        ])->assertRedirect(route('workers.index'));

        $self->refresh();
        expect($self->name)->toBe('Мастер Переименован');
        expect($self->position)->toBe('Мастер');
        expect($self->department_id)->toBe($own->id);
        expect($self->departmentIds())->toBe([$own->id]);
    });

    test('форма правки своей карточки не содержит полей должности и отделов', function () {
        $own    = Access::department('Свой');
        $master = Access::master($own, 'workers');

        $this->actingAs($master)->get(route('workers.edit', $master->worker))
            ->assertOk()
            ->assertViewHas('assignmentLocked', true)
            ->assertDontSee('name="position"', false)
            ->assertDontSee('name="department_ids[]"', false);
    });
});

describe('Администратор', function () {

    test('назначает любые отделы и мастерскую должность', function () {
        $deptA  = Access::department('Первый');
        $deptB  = Access::department('Второй');
        $admin  = H::adminUser();
        $worker = waWorker('Пильщик', 'Работник', [$deptA]);

        $this->actingAs($admin)->put(route('workers.update', $worker), [
            'name'           => 'Пильщик',
            'position'       => 'Мастер',
            'department_id'  => $deptB->id,
            'department_ids' => [$deptA->id, $deptB->id],
        ])->assertRedirect(route('workers.index'));

        $worker->refresh();
        expect($worker->position)->toBe('Мастер');
        expect($worker->department_id)->toBe($deptB->id);
        expect($worker->departmentIds())->toEqualCanonicalizing([$deptA->id, $deptB->id]);
    });

    test('правит свою карточку, включая отделы и должность', function () {
        $deptA = Access::department('Первый');
        $admin = H::adminUser();
        $self  = Worker::create(['name' => 'Админ', 'position' => 'Администратор']);
        $admin->update(['worker_id' => $self->id]);

        $this->actingAs($admin)->put(route('workers.update', $self), [
            'name'           => 'Админ',
            'position'       => 'Мастер',
            'department_id'  => $deptA->id,
            'department_ids' => [$deptA->id],
        ])->assertRedirect(route('workers.index'));

        expect($self->fresh()->position)->toBe('Мастер');
        expect($self->fresh()->department_id)->toBe($deptA->id);
    });
});
