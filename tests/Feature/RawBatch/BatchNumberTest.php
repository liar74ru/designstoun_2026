<?php

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\RawMaterialBatch;
use App\Models\RawMaterialMovement;
use App\Models\Store;
use App\Models\Worker;
use App\Services\RawMaterialBatchService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Helpers\ReceptionTestHelper as H;

function bnBatch(Worker $worker, ?string $number): RawMaterialBatch
{
    return RawMaterialBatch::create([
        'product_id'         => Product::factory()->create()->id,
        'initial_quantity'   => 10,
        'remaining_quantity' => 10,
        'current_store_id'   => Store::factory()->create()->id,
        'current_worker_id'  => $worker->id,
        'batch_number'       => $number,
        'status'             => 'in_work',
    ]);
}

/** Данные формы новой партии: сырьё лежит на складе-источнике. */
function bnCreateData(Worker $worker, ?string $number): array
{
    $product = Product::factory()->create();
    $from    = Store::factory()->create();
    ProductStock::create(['product_id' => $product->id, 'store_id' => $from->id, 'quantity' => 100]);

    return [
        'product_id'    => $product->id,
        'quantity'      => 5,
        'from_store_id' => $from->id,
        'to_store_id'   => Store::factory()->create()->id,
        'worker_id'     => $worker->id,
        'batch_number'  => $number,
    ];
}

// ──────────────────────────────────────────────────────────────────────────────
// generateBatchNumber — автономер партии
// ──────────────────────────────────────────────────────────────────────────────

test('номер партии имеет формат ГГ-НН-Фамилия-ПП', function () {
    $worker     = Worker::create(['name' => 'Иванов Иван', 'position' => 'Работник']);
    $controller = app(RawMaterialBatchService::class);

    $number = $controller->generateBatchNumber($worker);
    $year   = now()->format('y');
    $week   = now()->format('W');

    expect($number)->toStartWith("{$year}-{$week}-Иванов-");
    expect($number)->toMatch('/^\d{2}-\d{2}-\S+-\d{2}$/');
});

test('первая партия пильщика за неделю получает номер 01', function () {
    $worker     = Worker::create(['name' => 'Петров Пётр', 'position' => 'Работник']);
    $controller = app(RawMaterialBatchService::class);

    expect($controller->generateBatchNumber($worker))->toEndWith('-01');
});

test('вторая партия того же пильщика за ту же неделю получает номер 02', function () {
    $worker  = Worker::create(['name' => 'Сидоров', 'position' => 'Работник']);
    $service = app(RawMaterialBatchService::class);

    bnBatch($worker, $service->generateBatchNumber($worker));

    expect($service->generateBatchNumber($worker))->toEndWith('-02');
});

test('номер считается по максимуму: удаление партии не даёт повтора', function () {
    $worker  = Worker::create(['name' => 'Сидоров', 'position' => 'Работник']);
    $service = app(RawMaterialBatchService::class);

    $first = bnBatch($worker, $service->generateBatchNumber($worker));
    bnBatch($worker, $service->generateBatchNumber($worker));
    $first->delete();

    expect($service->generateBatchNumber($worker))->toEndWith('-03');
});

test('передача партии другому пильщику не уменьшает счёт', function () {
    $worker  = Worker::create(['name' => 'Сидоров', 'position' => 'Работник']);
    $other   = Worker::create(['name' => 'Петров', 'position' => 'Работник']);
    $service = app(RawMaterialBatchService::class);

    bnBatch($worker, $service->generateBatchNumber($worker))->update(['current_worker_id' => $other->id]);

    expect($service->generateBatchNumber($worker))->toEndWith('-02');
});

test('однофамильцы не получают одинаковых номеров', function () {
    $one     = Worker::create(['name' => 'Иванов Иван', 'position' => 'Работник']);
    $two     = Worker::create(['name' => 'Иванов Пётр', 'position' => 'Работник']);
    $service = app(RawMaterialBatchService::class);

    bnBatch($one, $service->generateBatchNumber($one));

    expect($service->generateBatchNumber($two))->toEndWith('-02');
});

test('номера чужих производных партий не сбивают счёт', function () {
    $worker  = Worker::create(['name' => 'Сидоров', 'position' => 'Работник']);
    $service = app(RawMaterialBatchService::class);
    $number  = $service->generateBatchNumber($worker);

    bnBatch($worker, $number);
    bnBatch($worker, $number . '-Петров Пётр');
    bnBatch($worker, $number . '/7');

    expect($service->generateBatchNumber($worker))->toEndWith('-02');
});

test('партии прошлой недели не влияют на счётчик текущей', function () {
    $worker  = Worker::create(['name' => 'Козлов', 'position' => 'Работник']);
    $product = Product::factory()->create();
    $store   = Store::factory()->create();

    // Явно ставим дату ДО начала текущей ISO-недели (понедельник минус 1 день)
    $lastWeek = now()->startOfWeek()->subDay()->midDay();

    // RawMaterialBatch::create() перезаписывает created_at текущим временем —
    // используем DB::table чтобы явно сохранить дату прошлой недели
    DB::table('raw_material_batches')->insert([
        'product_id'         => $product->id,
        'initial_quantity'   => 5,
        'remaining_quantity' => 5,
        'current_store_id'   => $store->id,
        'current_worker_id'  => $worker->id,
        'status'             => 'in_work',
        'created_at'         => $lastWeek,
        'updated_at'         => $lastWeek,
    ]);

    $controller = app(RawMaterialBatchService::class);
    expect($controller->generateBatchNumber($worker))->toEndWith('-01');
});

// ──────────────────────────────────────────────────────────────────────────────
// API эндпоинт
// ──────────────────────────────────────────────────────────────────────────────

test('API возвращает следующий номер партии для авторизованного', function () {
    $worker = Worker::create(['name' => 'Морозов', 'position' => 'Работник']);
    $user   = H::adminUser();

    $this->actingAs($user)
        ->getJson("/api/workers/{$worker->id}/next-batch-number")
        ->assertStatus(200)
        ->assertJsonStructure(['batch_number'])
        ->assertJsonPath('batch_number', fn ($v) => str_ends_with($v, '-01'));
});

test('API недоступен без авторизации', function () {
    $worker = Worker::create(['name' => 'Тестов', 'position' => 'Работник']);

    $this->getJson("/api/workers/{$worker->id}/next-batch-number")
        ->assertStatus(401);
});

// ──────────────────────────────────────────────────────────────────────────────
// Уникальность при сохранении: номер в форме посчитан при её открытии
// ──────────────────────────────────────────────────────────────────────────────

describe('Занятый номер при сохранении', function () {

    beforeEach(function () {
        Queue::fake();
        $this->worker  = Worker::create(['name' => 'Сидоров', 'position' => 'Работник']);
        $this->service = app(RawMaterialBatchService::class);
    });

    test('две формы с одним автономером → разные номера', function () {
        $number = $this->service->generateBatchNumber($this->worker);

        $first  = $this->service->create(bnCreateData($this->worker, $number), false)['batch'];
        $second = $this->service->create(bnCreateData($this->worker, $number), false)['batch'];

        expect($first->batch_number)->toBe($number);
        expect($second->batch_number)->toEndWith('-02');
    });

    test('через контроллер: вторая форма сохраняется без ошибки', function () {
        $number = $this->service->generateBatchNumber($this->worker);
        $dept   = H::departmentWithModifiers();

        foreach ([1, 2] as $i) {
            $this->actingAs(H::adminUser())
                ->post(route('raw-batches.store'), bnCreateData($this->worker, $number) + ['department_id' => $dept->id])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }

        expect(RawMaterialBatch::pluck('batch_number')->unique())->toHaveCount(2);
    });

    test('занятый ручной номер получает суффикс', function () {
        $first  = $this->service->create(bnCreateData($this->worker, 'РУЧНОЙ'), false)['batch'];
        $second = $this->service->create(bnCreateData($this->worker, 'РУЧНОЙ'), false)['batch'];
        $third  = $this->service->create(bnCreateData($this->worker, 'РУЧНОЙ'), false)['batch'];

        expect([$first->batch_number, $second->batch_number, $third->batch_number])
            ->toBe(['РУЧНОЙ', 'РУЧНОЙ_01', 'РУЧНОЙ_02']);
    });

    test('партий без номера может быть несколько', function () {
        $this->service->create(bnCreateData($this->worker, null), false);
        $this->service->create(bnCreateData($this->worker, null), false);

        expect(RawMaterialBatch::whereNull('batch_number')->count())->toBe(2);
    });

    test('гонка: конфликт индекса при вставке → партия сохраняется со второй попытки', function () {
        $calls = 0;
        RawMaterialBatch::creating(function () use (&$calls) {
            if (++$calls === 1) {
                throw new UniqueConstraintViolationException('sqlite', 'insert', [], new Exception('duplicate'));
            }
        });

        $batch = $this->service->create(bnCreateData($this->worker, 'Н-1'), false)['batch'];

        expect($calls)->toBe(2);
        expect($batch->exists)->toBeTrue();
        expect(RawMaterialMovement::where('batch_id', $batch->id)->count())->toBe(1);
    });

    test('конфликт не проходит за все попытки → ошибка, а не вечный цикл', function () {
        $calls = 0;
        RawMaterialBatch::creating(function () use (&$calls) {
            $calls++;
            throw new UniqueConstraintViolationException('sqlite', 'insert', [], new Exception('duplicate'));
        });

        expect(fn () => $this->service->create(bnCreateData($this->worker, 'Н-1'), false))
            ->toThrow(UniqueConstraintViolationException::class);
        expect($calls)->toBe(5);
    });

    test('индекс не даёт записать дубль мимо сервиса', function () {
        bnBatch($this->worker, 'Н-1');

        expect(fn () => bnBatch($this->worker, 'Н-1'))->toThrow(UniqueConstraintViolationException::class);
    });

    test('две передачи тому же работнику → разные номера', function () {
        $batch  = H::batch(H::product(), H::store(), $this->worker, 10.0, ['batch_number' => 'Н-1']);
        $target = Worker::create(['name' => 'Петров', 'position' => 'Работник']);

        $first  = $this->service->transfer($batch, ['quantity' => 1, 'to_worker_id' => $target->id])['newBatch'];
        $second = $this->service->transfer($batch->fresh(), ['quantity' => 1, 'to_worker_id' => $target->id])['newBatch'];

        expect($first->batch_number)->toBe('Н-1-Петров');
        expect($second->batch_number)->toBe('Н-1-Петров_01');
    });

    test('split при занятом номере не падает', function () {
        $batch = H::batch(H::product(), H::store(), $this->worker, 10.0, ['batch_number' => 'Н-1']);
        bnBatch($this->worker, 'Н-1/' . $batch->id);

        $child = $this->service->split($batch)['newBatch'];

        expect($child->fresh()->batch_number)->toBe('Н-1/' . $batch->id . '_01');
    });
});

// ──────────────────────────────────────────────────────────────────────────────
// Миграция: прод уже содержит дубли
// ──────────────────────────────────────────────────────────────────────────────

describe('Миграция уникального индекса', function () {

    beforeEach(function () {
        $this->migration = require database_path('migrations/2026_11_01_000002_add_unique_index_to_batch_number.php');
        $this->migration->down();
        $this->worker = Worker::create(['name' => 'Сидоров', 'position' => 'Работник']);
    });

    test('старшая партия сохраняет номер, младшие получают свободный суффикс', function () {
        $old    = bnBatch($this->worker, 'Д-1');
        $second = bnBatch($this->worker, 'Д-1');
        $third  = bnBatch($this->worker, 'Д-1');
        $taken  = bnBatch($this->worker, 'Д-1_01'); // Суффикс уже занят: его надо пропустить
        $other  = bnBatch($this->worker, 'Д-2');

        $this->migration->up();

        expect($old->fresh()->batch_number)->toBe('Д-1');
        expect($second->fresh()->batch_number)->toBe('Д-1_02');
        expect($third->fresh()->batch_number)->toBe('Д-1_03');
        expect($taken->fresh()->batch_number)->toBe('Д-1_01');
        expect($other->fresh()->batch_number)->toBe('Д-2');
    });

    test('пустые строки становятся NULL, партии без номера не трогаются', function () {
        $empty1 = bnBatch($this->worker, '');
        $empty2 = bnBatch($this->worker, '');
        $null   = bnBatch($this->worker, null);

        $this->migration->up();

        expect($empty1->fresh()->batch_number)->toBeNull();
        expect($empty2->fresh()->batch_number)->toBeNull();
        expect($null->fresh()->batch_number)->toBeNull();
    });

    test('после миграции индекс работает', function () {
        bnBatch($this->worker, 'Д-1');

        $this->migration->up();

        expect(fn () => bnBatch($this->worker, 'Д-1'))->toThrow(UniqueConstraintViolationException::class);
    });
});
