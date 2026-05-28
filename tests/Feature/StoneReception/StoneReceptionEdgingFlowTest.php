<?php

use App\Models\Product;
use App\Models\RawMaterialBatch;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Worker;
use App\Services\Moysklad\RawMaterialBatchSyncService;
use App\Services\Moysklad\StoneReceptionSyncService;
use App\Services\RawMaterialBatchService;
use App\Services\StoneReceptionService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    Setting::updateOrCreate(['key' => 'PIECE_RATE'],       ['value' => '100']);
    Setting::updateOrCreate(['key' => 'UNDERCUT_PENALTY'], ['value' => '1.5']);
    Setting::updateOrCreate(['key' => 'EDGING_COEFF'],     ['value' => '-2.5']);
});

function makeReceptionServiceForEdging(): StoneReceptionService
{
    $mockSync = \Mockery::mock(StoneReceptionSyncService::class);
    $mockSync->shouldReceive('syncReception')->andReturn(null);

    $mockBatchSync = \Mockery::mock(RawMaterialBatchSyncService::class);
    $mockBatchSync->shouldReceive('syncCreated')->andReturn(null);
    $mockBatchSync->shouldReceive('updateParentMove')->andReturn(null);

    return new StoneReceptionService($mockSync, app(RawMaterialBatchService::class), $mockBatchSync);
}

function makeEdgingFixtures(): array
{
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
        'status'             => RawMaterialBatch::STATUS_IN_WORK,
    ]);

    return compact('rawProduct', 'product', 'store', 'cutter', 'receiver', 'batch');
}

describe('StoneReceptionService::create() — флаг is_edging', function () {

    test('сохраняет is_edging=true и effective_cost_coeff=EDGING_COEFF', function () {
        $f = makeEdgingFixtures();

        $reception = makeReceptionServiceForEdging()->create([
            'receiver_id'           => $f['receiver']->id,
            'cutter_id'             => $f['cutter']->id,
            'store_id'              => $f['store']->id,
            'raw_material_batch_id' => $f['batch']->id,
            'raw_quantity_used'     => 5.0,
            'products' => [
                ['product_id' => $f['product']->id, 'quantity' => 2.0, 'is_edging' => '1'],
            ],
        ], false);

        $item = $reception->items->first();

        expect((bool) $item->is_edging)->toBeTrue();
        expect((bool) $item->is_undercut)->toBeFalse();
        expect((float) $item->effective_cost_coeff)->toBe(-2.5);
    });

    test('is_edging + is_undercut → effective = -4.0', function () {
        $f = makeEdgingFixtures();

        $reception = makeReceptionServiceForEdging()->create([
            'receiver_id'           => $f['receiver']->id,
            'cutter_id'             => $f['cutter']->id,
            'store_id'              => $f['store']->id,
            'raw_material_batch_id' => $f['batch']->id,
            'raw_quantity_used'     => 5.0,
            'products' => [
                ['product_id' => $f['product']->id, 'quantity' => 1.0, 'is_edging' => '1', 'is_undercut' => '1'],
            ],
        ], false);

        $item = $reception->items->first();

        expect((float) $item->effective_cost_coeff)->toBe(-4.0);
        expect((bool) $item->is_edging)->toBeTrue();
        expect((bool) $item->is_undercut)->toBeTrue();
    });

    test('без is_edging → effective_cost_coeff = prod_cost_coeff продукта', function () {
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

        expect((bool) $item->is_edging)->toBeFalse();
        expect((float) $item->effective_cost_coeff)->toBe(3.0);
    });

    test('worker_cost_per_m2 рассчитывается от replaced коэффициента', function () {
        // PIECE_RATE=100, EDGING_COEFF=-2.5 → prodCost(-2.5) = 100 * -2.5 = -250
        $f = makeEdgingFixtures();

        $reception = makeReceptionServiceForEdging()->create([
            'receiver_id'           => $f['receiver']->id,
            'cutter_id'             => $f['cutter']->id,
            'store_id'              => $f['store']->id,
            'raw_material_batch_id' => $f['batch']->id,
            'raw_quantity_used'     => 5.0,
            'products' => [
                ['product_id' => $f['product']->id, 'quantity' => 2.0, 'is_edging' => '1'],
            ],
        ], false);

        $item     = $reception->items->first();
        $expected = $f['product']->prodCost(-2.5);

        expect((float) $item->worker_cost_per_m2)->toBe((float) $expected);
    });
});

describe('StoneReceptionService::refreshItemCoeffs() — сохраняет is_edging', function () {

    test('пересчёт после refresh не сбрасывает is_edging и оставляет effective=-2.5', function () {
        $f       = makeEdgingFixtures();
        $service = makeReceptionServiceForEdging();

        $reception = $service->create([
            'receiver_id'           => $f['receiver']->id,
            'cutter_id'             => $f['cutter']->id,
            'store_id'              => $f['store']->id,
            'raw_material_batch_id' => $f['batch']->id,
            'raw_quantity_used'     => 5.0,
            'products' => [
                ['product_id' => $f['product']->id, 'quantity' => 2.0, 'is_edging' => '1'],
            ],
        ], false);

        // Меняем prod_cost_coeff у продукта — это бы пересчитало effective_cost_coeff для обычной позиции,
        // но для is_edging должно остаться -2.5 (полная замена)
        $f['product']->update(['prod_cost_coeff' => 10.0]);

        $service->refreshItemCoeffs($reception->fresh());

        $item = $reception->items()->first();

        expect((bool) $item->is_edging)->toBeTrue();
        expect((float) $item->effective_cost_coeff)->toBe(-2.5);
    });
});
