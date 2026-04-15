<?php

use App\Models\User;
use App\Models\Worker;
use App\Models\Product;
use App\Models\Store;
use App\Models\RawMaterialBatch;
use App\Models\StoneReception;

// ─── Вспомогательные фабрики ──────────────────────────────────────────────────

function makeBatch(string $status = 'in_work', float $remaining = 5.0): RawMaterialBatch
{
    $product = Product::factory()->create();
    $store   = Store::factory()->create();
    $worker  = Worker::create(['name' => 'Тест Тестов', 'position' => 'Пильщик']);

    return RawMaterialBatch::create([
        'product_id'         => $product->id,
        'initial_quantity'   => 10.0,
        'remaining_quantity' => $remaining,
        'current_store_id'   => $store->id,
        'current_worker_id'  => $worker->id,
        'status'             => $status,
    ]);
}

function makeAdminUser(): User
{
    $worker = Worker::create(['name' => 'Админ', 'position' => 'Директор']);
    return User::factory()->create(['worker_id' => $worker->id, 'is_admin' => true]);
}

// ─── markAsUsed ───────────────────────────────────────────────────────────────

test('markAsUsed переводит партию из in_work в used', function () {
    $user  = makeAdminUser();
    $batch = makeBatch('in_work', 0.0);

    $response = $this->actingAs($user)->post(route('raw-batches.mark-used', $batch));

    $response->assertRedirect();
    expect($batch->fresh()->status)->toBe(RawMaterialBatch::STATUS_USED);
});

test('markAsUsed переводит партию из new в used', function () {
    $user  = makeAdminUser();
    $batch = makeBatch('new', 0.0);

    $this->actingAs($user)->post(route('raw-batches.mark-used', $batch));

    expect($batch->fresh()->status)->toBe(RawMaterialBatch::STATUS_USED);
});

test('markAsUsed запрещён для archived партии', function () {
    $user  = makeAdminUser();
    $batch = makeBatch('archived', 0.0);

    $response = $this->actingAs($user)->post(route('raw-batches.mark-used', $batch));

    $response->assertRedirect();
    expect($batch->fresh()->status)->toBe('archived');
    $response->assertSessionHas('error');
});

test('markAsUsed запрещён для returned партии', function () {
    $user  = makeAdminUser();
    $batch = makeBatch('returned', 0.0);

    $this->actingAs($user)->post(route('raw-batches.mark-used', $batch));

    expect($batch->fresh()->status)->toBe('returned');
});

// ─── markAsInWork ─────────────────────────────────────────────────────────────

test('markAsInWork переводит партию из used в in_work', function () {
    $user  = makeAdminUser();
    $batch = makeBatch('used', 0.0);

    $this->actingAs($user)->post(route('raw-batches.mark-in-work', $batch));

    expect($batch->fresh()->status)->toBe(RawMaterialBatch::STATUS_IN_WORK);
});

test('markAsInWork запрещён для in_work партии', function () {
    $user  = makeAdminUser();
    $batch = makeBatch('in_work', 5.0);

    $response = $this->actingAs($user)->post(route('raw-batches.mark-in-work', $batch));

    $response->assertSessionHas('error');
    expect($batch->fresh()->status)->toBe('in_work');
});

test('markAsInWork запрещён для archived партии', function () {
    $user  = makeAdminUser();
    $batch = makeBatch('archived', 0.0);

    $this->actingAs($user)->post(route('raw-batches.mark-in-work', $batch));

    expect($batch->fresh()->status)->toBe('archived');
});

// ─── adjust() больше не меняет статус ────────────────────────────────────────

test('adjust не меняет статус партии когда remaining становится 0', function () {
    $user  = makeAdminUser();
    $batch = makeBatch('in_work', 3.0);

    $this->actingAs($user)->post(route('raw-batches.adjust', $batch), [
        'delta' => -3.0,
        'notes' => 'тест',
    ]);

    $fresh = $batch->fresh();
    expect($fresh->remaining_quantity)->toBe('0.000');
    expect($fresh->status)->toBe('in_work'); // статус не изменился
});

test('adjust не меняет статус партии когда remaining становится отрицательным — запрещено', function () {
    $user  = makeAdminUser();
    $batch = makeBatch('in_work', 3.0);

    $response = $this->actingAs($user)->post(route('raw-batches.adjust', $batch), [
        'delta' => -5.0, // больше чем есть
        'notes' => 'тест',
    ]);

    $response->assertSessionHasErrors('delta');
    expect($batch->fresh()->remaining_quantity)->toBe('3.000');
    expect($batch->fresh()->status)->toBe('in_work');
});

test('adjust не меняет статус партии в used при добавлении сырья', function () {
    $user  = makeAdminUser();
    $batch = makeBatch('used', 0.0);

    $this->actingAs($user)->post(route('raw-batches.adjust', $batch), [
        'delta' => 2.0,
    ]);

    $fresh = $batch->fresh();
    expect($fresh->remaining_quantity)->toBe('2.000');
    expect($fresh->status)->toBe('used'); // статус НЕ меняется автоматически обратно
});

// ─── AJAX: markAsUsed возвращает JSON ─────────────────────────────────────────

test('markAsUsed через AJAX возвращает JSON success', function () {
    $user  = makeAdminUser();
    $batch = makeBatch('in_work', 0.0);

    $response = $this->actingAs($user)
        ->postJson(route('raw-batches.mark-used', $batch));

    $response->assertOk()->assertJson(['success' => true]);
    expect($batch->fresh()->status)->toBe('used');
});
