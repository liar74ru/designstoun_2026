<?php

use App\Models\Department;
use App\Models\DepartmentExpense;
use App\Models\Product;
use App\Models\RawMaterialBatch;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use App\Models\Store;
use App\Models\Worker;
use App\Services\Moysklad\StoneReceptionSyncService;
use Illuminate\Support\Facades\Cache;

// ══════════════════════════════════════════════════════════════════════════════
// Примечание техоперации: расшифровка себестоимости за м²
// ══════════════════════════════════════════════════════════════════════════════

beforeEach(fn () => Cache::flush());

/**
 * Приёмка с двумя позициями и заданными ставками.
 * Возвращает [приёмка, описание из buildReceptionDescription()].
 */
function receptionDescriptionFor(array $items, ?Department $dept = null): array
{
    $store    = Store::factory()->create();
    $cutter   = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
    $receiver = Worker::create(['name' => 'Мастер', 'position' => 'Мастер']);
    $batch  = RawMaterialBatch::create([
        'product_id'         => Product::factory()->create(['name' => 'Гранит'])->id,
        'initial_quantity'   => 100.0,
        'remaining_quantity' => 95.0,
        'current_store_id'   => $store->id,
        'current_worker_id'  => $cutter->id,
        'status'             => RawMaterialBatch::STATUS_IN_WORK,
    ]);

    $reception = StoneReception::create([
        'receiver_id'           => $receiver->id,
        'cutter_id'             => $cutter->id,
        'store_id'              => $store->id,
        'raw_material_batch_id' => $batch->id,
        'raw_quantity_used'     => 5.0,
        'status'                => StoneReception::STATUS_ACTIVE,
        'department_id'         => $dept?->id,
    ]);

    foreach ($items as $row) {
        StoneReceptionItem::create([
            'stone_reception_id' => $reception->id,
            'product_id'         => Product::factory()->create()->id,
            'quantity'           => $row['quantity'],
            'worker_cost_per_m2' => $row['worker'],
            'master_cost_per_m2' => $row['master'],
        ]);
    }

    $service = app(StoneReceptionSyncService::class);
    $method  = (new ReflectionClass($service))->getMethod('buildReceptionDescription');
    $method->setAccessible(true);

    return [$reception, $method->invoke($service, $reception->fresh('items'), $batch)];
}

test('примечание содержит накладные и средние зарплаты за м²', function () {
    $dept = Department::create(['name' => 'Резка', 'is_active' => true]);
    DepartmentExpense::insert([
        ['department_id' => $dept->id, 'name' => 'Аренда',      'amount' => 35],
        ['department_id' => $dept->id, 'name' => 'Расход пилы', 'amount' => 50],
    ]);

    [, $description] = receptionDescriptionFor([
        ['quantity' => 10, 'worker' => 400, 'master' => 100],
        ['quantity' => 10, 'worker' => 500, 'master' => 200],
    ], $dept);

    expect($description)
        ->toContain('Накладные расходы отдела: 85 ₽/м²')
        ->toContain('Зарплата пильщика (средн.): 450 ₽/м²')   // (400*10 + 500*10) / 20
        ->toContain('Зарплата мастера (средн.): 150 ₽/м²');    // (100*10 + 200*10) / 20
});

test('средняя взвешена по количеству, а не по числу позиций', function () {
    $dept = Department::create(['name' => 'Резка', 'is_active' => true]);

    [, $description] = receptionDescriptionFor([
        ['quantity' => 90, 'worker' => 400, 'master' => 100],
        ['quantity' => 10, 'worker' => 500, 'master' => 200],
    ], $dept);

    // (400*90 + 500*10) / 100 = 410 — простое среднее дало бы 450
    expect($description)
        ->toContain('Зарплата пильщика (средн.): 410 ₽/м²')
        ->toContain('Зарплата мастера (средн.): 110 ₽/м²');
});

test('три строки в сумме дают processingSum, уходящий в МойСклад', function () {
    $dept = Department::create(['name' => 'Резка', 'is_active' => true]);
    DepartmentExpense::create([
        'department_id' => $dept->id,
        'name'          => 'Аренда',
        'amount'        => 35,
    ]);

    [$reception, $description] = receptionDescriptionFor([
        ['quantity' => 10, 'worker' => 400, 'master' => 100],
        ['quantity' => 10, 'worker' => 500, 'master' => 200],
    ], $dept);

    // 35 + 450 + 150 = 635 ₽/м²
    preg_match_all('/: ([\d.]+) ₽\/м²/u', $description, $m);
    $sum = array_sum(array_map('floatval', $m[1]));

    $service = app(StoneReceptionSyncService::class);
    $calc    = (new ReflectionClass($service))->getMethod('calcProcessingSum');
    $calc->setAccessible(true);

    $items       = $reception->fresh('items')->items;
    $totalQty    = (float) $items->sum('quantity');
    $workerTotal = $items->sum(fn ($i) => (float) $i->worker_cost_per_m2 * (float) $i->quantity);
    $masterTotal = $items->sum(fn ($i) => (float) $i->master_cost_per_m2 * (float) $i->quantity);
    $overhead    = $service->manualCostPerUnit($dept->id);

    $processingSum = $calc->invoke(
        $service,
        $workerTotal + $masterTotal + $overhead * $totalQty,
        $totalQty
    );

    expect($sum)->toBe(635.0)
        ->and($processingSum)->toBe(63500); // копейки за единицу
});

test('дробные суммы выводятся без хвостовых нулей', function () {
    $dept = Department::create(['name' => 'Резка', 'is_active' => true]);
    DepartmentExpense::create([
        'department_id' => $dept->id,
        'name'          => 'Аренда',
        'amount'        => 35.50,
    ]);

    [, $description] = receptionDescriptionFor([
        ['quantity' => 10, 'worker' => 400, 'master' => 100],
    ], $dept);

    expect($description)
        ->toContain('Накладные расходы отдела: 35.5 ₽/м²')
        ->toContain('Зарплата пильщика (средн.): 400 ₽/м²');
});

test('приёмка без позиций не делит на ноль', function () {
    $dept = Department::create(['name' => 'Резка', 'is_active' => true]);

    [, $description] = receptionDescriptionFor([], $dept);

    expect($description)
        ->toContain('Зарплата пильщика (средн.): 0 ₽/м²')
        ->toContain('Зарплата мастера (средн.): 0 ₽/м²');
});

test('приёмка без отдела берёт накладные по цепочке фолбэка', function () {
    $dept  = Department::create(['name' => 'Резка', 'is_active' => true]);
    DepartmentExpense::create([
        'department_id' => $dept->id,
        'name'          => 'Аренда',
        'amount'        => 35,
    ]);

    [$reception, ] = receptionDescriptionFor([
        ['quantity' => 10, 'worker' => 400, 'master' => 100],
    ]);

    // отдела нет ни у приёмки, ни у партии → накладные 0
    expect($reception->effectiveDepartmentId())->toBeNull();

    $reception->rawMaterialBatch->update(['department_id' => $dept->id]);
    Cache::flush();

    $service = app(StoneReceptionSyncService::class);
    $method  = (new ReflectionClass($service))->getMethod('buildReceptionDescription');
    $method->setAccessible(true);

    $description = $method->invoke(
        $service,
        $reception->fresh('items', 'rawMaterialBatch'),
        $reception->rawMaterialBatch->fresh()
    );

    expect($description)->toContain('Накладные расходы отдела: 35 ₽/м²');
});
