<?php

use App\Models\User;
use App\Models\Worker;
use App\Models\Product;
use App\Models\Store;
use App\Models\RawMaterialBatch;
use App\Http\Controllers\RawMaterialBatchController;
use Carbon\Carbon;

// ──────────────────────────────────────────────────────────────────────────────
// generateBatchNumber — автономер партии
// ──────────────────────────────────────────────────────────────────────────────

test('номер партии имеет формат ГГ-НН-Фамилия-ПП', function () {
    $worker     = Worker::create(['name' => 'Иванов Иван', 'position' => 'Пильщик']);
    $controller = app(RawMaterialBatchController::class);

    $number = $controller->generateBatchNumber($worker);
    $year   = now()->format('y');
    $week   = now()->format('W');

    expect($number)->toStartWith("{$year}-{$week}-Иванов-");
    expect($number)->toMatch('/^\d{2}-\d{2}-\S+-\d{2}$/');
});

test('первая партия пильщика за неделю получает номер 01', function () {
    $worker     = Worker::create(['name' => 'Петров Пётр', 'position' => 'Пильщик']);
    $controller = app(RawMaterialBatchController::class);

    expect($controller->generateBatchNumber($worker))->toEndWith('-01');
});

test('вторая партия того же пильщика за ту же неделю получает номер 02', function () {
    $worker  = Worker::create(['name' => 'Сидоров', 'position' => 'Пильщик']);
    $product = Product::factory()->create();
    $store   = Store::factory()->create();

    RawMaterialBatch::create([
        'product_id'         => $product->id,
        'initial_quantity'   => 10,
        'remaining_quantity' => 10,
        'current_store_id'   => $store->id,
        'current_worker_id'  => $worker->id,
        'status'             => 'in_work',
        'created_at'         => now(),
    ]);

    $controller = app(RawMaterialBatchController::class);
    expect($controller->generateBatchNumber($worker))->toEndWith('-02');
});

test('партии прошлой недели не влияют на счётчик текущей', function () {
    $worker  = Worker::create(['name' => 'Козлов', 'position' => 'Пильщик']);
    $product = Product::factory()->create();
    $store   = Store::factory()->create();

    // Явно ставим дату ДО начала текущей ISO-недели (понедельник минус 1 день)
    $lastWeek = now()->startOfWeek()->subDay()->midDay();

    // RawMaterialBatch::create() перезаписывает created_at текущим временем —
    // используем DB::table чтобы явно сохранить дату прошлой недели
    \Illuminate\Support\Facades\DB::table('raw_material_batches')->insert([
        'product_id'         => $product->id,
        'initial_quantity'   => 5,
        'remaining_quantity' => 5,
        'current_store_id'   => $store->id,
        'current_worker_id'  => $worker->id,
        'status'             => 'in_work',
        'created_at'         => $lastWeek,
        'updated_at'         => $lastWeek,
    ]);

    $controller = app(RawMaterialBatchController::class);
    expect($controller->generateBatchNumber($worker))->toEndWith('-01');
});

// ──────────────────────────────────────────────────────────────────────────────
// API эндпоинт
// ──────────────────────────────────────────────────────────────────────────────

test('API возвращает следующий номер партии для авторизованного', function () {
    $worker = Worker::create(['name' => 'Морозов', 'position' => 'Пильщик']);
    $user   = User::factory()->create(['is_admin' => true]);

    $this->actingAs($user)
        ->getJson("/api/workers/{$worker->id}/next-batch-number")
        ->assertStatus(200)
        ->assertJsonStructure(['batch_number'])
        ->assertJsonPath('batch_number', fn($v) => str_ends_with($v, '-01'));
});

test('API недоступен без авторизации', function () {
    $worker = Worker::create(['name' => 'Тестов', 'position' => 'Пильщик']);

    $this->getJson("/api/workers/{$worker->id}/next-batch-number")
        ->assertStatus(401);
});
