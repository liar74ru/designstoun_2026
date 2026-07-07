<?php

use App\Models\RawMaterialBatch;
use App\Models\Product;
use App\Models\ReceptionLog;
use App\Models\StoneReception;
use App\Models\Store;
use App\Models\User;
use App\Models\Worker;

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
    $admin   = User::factory()->create(['is_admin' => true]);

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
    $admin   = User::factory()->create(['is_admin' => true]);

    $log = makeReceptionLogForReceiverTest($master1->id);

    $this->actingAs($admin)
        ->patch(route('reception-logs.update-receiver', $log), ['receiver_id' => 999999])
        ->assertSessionHasErrors('receiver_id');

    expect($log->fresh()->receiver_id)->toBe($master1->id);
});
