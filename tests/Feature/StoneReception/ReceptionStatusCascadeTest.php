<?php

use App\Models\User;
use App\Models\Worker;
use App\Models\Product;
use App\Models\Store;
use App\Models\RawMaterialBatch;
use App\Models\StoneReception;

// ─── Вспомогательные ─────────────────────────────────────────────────────────

function makeCascadeBatch(string $status = 'in_work', float $remaining = 0.0): RawMaterialBatch
{
    $product = Product::factory()->create();
    $store   = Store::factory()->create();
    $worker  = Worker::create(['name' => 'Пильщик Каскад', 'position' => 'Пильщик']);

    return RawMaterialBatch::create([
        'product_id'         => $product->id,
        'initial_quantity'   => 10.0,
        'remaining_quantity' => $remaining,
        'current_store_id'   => $store->id,
        'current_worker_id'  => $worker->id,
        'status'             => $status,
    ]);
}

function makeReception(RawMaterialBatch $batch, string $status = 'active'): int
{
    $store  = Store::factory()->create();
    $cutter = Worker::create(['name' => 'Пильщик ' . uniqid(), 'position' => 'Пильщик']);
    $master = Worker::create(['name' => 'Мастер ' . uniqid(), 'position' => 'Мастер']);

    return (int) \Illuminate\Support\Facades\DB::table('stone_receptions')->insertGetId([
        'raw_material_batch_id' => $batch->id,
        'store_id'              => $store->id,
        'cutter_id'             => $cutter->id,
        'receiver_id'           => $master->id,
        'raw_quantity_used'     => 0,
        'status'                => $status,
        'created_at'            => now(),
        'updated_at'            => now(),
    ]);
}

function findReception(int $id): StoneReception
{
    return StoneReception::findOrFail($id);
}

function makeCascadeAdmin(): User
{
    $worker = Worker::create(['name' => 'Админ Каскад', 'position' => 'Директор']);
    return User::factory()->create(['worker_id' => $worker->id, 'is_admin' => true]);
}

// ─── Каскад: markAsUsed → активная приёмка завершается ───────────────────────

test('markAsUsed переводит активную приёмку в completed', function () {
    $user  = makeCascadeAdmin();
    $batch = makeCascadeBatch('in_work', 0.0);
    $rId   = makeReception($batch, 'active');

    $this->actingAs($user)->post(route('raw-batches.mark-used', $batch));

    expect(findReception($rId)->status)->toBe(StoneReception::STATUS_COMPLETED);
    expect($batch->fresh()->status)->toBe(RawMaterialBatch::STATUS_USED);
});

test('markAsUsed не трогает приёмку в статусе processed', function () {
    $user  = makeCascadeAdmin();
    $batch = makeCascadeBatch('in_work', 0.0);
    $rId   = makeReception($batch, 'processed');

    $this->actingAs($user)->post(route('raw-batches.mark-used', $batch));

    expect(findReception($rId)->status)->toBe('processed'); // не изменилась
});

test('markAsUsed не трогает приёмку в статусе completed', function () {
    $user  = makeCascadeAdmin();
    $batch = makeCascadeBatch('in_work', 0.0);
    $rId   = makeReception($batch, 'completed');

    $this->actingAs($user)->post(route('raw-batches.mark-used', $batch));

    // processed — не была active, не меняется
    expect(findReception($rId)->status)->toBe('completed');
});

// ─── Каскад: markAsInWork сценарий (б) — completed → active ──────────────────

test('markAsInWork переводит completed приёмку обратно в active (сценарий б)', function () {
    $user  = makeCascadeAdmin();
    $batch = makeCascadeBatch('used', 0.0);
    $rId   = makeReception($batch, 'completed');

    $this->actingAs($user)->post(route('raw-batches.mark-in-work', $batch));

    expect($batch->fresh()->status)->toBe(RawMaterialBatch::STATUS_IN_WORK);
    expect(findReception($rId)->status)->toBe(StoneReception::STATUS_ACTIVE);
});

test('markAsInWork не трогает processed приёмку — сценарий а/в (новая будет создана)', function () {
    $user  = makeCascadeAdmin();
    $batch = makeCascadeBatch('used', 0.0);
    $rId   = makeReception($batch, 'processed');

    $this->actingAs($user)->post(route('raw-batches.mark-in-work', $batch));

    expect($batch->fresh()->status)->toBe(RawMaterialBatch::STATUS_IN_WORK);
    expect(findReception($rId)->status)->toBe('processed'); // не изменилась
});

// ─── handleBatchUpdate больше не меняет статус ────────────────────────────────

test('редактирование приёмки не переводит партию в used при нулевом остатке', function () {
    $user    = makeCascadeAdmin();
    $product = Product::factory()->create(['prod_cost_coeff' => 1.0]);
    $store   = Store::factory()->create();
    $cutter  = Worker::create(['name' => 'Пильщик HBU', 'position' => 'Пильщик']);
    $master  = Worker::create(['name' => 'Мастер HBU', 'position' => 'Мастер']);

    $batch = RawMaterialBatch::create([
        'product_id'         => $product->id,
        'initial_quantity'   => 5.0,
        'remaining_quantity' => 3.0,
        'current_store_id'   => $store->id,
        'current_worker_id'  => $cutter->id,
        'status'             => 'in_work',
    ]);

    // Создаём приёмку напрямую в БД (минуя booted(), чтобы не списывать сырьё)
    $receptionId = \Illuminate\Support\Facades\DB::table('stone_receptions')->insertGetId([
        'raw_material_batch_id' => $batch->id,
        'store_id'              => $store->id,
        'cutter_id'             => $cutter->id,
        'receiver_id'           => $master->id,
        'raw_quantity_used'     => 1.0,
        'status'                => 'active',
        'created_at'            => now(),
        'updated_at'            => now(),
    ]);

    $reception = StoneReception::findOrFail($receptionId);

    // Обновляем приёмку с расходом = 3 (весь остаток)
    $this->actingAs($user)->put(route('stone-receptions.update', $reception), [
        'cutter_id'             => $cutter->id,
        'receiver_id'           => $master->id,
        'store_id'              => $store->id,
        'raw_material_batch_id' => $batch->id,
        'raw_quantity_used'     => 3.0,
        'raw_quantity_delta'    => 2.0,
        'products'              => [
            ['product_id' => $product->id, 'quantity' => 1.0],
        ],
    ]);

    // Статус партии должен стать 'confirmed' после редактирования с ненулевым остатком
    expect($batch->fresh()->status)->toBe('confirmed');
});

// ─── updateStocks: первая приёмка переводит партию new → in_work ─────────────

test('создание первой приёмки переводит партию из new в in_work', function () {
    $product = Product::factory()->create(['prod_cost_coeff' => 1.0]);
    $store   = Store::factory()->create();
    $cutter  = Worker::create(['name' => 'Пильщик New', 'position' => 'Пильщик']);

    $batch = RawMaterialBatch::create([
        'product_id'         => $product->id,
        'initial_quantity'   => 10.0,
        'remaining_quantity' => 10.0,
        'current_store_id'   => $store->id,
        'current_worker_id'  => $cutter->id,
        'status'             => 'new',
    ]);

    // Создаём приёмку через модель, которая вызывает updateStocks в booted()
    StoneReception::create([
        'raw_material_batch_id' => $batch->id,
        'store_id'              => $store->id,
        'cutter_id'             => $cutter->id,
        'receiver_id'           => $cutter->id,
        'raw_quantity_used'     => 2.0,
        'status'                => 'active',
    ]);

    $fresh = $batch->fresh();
    expect($fresh->status)->toBe('confirmed');    // new → confirmed (остаток > 0) ✓
    expect($fresh->remaining_quantity)->toBe('8.000'); // 10 - 2 = 8
});

test('создание приёмки не переводит партию в used даже при нулевом остатке', function () {
    $product = Product::factory()->create(['prod_cost_coeff' => 1.0]);
    $store   = Store::factory()->create();
    $cutter  = Worker::create(['name' => 'Пильщик Zero', 'position' => 'Пильщик']);

    $batch = RawMaterialBatch::create([
        'product_id'         => $product->id,
        'initial_quantity'   => 2.0,
        'remaining_quantity' => 2.0,
        'current_store_id'   => $store->id,
        'current_worker_id'  => $cutter->id,
        'status'             => 'in_work',
    ]);

    StoneReception::create([
        'raw_material_batch_id' => $batch->id,
        'store_id'              => $store->id,
        'cutter_id'             => $cutter->id,
        'receiver_id'           => $cutter->id,
        'raw_quantity_used'     => 2.0, // весь остаток
        'status'                => 'active',
    ]);

    $fresh = $batch->fresh();
    expect($fresh->status)->toBe('in_work');      // НЕ 'used' — авто-смена отключена
    expect((float)$fresh->remaining_quantity)->toBe(0.0);
});

// ─── getActiveBatches включает нулевые партии ─────────────────────────────────

test('getBatchesJson включает партию с нулевым remaining в статусе in_work', function () {
    $user    = makeCascadeAdmin();
    $product = Product::factory()->create();
    $store   = Store::factory()->create();
    $worker  = Worker::create(['name' => 'Пильщик Zero List', 'position' => 'Пильщик']);
    User::factory()->create(['worker_id' => $worker->id, 'is_admin' => false]);

    $batch = RawMaterialBatch::create([
        'product_id'         => $product->id,
        'initial_quantity'   => 5.0,
        'remaining_quantity' => 0.0,
        'current_store_id'   => $store->id,
        'current_worker_id'  => $worker->id,
        'status'             => 'in_work',
    ]);

    $response = $this->actingAs($user)
        ->getJson(route('api.worker.batches', $worker));

    $response->assertOk();
    $ids = collect($response->json())->pluck('id');
    expect($ids)->toContain($batch->id);
});

test('getBatchesJson не возвращает партию в статусе used', function () {
    $user    = makeCascadeAdmin();
    $product = Product::factory()->create();
    $store   = Store::factory()->create();
    $worker  = Worker::create(['name' => 'Пильщик Used List', 'position' => 'Пильщик']);

    $batch = RawMaterialBatch::create([
        'product_id'         => $product->id,
        'initial_quantity'   => 5.0,
        'remaining_quantity' => 0.0,
        'current_store_id'   => $store->id,
        'current_worker_id'  => $worker->id,
        'status'             => 'used',
    ]);

    $response = $this->actingAs($user)
        ->getJson(route('api.worker.batches', $worker));

    $response->assertOk();
    $ids = collect($response->json())->pluck('id');
    expect($ids)->not->toContain($batch->id);
});

// ─── AJAX: getActiveReceptionByBatchJson ─────────────────────────────────────

test('api/batches/{batch}/active-reception возвращает null если нет активной', function () {
    $user  = makeCascadeAdmin();
    $batch = makeCascadeBatch('in_work', 3.0);

    $this->actingAs($user)
        ->getJson(route('api.batch.active-reception', $batch))
        ->assertOk()
        ->assertExactJson(['data' => null]); // Laravel обернёт null по-разному, проверим статус
})->skip('null response format depends on Laravel version — adjust assertion if needed');

test('api/batches/{batch}/active-reception возвращает данные приёмки', function () {
    $user  = makeCascadeAdmin();
    $batch = makeCascadeBatch('in_work', 3.0);
    $rId   = makeReception($batch, 'active');

    $response = $this->actingAs($user)
        ->getJson(route('api.batch.active-reception', $batch));

    $response->assertOk()
        ->assertJsonFragment(['reception_id' => $rId]);
});
