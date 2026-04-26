<?php

use App\Models\Department;
use App\Models\User;
use App\Models\Worker;
use Tests\Helpers\ReceptionTestHelper as H;

function makeWorker(array $attrs = []): Worker
{
    return Worker::create(array_merge([
        'name'      => 'Тестов Тест',
        'positions' => ['Пильщик'],
        'phone'     => null,
        'email'     => null,
    ], $attrs));
}

function makeWorkerTestDept(): Department
{
    return Department::create(['name' => 'Тест отдел', 'is_active' => true]);
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

    test('недоступна без авторизации', function () {
        $this->get(route('workers.index'))
            ->assertRedirect('/login');
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

    test('недоступна без авторизации', function () {
        $this->get(route('workers.create'))
            ->assertRedirect('/login');
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
                'positions' => ['Пильщик'],
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
                'positions'     => ['Мастер'],
                'department_id' => $dept->id,
            ])
            ->assertRedirect(route('workers.index'));

        expect(Worker::where('name', 'Отделов Отдел')->value('department_id'))->toBe($dept->id);
    });

    test('отклоняет без имени', function () {
        $this->actingAs(H::adminUser())
            ->post(route('workers.store'), ['positions' => ['Пильщик']])
            ->assertSessionHasErrors('name');
    });

    test('отклоняет недопустимую должность', function () {
        $this->actingAs(H::adminUser())
            ->post(route('workers.store'), [
                'name'      => 'Тестов Тест',
                'positions' => ['НесуществующаяДолжность'],
            ])
            ->assertSessionHasErrors('positions.0');
    });

    test('отклоняет дублирующийся email', function () {
        makeWorker(['email' => 'dup@test.com']);

        $this->actingAs(H::adminUser())
            ->post(route('workers.store'), [
                'name'      => 'Другой Работник',
                'positions' => ['Пильщик'],
                'email'     => 'dup@test.com',
            ])
            ->assertSessionHasErrors('email');
    });

    test('недоступен без авторизации', function () {
        $this->post(route('workers.store'), [])
            ->assertRedirect('/login');
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

    test('недоступна без авторизации', function () {
        $worker = makeWorker();

        $this->get(route('workers.edit', $worker))
            ->assertRedirect('/login');
    });
});

describe('WorkerController update()', function () {

    test('успешно обновляет работника', function () {
        $worker = makeWorker(['name' => 'Старое Имя']);

        $this->actingAs(H::adminUser())
            ->put(route('workers.update', $worker), [
                'name'      => 'Новое Имя',
                'positions' => ['Мастер'],
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
                'positions' => ['Пильщик'],
                'phone'     => '999',
            ])
            ->assertRedirect(route('workers.index'));

        expect($user->fresh()->phone)->toBe('999');
    });

    test('отклоняет без имени', function () {
        $worker = makeWorker();

        $this->actingAs(H::adminUser())
            ->put(route('workers.update', $worker), ['positions' => ['Пильщик']])
            ->assertSessionHasErrors('name');
    });

    test('email unique игнорирует собственный email при update', function () {
        $worker = makeWorker(['email' => 'own@test.com']);

        $this->actingAs(H::adminUser())
            ->put(route('workers.update', $worker), [
                'name'      => $worker->name,
                'positions' => $worker->positions,
                'email'     => 'own@test.com',
            ])
            ->assertRedirect(route('workers.index'));
    });

    test('недоступен без авторизации', function () {
        $worker = makeWorker();

        $this->put(route('workers.update', $worker), [])
            ->assertRedirect('/login');
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

    test('недоступен без авторизации', function () {
        $worker = makeWorker();

        $this->delete(route('workers.destroy', $worker))
            ->assertRedirect('/login');
    });
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

    test('недоступен без авторизации', function () {
        $worker = makeWorker();

        $this->post(route('workers.store-user', $worker), [])
            ->assertRedirect('/login');
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
                'password'              => '123',
                'password_confirmation' => '123',
            ])
            ->assertSessionHasErrors('password');
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

    test('недоступен без авторизации', function () {
        $worker = makeWorker();

        $this->put(route('workers.update-user', $worker), [])
            ->assertRedirect('/login');
    });
});
