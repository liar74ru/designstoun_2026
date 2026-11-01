<?php

use App\Models\RawMaterialBatch;
use App\Models\Product;
use App\Models\ReceptionLog;
use App\Models\ReceptionLogItem;
use App\Models\StoneReception;
use App\Models\Store;
use App\Models\User;
use App\Models\Worker;
use App\Services\StoneReceptionService;
use App\Services\WorkerDashboardService;
use Illuminate\Support\Facades\Queue;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

/**
 * Часть B: админ может точечно исправить приёмщика (receiver_id) в существующей записи
 * журнала ReceptionLog. Это переносит выработку этого лога другому мастеру.
 */

function makeReceptionLogForReceiverTest(int $receiverId): ReceptionLog
{
    $rawProduct = Product::factory()->create(['name' => 'Гранит']);
    $store      = Store::factory()->create();
    $cutter     = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
    $batch      = RawMaterialBatch::create([
        'product_id'         => $rawProduct->id,
        'initial_quantity'   => 100.0,
        'remaining_quantity' => 95.0,
        'current_store_id'   => $store->id,
        'current_worker_id'  => $cutter->id,
        'status'             => RawMaterialBatch::STATUS_IN_WORK,
    ]);

    $reception = StoneReception::create([
        'receiver_id'           => $receiverId,
        'cutter_id'             => $cutter->id,
        'store_id'              => $store->id,
        'raw_material_batch_id' => $batch->id,
        'raw_quantity_used'     => 5.0,
        'status'                => StoneReception::STATUS_ACTIVE,
    ]);

    return ReceptionLog::create([
        'stone_reception_id'    => $reception->id,
        'raw_material_batch_id' => $batch->id,
        'cutter_id'             => $cutter->id,
        'receiver_id'           => $receiverId,
        'type'                  => ReceptionLog::TYPE_CREATED,
        'raw_quantity_delta'    => 5.0,
    ]);
}

test('админ меняет приёмщика в записи журнала', function () {
    $master1 = Worker::create(['name' => 'Мастер 1', 'position' => 'Мастер']);
    $master2 = Worker::create(['name' => 'Мастер 2', 'position' => 'Мастер']);
    $admin   = H::adminUser();

    $log = makeReceptionLogForReceiverTest($master1->id);

    $this->actingAs($admin)
        ->patch(route('reception-logs.update-receiver', $log), ['receiver_id' => $master2->id])
        ->assertOk()
        ->assertJson(['success' => true, 'receiver_name' => $master2->name]);

    expect($log->fresh()->receiver_id)->toBe($master2->id);
});

test('не-администратор не может сменить приёмщика в журнале', function () {
    $master1 = Worker::create(['name' => 'Мастер 1', 'position' => 'Мастер']);
    $master2 = Worker::create(['name' => 'Мастер 2', 'position' => 'Мастер']);
    $user    = User::factory()->create(['is_admin' => false, 'worker_id' => $master1->id]);

    $log = makeReceptionLogForReceiverTest($master1->id);

    $this->actingAs($user)
        ->patch(route('reception-logs.update-receiver', $log), ['receiver_id' => $master2->id])
        ->assertForbidden();

    expect($log->fresh()->receiver_id)->toBe($master1->id);
});

test('невалидный receiver_id отклоняется', function () {
    $master1 = Worker::create(['name' => 'Мастер 1', 'position' => 'Мастер']);
    $admin   = H::adminUser();

    $log = makeReceptionLogForReceiverTest($master1->id);

    $this->actingAs($admin)
        ->patch(route('reception-logs.update-receiver', $log), ['receiver_id' => 999999])
        ->assertSessionHasErrors('receiver_id');

    expect($log->fresh()->receiver_id)->toBe($master1->id);
});

// ──────────────────────────────────────────────────────────────────────────────
// Плюс за правку — правящему, минус — тому, кто получил плюс
// ──────────────────────────────────────────────────────────────────────────────

describe('Кому идёт зарплата мастера за правку', function () {

    beforeEach(function () {
        Queue::fake();

        $this->dept   = H::departmentWithModifiers();
        $this->cutter = Worker::create(['name' => 'Пильщик', 'position' => 'Работник', 'department_id' => $this->dept->id]);
        $this->m1     = Access::master($this->dept, 'stone-receptions', 'Мастер Первый');
        $this->m2     = Access::master($this->dept, 'stone-receptions', 'Мастер Второй');
        $this->m3     = Access::master($this->dept, 'stone-receptions', 'Мастер Третий');
        $this->tile   = H::product(['moysklad_id' => 'tile']);
        $this->other  = H::product(['moysklad_id' => 'other']);

        $store = H::store();
        $batch = H::batch(H::product(['moysklad_id' => 'raw']), $store, $this->cutter, 100.0);

        $this->reception = H::reception($batch, $this->m1->worker, $this->cutter, $store, 5.0, ['department_id' => $this->dept->id]);
        $this->reception->items()->create([
            'product_id' => $this->tile->id, 'quantity' => 10, 'worker_cost_per_m2' => 520, 'master_cost_per_m2' => 100,
        ]);

        $log = ReceptionLog::create([
            'stone_reception_id' => $this->reception->id,
            'cutter_id'          => $this->cutter->id,
            'receiver_id'        => $this->m1->worker_id,
            'type'               => ReceptionLog::TYPE_CREATED,
            'raw_quantity_delta' => 5,
        ]);
        $log->items()->create(['product_id' => $this->tile->id, 'quantity_delta' => 10]);
    });

    /** Правка через настоящий сервис: $quantities — [product_id => итоговое количество]. */
    function rlrEdit($ctx, User $by, array $quantities): void
    {
        test()->actingAs($by);

        app(StoneReceptionService::class)->update($ctx->reception->fresh(), [
            'products'              => collect($quantities)->map(fn ($q, $id) => ['product_id' => $id, 'quantity' => $q])->values()->all(),
            'raw_quantity_delta'    => 0,
            'cutter_id'             => $ctx->cutter->id,
            'receiver_id'           => $by->worker_id,
            'store_id'              => $ctx->reception->store_id,
            'raw_material_batch_id' => $ctx->reception->raw_material_batch_id,
            'raw_quantity_used'     => 5,
        ], false);
    }

    function rlrPay(int $workerId, string $key = 'totalMasterPay'): float
    {
        return (float) app(WorkerDashboardService::class)
            ->getDashboardData($workerId, now()->subDay(), now()->addDay())[$key];
    }

    function rlrLastLines(): Illuminate\Support\Collection
    {
        return ReceptionLog::latest('id')->first()->items()->orderBy('id')->get();
    }

    test('М2 уменьшил чужую приёмку: минус у М1, запись за М2', function () {
        rlrEdit($this, $this->m2, [$this->tile->id => 6]);

        expect(rlrPay($this->m1->worker_id))->toEqual(600.0)
            ->and(rlrPay($this->m2->worker_id))->toEqual(0.0)
            ->and(ReceptionLog::latest('id')->first()->receiver_id)->toBe($this->m2->worker_id);

        $line = rlrLastLines()->sole();
        expect((float) $line->quantity_delta)->toEqual(-4.0)
            ->and($line->charged_worker_id)->toBe($this->m1->worker_id);
    });

    test('М2 добавил: плюс у М2', function () {
        rlrEdit($this, $this->m2, [$this->tile->id => 15]);

        expect(rlrPay($this->m1->worker_id))->toEqual(1000.0)
            ->and(rlrPay($this->m2->worker_id))->toEqual(500.0)
            ->and(rlrLastLines()->sole()->charged_worker_id)->toBeNull();
    });

    test('несколько добавивших: сначала с последнего, затем с предыдущего', function () {
        rlrEdit($this, $this->m2, [$this->tile->id => 15]);
        rlrEdit($this, $this->m3, [$this->tile->id => 8]);

        expect(rlrLastLines()->map(fn ($l) => [(float) $l->quantity_delta, $l->charged_worker_id])->all())
            ->toEqual([[-5.0, $this->m2->worker_id], [-2.0, $this->m1->worker_id]]);

        expect(rlrPay($this->m1->worker_id))->toEqual(800.0)
            ->and(rlrPay($this->m2->worker_id))->toEqual(0.0)
            ->and(rlrPay($this->m3->worker_id))->toEqual(0.0);
    });

    test('сам приёмщик уменьшает после чужой добавки: сначала с добавившего, своё без отметки', function () {
        rlrEdit($this, $this->m2, [$this->tile->id => 15]);
        rlrEdit($this, $this->m1, [$this->tile->id => 8]);

        expect(rlrLastLines()->map(fn ($l) => [(float) $l->quantity_delta, $l->charged_worker_id])->all())
            ->toEqual([[-5.0, $this->m2->worker_id], [-2.0, null]]);

        expect(rlrPay($this->m1->worker_id))->toEqual(800.0)
            ->and(rlrPay($this->m2->worker_id))->toEqual(0.0);
    });

    test('повторная добавка делает мастера последним', function () {
        rlrEdit($this, $this->m2, [$this->tile->id => 15]);
        rlrEdit($this, $this->m1, [$this->tile->id => 17]);
        rlrEdit($this, $this->m3, [$this->tile->id => 16]);

        expect(rlrLastLines()->sole()->charged_worker_id)->toBe($this->m1->worker_id);
    });

    test('в одном сохранении плюс и минус: плюс правящему, минус принявшему', function () {
        rlrEdit($this, $this->m2, [$this->tile->id => 6, $this->other->id => 2]);
        $this->reception->items()->where('product_id', $this->other->id)->update(['master_cost_per_m2' => 50]);

        expect(rlrPay($this->m1->worker_id))->toEqual(600.0)
            ->and(rlrPay($this->m2->worker_id))->toEqual(100.0);
    });

    test('записей о плюсе нет: минус на приёмщике приёмки', function () {
        ReceptionLogItem::query()->delete();

        rlrEdit($this, $this->m2, [$this->tile->id => 6]);

        expect(rlrLastLines()->sole()->charged_worker_id)->toBe($this->m1->worker_id);
    });

    test('старый минус у правившего остаётся у него, следующий идёт с остатка', function () {
        $old = ReceptionLog::create([
            'stone_reception_id' => $this->reception->id,
            'cutter_id'          => $this->cutter->id,
            'receiver_id'        => $this->m2->worker_id,
            'type'               => ReceptionLog::TYPE_UPDATED,
            'raw_quantity_delta' => 0,
        ]);
        $old->items()->create(['product_id' => $this->tile->id, 'quantity_delta' => -4]);
        $this->reception->items()->update(['quantity' => 6]);

        expect(rlrPay($this->m2->worker_id))->toEqual(-400.0);

        rlrEdit($this, $this->m3, [$this->tile->id => 4]);

        expect(rlrLastLines()->sole()->charged_worker_id)->toBe($this->m1->worker_id)
            ->and(rlrPay($this->m1->worker_id))->toEqual(800.0)
            ->and(rlrPay($this->m2->worker_id))->toEqual(-400.0);
    });

    test('пильщик сам правит: как пильщик получает все строки, минус мастера у принявшего', function () {
        $this->reception->update(['cutter_id' => $this->m2->worker_id]);
        ReceptionLog::query()->update(['cutter_id' => $this->m2->worker_id]);
        $this->cutter = $this->m2->worker;

        rlrEdit($this, $this->m2, [$this->tile->id => 6]);

        expect(rlrPay($this->m2->worker_id, 'totalWorkerPay'))->toEqual(3120.0)
            ->and(rlrPay($this->m2->worker_id))->toEqual(0.0)
            ->and(rlrPay($this->m1->worker_id))->toEqual(600.0);
    });

    test('админ меняет приёмщика записи: списание сбрасывается, минус у выбранного', function () {
        rlrEdit($this, $this->m2, [$this->tile->id => 6]);
        $log = ReceptionLog::latest('id')->first();

        $this->actingAs(H::adminUser())
            ->patch(route('reception-logs.update-receiver', $log), ['receiver_id' => $this->m3->worker_id]);

        expect(rlrLastLines()->sole()->charged_worker_id)->toBeNull()
            ->and(rlrPay($this->m3->worker_id))->toEqual(-400.0)
            ->and(rlrPay($this->m1->worker_id))->toEqual(1000.0);
    });

    test('журнал показывает, с кого списан минус', function () {
        rlrEdit($this, $this->m2, [$this->tile->id => 6]);

        $this->actingAs(H::adminUser())->get(route('stone-receptions.show', $this->reception))
            ->assertOk()
            ->assertSee('зарплата: Мастер Первый');
    });

    describe('Предупреждение на форме', function () {

        test('другой мастер видит, кому пойдут плюс и минус', function () {
            $this->actingAs($this->m2)->get(route('stone-receptions.edit', $this->reception))
                ->assertOk()
                ->assertSee('id="receiverPayWarning"', false)
                ->assertSee('Приёмщик этой приёмки — Мастер Первый')
                ->assertSee('будет засчитана вам');
        });

        test('изначальный приёмщик не видит', function () {
            $this->actingAs($this->m1)->get(route('stone-receptions.edit', $this->reception))
                ->assertOk()
                ->assertDontSee('id="receiverPayWarning"', false);
        });

        test('админ: видно, если выбран не приёмщик, и скрыто, если выбран он', function () {
            $admin = User::factory()->create(['is_admin' => true, 'worker_id' => $this->m3->worker_id]);
            $shown = 'class="alert alert-warning py-2 small "';

            $this->actingAs($admin)->get(route('stone-receptions.edit', $this->reception))
                ->assertSee($shown, false)
                ->assertSee('выбранному в форме приёмщику');

            $this->actingAs($admin)
                ->withSession(['_old_input' => ['receiver_id' => $this->m1->worker_id]])
                ->get(route('stone-receptions.edit', $this->reception))
                ->assertSee('id="receiverPayWarning"', false)
                ->assertDontSee($shown, false);
        });
    });
});
