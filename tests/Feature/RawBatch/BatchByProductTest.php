<?php

use App\Models\Product;
use App\Models\RawMaterialBatch;
use App\Models\Store;
use App\Models\User;
use App\Models\Worker;
use App\Services\RawMaterialBatchService;

// Хелпер: создать партию с заданными параметрами
function makeWorkerProductBatch(array $attrs = []): RawMaterialBatch
{
    return RawMaterialBatch::create(array_merge([
        'initial_quantity'   => 10,
        'remaining_quantity' => 8,
        'status'             => 'in_work',
        'current_store_id'   => Store::factory()->create()->id,
    ], $attrs));
}

// ──────────────────────────────────────────────────────────────────────────────
// Сервис: findWorkerBatchByProduct
// ──────────────────────────────────────────────────────────────────────────────

test('findWorkerBatchByProduct → находит рабочую партию пильщика по продукту', function () {
    $worker  = Worker::create(['name' => 'Иванов', 'position' => 'Работник']);
    $product = Product::factory()->create();
    $batch   = makeWorkerProductBatch(['product_id' => $product->id, 'current_worker_id' => $worker->id]);

    $found = app(RawMaterialBatchService::class)->findWorkerBatchByProduct($worker, $product->id);

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($batch->id);
});

test('findWorkerBatchByProduct → учитывает статусы new/in_work/confirmed', function () {
    $worker  = Worker::create(['name' => 'Петров', 'position' => 'Работник']);
    $product = Product::factory()->create();
    $service = app(RawMaterialBatchService::class);

    foreach (['new', 'in_work', 'confirmed'] as $status) {
        $batch = makeWorkerProductBatch([
            'product_id'        => $product->id,
            'current_worker_id' => $worker->id,
            'status'            => $status,
        ]);

        expect($service->findWorkerBatchByProduct($worker, $product->id)?->id)->toBe($batch->id);

        $batch->delete();
    }
});

test('findWorkerBatchByProduct → игнорирует завершённые партии (used/returned/archived)', function () {
    $worker  = Worker::create(['name' => 'Сидоров', 'position' => 'Работник']);
    $product = Product::factory()->create();
    $service = app(RawMaterialBatchService::class);

    foreach (['used', 'returned', 'archived'] as $status) {
        makeWorkerProductBatch([
            'product_id'        => $product->id,
            'current_worker_id' => $worker->id,
            'status'            => $status,
        ]);
    }

    expect($service->findWorkerBatchByProduct($worker, $product->id))->toBeNull();
});

test('findWorkerBatchByProduct → не находит партию другого продукта', function () {
    $worker   = Worker::create(['name' => 'Козлов', 'position' => 'Работник']);
    $product  = Product::factory()->create();
    $other    = Product::factory()->create();
    makeWorkerProductBatch(['product_id' => $other->id, 'current_worker_id' => $worker->id]);

    expect(app(RawMaterialBatchService::class)->findWorkerBatchByProduct($worker, $product->id))->toBeNull();
});

test('findWorkerBatchByProduct → не находит партию другого пильщика', function () {
    $worker   = Worker::create(['name' => 'Морозов', 'position' => 'Работник']);
    $other    = Worker::create(['name' => 'Волков', 'position' => 'Работник']);
    $product  = Product::factory()->create();
    makeWorkerProductBatch(['product_id' => $product->id, 'current_worker_id' => $other->id]);

    expect(app(RawMaterialBatchService::class)->findWorkerBatchByProduct($worker, $product->id))->toBeNull();
});

test('findWorkerBatchByProduct → возвращает последнюю партию при нескольких совпадениях', function () {
    $worker  = Worker::create(['name' => 'Зайцев', 'position' => 'Работник']);
    $product = Product::factory()->create();

    makeWorkerProductBatch(['product_id' => $product->id, 'current_worker_id' => $worker->id]);
    $latest = makeWorkerProductBatch(['product_id' => $product->id, 'current_worker_id' => $worker->id]);

    expect(app(RawMaterialBatchService::class)->findWorkerBatchByProduct($worker, $product->id)?->id)
        ->toBe($latest->id);
});

// ──────────────────────────────────────────────────────────────────────────────
// API эндпоинт: /api/workers/{worker}/batch-by-product
// ──────────────────────────────────────────────────────────────────────────────

test('API возвращает данные партии для авторизованного', function () {
    $worker  = Worker::create(['name' => 'Белов', 'position' => 'Работник']);
    $product = Product::factory()->create();
    $batch   = makeWorkerProductBatch([
        'product_id'         => $product->id,
        'current_worker_id'  => $worker->id,
        'initial_quantity'   => 12,
        'remaining_quantity' => 5,
    ]);
    $user = User::factory()->create(['is_admin' => true]);

    $this->actingAs($user)
        ->getJson("/api/workers/{$worker->id}/batch-by-product?product_id={$product->id}")
        ->assertStatus(200)
        ->assertJsonStructure(['batch_id', 'batch_number', 'initial_quantity', 'remaining_quantity', 'adjust_url'])
        ->assertJsonPath('batch_id', $batch->id)
        ->assertJsonPath('initial_quantity', 12)
        ->assertJsonPath('remaining_quantity', 5);
});

test('API возвращает null если рабочей партии нет', function () {
    $worker  = Worker::create(['name' => 'Орлов', 'position' => 'Работник']);
    $product = Product::factory()->create();
    $user    = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($user)
        ->getJson("/api/workers/{$worker->id}/batch-by-product?product_id={$product->id}")
        ->assertStatus(200);

    expect($response->json())->toBe([]); // JsonResponse(null) сериализуется как {}
});

test('API возвращает null без product_id', function () {
    $worker = Worker::create(['name' => 'Гусев', 'position' => 'Работник']);
    $user   = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($user)
        ->getJson("/api/workers/{$worker->id}/batch-by-product")
        ->assertStatus(200);

    expect($response->json())->toBe([]); // JsonResponse(null) сериализуется как {}
});

test('API недоступен без авторизации', function () {
    $worker  = Worker::create(['name' => 'Лисов', 'position' => 'Работник']);
    $product = Product::factory()->create();

    $this->getJson("/api/workers/{$worker->id}/batch-by-product?product_id={$product->id}")
        ->assertStatus(401);
});
