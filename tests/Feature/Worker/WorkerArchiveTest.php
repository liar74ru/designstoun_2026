<?php

use App\Models\Worker;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

// ══════════════════════════════════════════════════════════════════════════════
// archive()
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkerController archive()', function () {

    test('переводит работника в архив', function () {
        $worker = H::worker('Архивов Иван', 'Работник');

        $this->actingAs(H::adminUser())
            ->patch(route('workers.archive', $worker))
            ->assertRedirect(route('workers.index'))
            ->assertSessionHas('success', 'Работник Архивов Иван переведён в архив');

        $worker->refresh();
        expect($worker->archived_at)->not->toBeNull()
            ->and($worker->isArchived())->toBeTrue()
            ->and(Worker::active()->whereKey($worker->id)->exists())->toBeFalse()
            ->and(Worker::archived()->whereKey($worker->id)->exists())->toBeTrue();
    });

    test('404 для несуществующего работника', function () {
        $this->actingAs(H::adminUser())
            ->patch(route('workers.archive', 999999))
            ->assertNotFound();
    });

    test('мастер отдела с операцией workers может архивировать', function () {
        $dept   = Access::department();
        $worker = Worker::create(['name' => 'Подчинённый', 'position' => 'Работник', 'department_id' => $dept->id]);

        $this->actingAs(Access::master($dept, 'workers'))
            ->patch(route('workers.archive', $worker))
            ->assertRedirect(route('workers.index'));

        expect($worker->fresh()->isArchived())->toBeTrue();
    });

    test('мастер отдела без операции workers — 403', function () {
        $worker = H::worker('Не трогать', 'Работник');

        $this->actingAs(Access::master(Access::department(), 'stone-receptions'))
            ->patch(route('workers.archive', $worker))
            ->assertForbidden();

        expect($worker->fresh()->archived_at)->toBeNull();
    });

    test('работник — 403', function () {
        $worker = H::worker('Не трогать', 'Работник');

        $this->actingAs(Access::userWithPosition('Работник', Access::department()))
            ->patch(route('workers.archive', $worker))
            ->assertForbidden();

        expect($worker->fresh()->archived_at)->toBeNull();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// restore()
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkerController restore()', function () {

    test('возвращает работника из архива', function () {
        $worker = H::worker('Возвратов Пётр', 'Работник');
        $worker->archive();

        $this->actingAs(H::adminUser())
            ->patch(route('workers.restore', $worker))
            ->assertRedirect(route('workers.index'))
            ->assertSessionHas('success', 'Работник Возвратов Пётр возвращён из архива');

        $worker->refresh();
        expect($worker->archived_at)->toBeNull()
            ->and(Worker::active()->whereKey($worker->id)->exists())->toBeTrue();
    });

    test('restore активного работника ничего не ломает', function () {
        $worker = H::worker('Активов', 'Работник');

        $this->actingAs(H::adminUser())
            ->patch(route('workers.restore', $worker))
            ->assertRedirect(route('workers.index'));

        expect($worker->fresh()->archived_at)->toBeNull();
    });

    test('мастер отдела без операции workers — 403', function () {
        $worker = H::worker('Архивный', 'Работник');
        $worker->archive();

        $this->actingAs(Access::master(Access::department(), 'raw-batches'))
            ->patch(route('workers.restore', $worker))
            ->assertForbidden();

        expect($worker->fresh()->isArchived())->toBeTrue();
    });

    test('мастер без отдела — 403', function () {
        $worker = H::worker('Архивный', 'Работник');
        $worker->archive();

        $this->actingAs(Access::masterWithoutDept())
            ->patch(route('workers.restore', $worker))
            ->assertForbidden();

        expect($worker->fresh()->isArchived())->toBeTrue();
    });
});
