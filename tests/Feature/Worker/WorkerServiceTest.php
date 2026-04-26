<?php

use App\Models\Department;
use App\Models\User;
use App\Models\Worker;
use App\Services\WorkerService;

// ══════════════════════════════════════════════════════════════════════════════
// WorkerService — buildIndexQuery()
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkerService::buildIndexQuery()', function () {

    test('фильтрует по position', function () {
        $cutter = Worker::create(['name' => 'Пильщик', 'positions' => ['Пильщик']]);
        $master = Worker::create(['name' => 'Мастер', 'positions' => ['Мастер']]);
        $admin = actingAsAdmin();

        $service = new WorkerService();
        $result = $service->buildIndexQuery(['position' => 'Пильщик'], $admin);

        expect($result->count())->toBe(1);
        expect($result->first()->id)->toBe($cutter->id);
    });

    test('фильтрует по department_id', function () {
        $dept = Department::create(['name' => 'Тест отдел', 'code' => 'TEST']);
        $worker1 = Worker::create(['name' => 'Работник 1', 'positions' => ['Пильщик'], 'department_id' => $dept->id]);
        $worker2 = Worker::create(['name' => 'Работник 2', 'positions' => ['Пильщик'], 'department_id' => null]);
        $admin = actingAsAdmin();

        $service = new WorkerService();
        $result = $service->buildIndexQuery(['department_id' => $dept->id], $admin);

        expect($result->count())->toBe(1);
        expect($result->first()->id)->toBe($worker1->id);
    });

test('мастер видит только свой department', function () {
        $dept1 = Department::create(['name' => 'Отдел 1', 'code' => 'D1']);
        $dept2 = Department::create(['name' => 'Отдел 2', 'code' => 'D2']);
        $masterWorker = Worker::create(['name' => 'Мастер', 'positions' => ['Мастер'], 'department_id' => $dept1->id]);
        $cutterInDept = Worker::create(['name' => 'Пильщик 1', 'positions' => ['Пильщик'], 'department_id' => $dept1->id]);
        $cutterOutDept = Worker::create(['name' => 'Пильщик 2', 'positions' => ['Пильщик'], 'department_id' => $dept2->id]);

        $masterUser = User::factory()->create([
            'is_admin' => false,
            'worker_id' => $masterWorker->id,
        ]);

        $service = new WorkerService();
        $result = $service->buildIndexQuery([], $masterUser);

        // Мастер видит себя + своих пильщиков = 2
        expect($result->count())->toBe(2);
        expect($result->pluck('id')->toArray())->toContain($masterWorker->id);
        expect($result->pluck('id')->toArray())->toContain($cutterInDept->id);
        expect($result->pluck('id')->toArray())->not->toContain($cutterOutDept->id);
    });

    test('фильтрует по has_account', function () {
        $workerWithUser = Worker::create(['name' => 'С юзером', 'positions' => ['Пильщик'], 'phone' => '79000000001']);
        $workerWithoutUser = Worker::create(['name' => 'Без юзера', 'positions' => ['Пильщик']]);
        User::factory()->create(['worker_id' => $workerWithUser->id, 'phone' => '79000000001']);
        $admin = actingAsAdmin();

        $service = new WorkerService();
        $result = $service->buildIndexQuery(['has_account' => '1'], $admin);

        expect($result->count())->toBe(1);
        expect($result->first()->id)->toBe($workerWithUser->id);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// WorkerService — syncPhoneToUser()
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkerService::syncPhoneToUser()', function () {

    test('обновляет телефон у связанного пользователя', function () {
        $worker = Worker::create(['name' => 'Тестов', 'positions' => ['Пильщик'], 'phone' => '79000000001']);
        $user = User::factory()->create(['worker_id' => $worker->id, 'phone' => '79000000001']);

        $service = new WorkerService();
        $service->syncPhoneToUser($worker, '79099999999');

        $user->refresh();
        expect($user->phone)->toBe('79099999999');
    });

    test('не обновляет если телефон не изменился', function () {
        $worker = Worker::create(['name' => 'Тестов', 'positions' => ['Пильщик'], 'phone' => '79000000001']);
        $user = User::factory()->create(['worker_id' => $worker->id, 'phone' => '79000000001']);

        $service = new WorkerService();
        $service->syncPhoneToUser($worker, '79000000001');

        expect($user->fresh()->phone)->toBe('79000000001');
    });

    test('не обновляет если у работника нет пользователя', function () {
        $worker = Worker::create(['name' => 'Тестов', 'positions' => ['Пильщик'], 'phone' => null]);

        $service = new WorkerService();
        $service->syncPhoneToUser($worker, '79000000001');

        expect(User::count())->toBe(0);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// WorkerService — createUser()
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkerService::createUser()', function () {

    test('создаёт пользователя для работника с телефоном', function () {
        $worker = Worker::create(['name' => 'Тестов', 'positions' => ['Пильщик'], 'phone' => '79000000001']);

        $service = new WorkerService();
        $result = $service->createUser($worker, 'secret123');

        expect($result['success'])->toBeTrue();
        expect(User::where('worker_id', $worker->id)->exists())->toBeTrue();
    });

    test('возвращает ошибку если у работника нет телефона', function () {
        $worker = Worker::create(['name' => 'Тестов', 'positions' => ['Пильщик'], 'phone' => null]);

        $service = new WorkerService();
        $result = $service->createUser($worker, 'secret123');

        expect($result['success'])->toBeFalse();
        expect($result['message'])->toContain('телефон');
    });

    test('возвращает ошибку если телефон уже используется', function () {
        $existingWorker = Worker::create(['name' => 'Существующий', 'positions' => ['Пильщик'], 'phone' => '79000000001']);
        $existingUser = User::factory()->create(['phone' => '79000000001']);

        $newWorker = Worker::create(['name' => 'Новый', 'positions' => ['Пильщик'], 'phone' => '79000000001']);

        $service = new WorkerService();
        $result = $service->createUser($newWorker, 'secret123');

        expect($result['success'])->toBeFalse();
        expect($result['message'])->toContain('используется');
    });
});

// ══════════���═══════════════════════════════════════════════════════════════════
// WorkerService — updateUser()
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkerService::updateUser()', function () {

    test('обновляет пароль пользователя', function () {
        $worker = Worker::create(['name' => 'Тестов', 'positions' => ['Пильщик'], 'phone' => '79000000001']);
        $user = User::factory()->create(['worker_id' => $worker->id, 'phone' => '79000000001']);

        $service = new WorkerService();
        $service->updateUser($worker, 'newpassword', false, false);

        expect(\Illuminate\Support\Facades\Hash::check('newpassword', $user->fresh()->password))->toBeTrue();
    });

    test('админ может установить is_admin', function () {
        $worker = Worker::create(['name' => 'Тестов', 'positions' => ['Пильщик'], 'phone' => '79000000001']);
        $user = User::factory()->create(['worker_id' => $worker->id, 'phone' => '79000000001', 'is_admin' => false]);

        $service = new WorkerService();
        $service->updateUser($worker, 'newpassword', true, true);

        expect($user->fresh()->is_admin)->toBeTrue();
    });

    test('неадмин не может установить is_admin', function () {
        $worker = Worker::create(['name' => 'Тестов', 'positions' => ['Пильщик'], 'phone' => '79000000001']);
        $user = User::factory()->create(['worker_id' => $worker->id, 'phone' => '79000000001', 'is_admin' => false]);

        $service = new WorkerService();
        $service->updateUser($worker, 'newpassword', true, false);

        expect($user->fresh()->is_admin)->toBeFalse();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Helpers
// ══════════════════════════════════════════════════════════════════════════════

function actingAsAdmin(): User
{
    return User::factory()->create(['is_admin' => true]);
}