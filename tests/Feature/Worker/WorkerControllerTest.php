<?php

use App\Models\Counterparty;
use App\Models\Department;
use App\Models\PiggyBankEntry;
use App\Models\RawMaterialMovement;
use App\Models\ReceptionLog;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Models\Worker;
use App\Models\Workshop;
use App\Models\WorkshopLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

function makeWorker(array $attrs = []): Worker
{
    return Worker::create(array_merge([
        'name'      => 'Тестов Тест',
        'position' => 'Работник',
        'phone'     => null,
        'email'     => null,
    ], $attrs));
}

function makeWorkerTestDept(): Department
{
    return Department::create(['name' => 'Тест отдел', 'is_active' => true]);
}

/** Запись, из-за которой работника нельзя удалить. */
function makeWorkerHistory(string $kind, Worker $worker): Model
{
    $other = H::worker('Другой');
    $store = H::store();
    $batch = H::batch(H::product(), $store, $other);

    $reception = fn () => H::reception($batch, $other, $other, $store);
    $workshop  = fn (array $attrs = []) => Workshop::create($attrs + [
        'packer_id'   => $other->id,
        'receiver_id' => $other->id,
        'store_id'    => $store->id,
    ]);
    $receptionLog = fn (array $attrs) => ReceptionLog::create($attrs + [
        'stone_reception_id'    => $reception()->id,
        'raw_material_batch_id' => $batch->id,
        'cutter_id'             => $other->id,
        'receiver_id'           => $other->id,
        'type'                  => 'created',
        'raw_quantity_delta'    => 1,
        'raw_quantity_snapshot' => 1,
    ]);
    $workshopLog = fn (array $attrs) => WorkshopLog::create($attrs + [
        'workshop_id'               => $workshop()->id,
        'packer_id'                 => $other->id,
        'receiver_id'               => $other->id,
        'type'                      => 'created',
        'package_quantity_delta'    => 1,
        'package_quantity_snapshot' => 1,
    ]);
    $movement = fn (string $column) => RawMaterialMovement::create([
        'batch_id'      => $batch->id,
        'movement_type' => 'create',
        'quantity'      => 1,
        $column         => $worker->id,
    ]);

    return match ($kind) {
        'выдача зарплаты' => $worker->salaryPayouts()->create([
            'period_from' => now()->subWeek(),
            'period_to'   => now(),
            'accrued'     => 1000,
            'paid_total'  => 1000,
            'accounted'   => 1000,
            'to_bank'     => 0,
            'paid_at'     => now(),
        ]),
        'проводка копилки' => $worker->piggyBankEntries()->create([
            'amount' => -500,
            'type'   => PiggyBankEntry::TYPE_ADJUSTMENT,
        ]),
        'пильщик в приёмке'          => H::reception($batch, $other, $worker, $store),
        'приёмщик в приёмке'         => H::reception($batch, $worker, $other, $store),
        'пильщик в журнале приёмок'  => $receptionLog(['cutter_id' => $worker->id]),
        'приёмщик в журнале приёмок' => $receptionLog(['receiver_id' => $worker->id]),
        'минус списан с работника'   => $receptionLog([])->items()->create([
            'product_id'        => H::product()->id,
            'quantity_delta'    => -1,
            'charged_worker_id' => $worker->id,
        ]),
        'работник цеха'              => $workshop(['packer_id' => $worker->id]),
        'приёмщик в цехе'            => $workshop(['receiver_id' => $worker->id]),
        'работник в журнале цеха'    => $workshopLog(['packer_id' => $worker->id]),
        'приёмщик в журнале цеха'    => $workshopLog(['receiver_id' => $worker->id]),
        'партия у работника'         => H::batch(H::product(), $store, $worker),
        'перемещение от работника'   => $movement('from_worker_id'),
        'перемещение к работнику'    => $movement('to_worker_id'),
        'перемещение выполнил'       => $movement('moved_by'),
        'приёмщик поступления'       => SupplierOrder::create([
            'number'          => 'HIST-01',
            'store_id'        => $store->id,
            'counterparty_id' => Counterparty::create(['name' => 'Поставщик', 'moysklad_id' => 'cp-1'])->id,
            'status'          => SupplierOrder::STATUS_NEW,
            'receiver_id'     => $worker->id,
        ]),
    };
}

// ══════════════════════════════════════════════════════════════════════════════
// index()
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkerController index()', function () {

    test('доступна авторизованному', function () {
        $this->actingAs(H::adminUser())
            ->get(route('workers.index'))
            ->assertStatus(200);
    });

    test('показывает список работников', function () {
        makeWorker(['name' => 'Уникальный Работников']);

        $this->actingAs(H::adminUser())
            ->get(route('workers.index'))
            ->assertStatus(200)
            ->assertSee('Уникальный Работников');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// create()
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkerController create()', function () {

    test('форма создания доступна', function () {
        $this->actingAs(H::adminUser())
            ->get(route('workers.create'))
            ->assertStatus(200);
    });

});

// ══════════════════════════════════════════════════════════════════════════════
// store()
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkerController store()', function () {

    test('успешно создаёт работника', function () {
        $this->actingAs(H::adminUser())
            ->post(route('workers.store'), [
                'name'      => 'Новиков Иван',
                'position' => 'Работник',
            ])
            ->assertRedirect(route('workers.index'))
            ->assertSessionHas('success');

        expect(Worker::where('name', 'Новиков Иван')->exists())->toBeTrue();
    });

    test('создаёт работника с отделом', function () {
        $dept = makeWorkerTestDept();

        $this->actingAs(H::adminUser())
            ->post(route('workers.store'), [
                'name'          => 'Отделов Отдел',
                'position' => 'Мастер',
                'department_id' => $dept->id,
            ])
            ->assertRedirect(route('workers.index'));

        expect(Worker::where('name', 'Отделов Отдел')->value('department_id'))->toBe($dept->id);
    });

    test('отклоняет без имени', function () {
        $this->actingAs(H::adminUser())
            ->post(route('workers.store'), ['position' => 'Работник'])
            ->assertSessionHasErrors('name');
    });

    test('отклоняет недопустимую должность', function () {
        $this->actingAs(H::adminUser())
            ->post(route('workers.store'), [
                'name'     => 'Тестов Тест',
                'position' => 'НесуществующаяДолжность',
            ])
            ->assertSessionHasErrors('position');
    });

    test('отклоняет дублирующийся email', function () {
        makeWorker(['email' => 'dup@test.com']);

        $this->actingAs(H::adminUser())
            ->post(route('workers.store'), [
                'name'      => 'Другой Работник',
                'position' => 'Работник',
                'email'     => 'dup@test.com',
            ])
            ->assertSessionHasErrors('email');
    });

});

// ══════════════════════════════════════════════════════════════════════════════
// edit() / update()
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkerController edit()', function () {

    test('форма редактирования доступна', function () {
        $worker = makeWorker();

        $this->actingAs(H::adminUser())
            ->get(route('workers.edit', $worker))
            ->assertStatus(200);
    });

});

describe('WorkerController update()', function () {

    test('успешно обновляет работника', function () {
        $worker = makeWorker(['name' => 'Старое Имя']);

        $this->actingAs(H::adminUser())
            ->put(route('workers.update', $worker), [
                'name'      => 'Новое Имя',
                'position' => 'Мастер',
            ])
            ->assertRedirect(route('workers.index'))
            ->assertSessionHas('success');

        expect($worker->fresh()->name)->toBe('Новое Имя');
    });

    test('обновление телефона синхронизируется с user', function () {
        $worker = makeWorker(['name' => 'Синхронов Синх', 'phone' => '111']);
        $user   = User::create([
            'name'      => $worker->name,
            'phone'     => '111',
            'password'  => bcrypt('secret'),
            'worker_id' => $worker->id,
        ]);

        $this->actingAs(H::adminUser())
            ->put(route('workers.update', $worker), [
                'name'      => 'Синхронов Синх',
                'position' => 'Работник',
                'phone'     => '999',
            ])
            ->assertRedirect(route('workers.index'));

        expect($user->fresh()->phone)->toBe('999');
    });

    test('отклоняет без имени', function () {
        $worker = makeWorker();

        $this->actingAs(H::adminUser())
            ->put(route('workers.update', $worker), ['position' => 'Работник'])
            ->assertSessionHasErrors('name');
    });

    test('email unique игнорирует собственный email при update', function () {
        $worker = makeWorker(['email' => 'own@test.com']);

        $this->actingAs(H::adminUser())
            ->put(route('workers.update', $worker), [
                'name'     => $worker->name,
                'position' => $worker->position,
                'email'    => 'own@test.com',
            ])
            ->assertRedirect(route('workers.index'));
    });

});

// ══════════════════════════════════════════════════════════════════════════════
// destroy()
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkerController destroy()', function () {

    test('успешно удаляет работника', function () {
        $worker = makeWorker();

        $this->actingAs(H::adminUser())
            ->delete(route('workers.destroy', $worker))
            ->assertRedirect(route('workers.index'))
            ->assertSessionHas('success');

        expect(Worker::find($worker->id))->toBeNull();
    });

    test('мастер своего отдела удаляет работника без истории', function () {
        $dept   = Access::department();
        $worker = makeWorker(['department_id' => $dept->id]);

        $this->actingAs(Access::master($dept, 'workers'))
            ->delete(route('workers.destroy', $worker))
            ->assertRedirect(route('workers.index'))
            ->assertSessionHas('success');

        expect(Worker::find($worker->id))->toBeNull();
    });

    test('работника с историей удалить нельзя, запись остаётся', function (string $kind) {
        $worker = makeWorker();
        $record = makeWorkerHistory($kind, $worker);

        $this->actingAs(H::adminUser())
            ->delete(route('workers.destroy', $worker))
            ->assertRedirect(route('workers.index'))
            ->assertSessionHas('error');

        expect(Worker::find($worker->id))->not->toBeNull()
            ->and($record->fresh())->not->toBeNull();
    })->with([
        'выдача зарплаты', 'проводка копилки',
        'пильщик в приёмке', 'приёмщик в приёмке',
        'пильщик в журнале приёмок', 'приёмщик в журнале приёмок',
        'минус списан с работника',
        'работник цеха', 'приёмщик в цехе',
        'работник в журнале цеха', 'приёмщик в журнале цеха',
        'партия у работника',
        'перемещение от работника', 'перемещение к работнику', 'перемещение выполнил',
        'приёмщик поступления',
    ]);

    test('мимо сервиса БД не даёт удалить работника с выдачей и копилкой', function (string $kind) {
        $worker = makeWorker();
        $record = makeWorkerHistory($kind, $worker);

        expect(fn () => $worker->delete())->toThrow(QueryException::class)
            ->and($record->fresh())->not->toBeNull();
    })->with(['выдача зарплаты', 'проводка копилки']);

});

// ══════════════════════════════════════════════════════════════════════════════
// createUser() / storeUser()
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkerController createUser()', function () {

    test('форма создания учётки доступна если нет user', function () {
        $worker = makeWorker();

        $this->actingAs(H::adminUser())
            ->get(route('workers.create-user', $worker))
            ->assertStatus(200);
    });

    test('редиректит если у работника уже есть учётка', function () {
        $worker = makeWorker(['phone' => '123456']);
        User::create([
            'name'      => $worker->name,
            'phone'     => $worker->phone,
            'password'  => bcrypt('secret'),
            'worker_id' => $worker->id,
        ]);

        $this->actingAs(H::adminUser())
            ->get(route('workers.create-user', $worker))
            ->assertRedirect(route('workers.index'))
            ->assertSessionHas('error');
    });
});

describe('WorkerController storeUser()', function () {

    test('успешно создаёт учётку для работника', function () {
        $worker = makeWorker(['phone' => '79001112233']);

        $this->actingAs(H::adminUser())
            ->post(route('workers.store-user', $worker), [
                'password' => 'secret123',
            ])
            ->assertRedirect(route('workers.index'))
            ->assertSessionHas('success');

        expect(User::where('worker_id', $worker->id)->exists())->toBeTrue();
    });

    test('отклоняет пароль короче 8 символов', function () {
        $worker = makeWorker(['phone' => '79001112235']);

        $this->actingAs(H::adminUser())
            ->post(route('workers.store-user', $worker), ['password' => '1234567'])
            ->assertSessionHasErrors('password');

        expect(User::where('worker_id', $worker->id)->exists())->toBeFalse();
    });

    test('отклоняет если у работника уже есть учётка', function () {
        $worker = makeWorker(['phone' => '79001112234']);
        User::create([
            'name'      => $worker->name,
            'phone'     => $worker->phone,
            'password'  => bcrypt('secret'),
            'worker_id' => $worker->id,
        ]);

        $this->actingAs(H::adminUser())
            ->post(route('workers.store-user', $worker), ['password' => 'secret123'])
            ->assertRedirect(route('workers.index'))
            ->assertSessionHas('error');
    });

    test('отклоняет если у работника нет телефона', function () {
        $worker = makeWorker(['phone' => null]);

        $this->actingAs(H::adminUser())
            ->post(route('workers.store-user', $worker), ['password' => 'secret123'])
            ->assertSessionHas('error');
    });

    test('отклоняет если телефон уже занят другим пользователем', function () {
        $worker = makeWorker(['phone' => '79009998877']);
        User::create([
            'name'     => 'Другой',
            'phone'    => '79009998877',
            'password' => bcrypt('x'),
        ]);

        $this->actingAs(H::adminUser())
            ->post(route('workers.store-user', $worker), ['password' => 'secret123'])
            ->assertSessionHas('error');
    });

});

// ══════════════════════════════════════════════════════════════════════════════
// editUser() / updateUser()
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkerController editUser()', function () {

    test('форма редактирования учётки доступна если user есть', function () {
        $worker = makeWorker(['phone' => '79111111111']);
        User::create([
            'name'      => $worker->name,
            'phone'     => $worker->phone,
            'password'  => bcrypt('secret'),
            'worker_id' => $worker->id,
        ]);

        $this->actingAs(H::adminUser())
            ->get(route('workers.edit-user', $worker))
            ->assertStatus(200);
    });

    test('редиректит если user нет', function () {
        $worker = makeWorker();

        $this->actingAs(H::adminUser())
            ->get(route('workers.edit-user', $worker))
            ->assertRedirect(route('workers.index'))
            ->assertSessionHas('error');
    });
});

describe('WorkerController updateUser()', function () {

    test('успешно обновляет пароль', function () {
        $worker = makeWorker(['phone' => '79222222222']);
        $user   = User::create([
            'name'      => $worker->name,
            'phone'     => $worker->phone,
            'password'  => bcrypt('oldpass'),
            'worker_id' => $worker->id,
        ]);

        $this->actingAs(H::adminUser())
            ->put(route('workers.update-user', $worker), [
                'password'              => 'newpass1',
                'password_confirmation' => 'newpass1',
            ])
            ->assertRedirect(route('workers.index'))
            ->assertSessionHas('success');
    });

    test('отклоняет если пароли не совпадают', function () {
        $worker = makeWorker(['phone' => '79333333333']);
        User::create([
            'name'      => $worker->name,
            'phone'     => $worker->phone,
            'password'  => bcrypt('old'),
            'worker_id' => $worker->id,
        ]);

        $this->actingAs(H::adminUser())
            ->put(route('workers.update-user', $worker), [
                'password'              => 'newpass1',
                'password_confirmation' => 'wrongpass',
            ])
            ->assertSessionHasErrors('password');
    });

    test('отклоняет если пароль слишком короткий', function () {
        $worker = makeWorker(['phone' => '79444444444']);
        User::create([
            'name'      => $worker->name,
            'phone'     => $worker->phone,
            'password'  => bcrypt('old'),
            'worker_id' => $worker->id,
        ]);

        $this->actingAs(H::adminUser())
            ->put(route('workers.update-user', $worker), [
                'password'              => '1234567',
                'password_confirmation' => '1234567',
            ])
            ->assertSessionHasErrors('password');
    });

    test('работник не может выдать себе права администратора', function () {
        $worker = makeWorker(['phone' => '79555555555']);
        $user   = User::create([
            'name'      => $worker->name,
            'phone'     => $worker->phone,
            'password'  => bcrypt('old'),
            'worker_id' => $worker->id,
        ]);

        $this->actingAs($user)
            ->put(route('workers.update-user', $worker), [
                'password'              => 'newpass1',
                'password_confirmation' => 'newpass1',
                'is_admin'              => '1',
            ]);

        expect($user->fresh()->is_admin)->toBeFalse();
    });

    test('администратор выдаёт и снимает права администратора', function () {
        $worker = makeWorker(['phone' => '79666666666']);
        $user   = User::create([
            'name'      => $worker->name,
            'phone'     => $worker->phone,
            'password'  => bcrypt('old'),
            'worker_id' => $worker->id,
        ]);
        $admin = H::adminUser();
        $form  = ['password' => 'newpass1', 'password_confirmation' => 'newpass1'];

        $this->actingAs($admin)->put(route('workers.update-user', $worker), $form + ['is_admin' => '1']);
        expect($user->fresh()->is_admin)->toBeTrue();

        $this->actingAs($admin)->put(route('workers.update-user', $worker), $form);
        expect($user->fresh()->is_admin)->toBeFalse();
    });

    test('is_admin нельзя задать массовым присваиванием', function () {
        expect((new User)->fill(['is_admin' => true])->is_admin)->toBeNull();
    });

    test('редиректит если user нет', function () {
        $worker = makeWorker();

        $this->actingAs(H::adminUser())
            ->put(route('workers.update-user', $worker), [
                'password'              => 'newpass1',
                'password_confirmation' => 'newpass1',
            ])
            ->assertRedirect(route('workers.index'))
            ->assertSessionHas('error');
    });

});
