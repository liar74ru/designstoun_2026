<?php

use App\Models\Department;
use App\Models\Product;
use App\Models\RawMaterialBatch;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Worker;
use App\Services\DepartmentModifierService;
use App\Services\RawMaterialBatchService;
use App\Services\StoneReceptionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Cache::flush();
    Setting::updateOrCreate(['key' => 'PIECE_RATE'], ['value' => '100']);
});

function makeReceptionServiceForEdging(): StoneReceptionService
{
    Queue::fake();

    return new StoneReceptionService(app(RawMaterialBatchService::class));
}

function makeEdgingFixtures(): array
{
    // Правила себестоимости живут на уровне отдела и не наследуются: без отдела
    // с заведёнными правилами подкол и торцовка не применятся вовсе.
    $department = Department::create(['name' => 'Резка', 'is_active' => true]);
    app(DepartmentModifierService::class)->applyDefaults($department);

    $rawProduct = Product::factory()->create(['name' => 'Сырьё 04', 'sku' => '04-01']);
    $product    = Product::factory()->create([
        'name'            => 'Плитка',
        'sku'             => '04-01-20',
        'prod_cost_coeff' => 3.0,
    ]);
    $store      = Store::factory()->create();
    $cutter     = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
    $receiver   = Worker::create(['name' => 'Приёмщик', 'position' => 'Мастер']);

    $batch = RawMaterialBatch::create([
        'product_id'         => $rawProduct->id,
        'initial_quantity'   => 100.0,
        'remaining_quantity' => 100.0,
        'current_store_id'   => $store->id,
        'current_worker_id'  => $cutter->id,
        'department_id'      => $department->id,
        'status'             => RawMaterialBatch::STATUS_IN_WORK,
    ]);

    return compact('rawProduct', 'product', 'store', 'cutter', 'receiver', 'batch', 'department');
}

describe('StoneReceptionService::create() — правило «торцовка»', function () {

    // Правило «торцовка» — слагаемое −2.5 к коэффициенту продукта (3.0),
    // а не замена коэффициента, как было до упрощения модели правил.
    test('отмеченное правило вычитает своё значение из коэффициента', function () {
        $f = makeEdgingFixtures();

        $reception = makeReceptionServiceForEdging()->create([
            'receiver_id'           => $f['receiver']->id,
            'cutter_id'             => $f['cutter']->id,
            'store_id'              => $f['store']->id,
            'raw_material_batch_id' => $f['batch']->id,
            'raw_quantity_used'     => 5.0,
            'products' => [
                ['product_id' => $f['product']->id, 'quantity' => 2.0, 'modifiers' => ['edging']],
            ],
        ], false);

        $item = $reception->items->first();

        expect($item->modifiers->pluck('key')->all())->toBe(['edging']);
        expect((float) $item->effective_cost_coeff)->toBe(0.5);
    });

    test('торцовка и подкол складываются: 3 − 2.5 − 1.5 = −1.0', function () {
        $f = makeEdgingFixtures();

        $reception = makeReceptionServiceForEdging()->create([
            'receiver_id'           => $f['receiver']->id,
            'cutter_id'             => $f['cutter']->id,
            'store_id'              => $f['store']->id,
            'raw_material_batch_id' => $f['batch']->id,
            'raw_quantity_used'     => 5.0,
            'products' => [
                ['product_id' => $f['product']->id, 'quantity' => 1.0, 'modifiers' => ['edging', 'undercut']],
            ],
        ], false);

        $item = $reception->items->first();

        expect((float) $item->effective_cost_coeff)->toBe(-1.0);
        expect($item->modifiers->pluck('key')->sort()->values()->all())->toBe(['edging', 'undercut']);
    });

    test('без правил → effective_cost_coeff = prod_cost_coeff продукта', function () {
        $f = makeEdgingFixtures();

        $reception = makeReceptionServiceForEdging()->create([
            'receiver_id'           => $f['receiver']->id,
            'cutter_id'             => $f['cutter']->id,
            'store_id'              => $f['store']->id,
            'raw_material_batch_id' => $f['batch']->id,
            'raw_quantity_used'     => 5.0,
            'products' => [
                ['product_id' => $f['product']->id, 'quantity' => 2.0],
            ],
        ], false);

        $item = $reception->items->first();

        expect($item->modifiers->pluck('key')->all())->not->toContain('edging');
        expect((float) $item->effective_cost_coeff)->toBe(3.0);
    });

    test('worker_cost_per_m2 считается от итогового коэффициента', function () {
        // PIECE_RATE=100, коэффициент продукта 3.0, торцовка −2.5 → 0.5
        $f = makeEdgingFixtures();

        $reception = makeReceptionServiceForEdging()->create([
            'receiver_id'           => $f['receiver']->id,
            'cutter_id'             => $f['cutter']->id,
            'store_id'              => $f['store']->id,
            'raw_material_batch_id' => $f['batch']->id,
            'raw_quantity_used'     => 5.0,
            'products' => [
                ['product_id' => $f['product']->id, 'quantity' => 2.0, 'modifiers' => ['edging']],
            ],
        ], false);

        $item     = $reception->items->first();
        $expected = $f['product']->prodCost(0.5);

        expect((float) $item->worker_cost_per_m2)->toBe((float) $expected);
    });
});

describe('StoneReceptionService::refreshItemCoeffs() — сохраняет выбор правил', function () {

    test('пересчёт не теряет отмеченное правило и учитывает новый коэффициент продукта', function () {
        $f       = makeEdgingFixtures();
        $service = makeReceptionServiceForEdging();

        $reception = $service->create([
            'receiver_id'           => $f['receiver']->id,
            'cutter_id'             => $f['cutter']->id,
            'store_id'              => $f['store']->id,
            'raw_material_batch_id' => $f['batch']->id,
            'raw_quantity_used'     => 5.0,
            'products' => [
                ['product_id' => $f['product']->id, 'quantity' => 2.0, 'modifiers' => ['edging']],
            ],
        ], false);

        // Торцовка — слагаемое, поэтому новый коэффициент продукта на неё влияет: 10 − 2.5
        $f['product']->update(['prod_cost_coeff' => 10.0]);

        $service->refreshItemCoeffs($reception->fresh());

        $item = $reception->items()->first();

        expect($item->fresh()->modifiers->pluck('key')->all())->toContain('edging');
        expect((float) $item->effective_cost_coeff)->toBe(7.5);
    });
});
