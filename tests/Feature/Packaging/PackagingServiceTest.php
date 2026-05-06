<?php

use App\Models\Packaging;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Worker;
use App\Services\PackagingService;
use App\Services\Moysklad\PackagingSyncService;

beforeEach(function () {
    Setting::set('PACKAGING_PROD_COST', 100);
    Setting::set('PACKAGING_COST', 30);

    $this->packer   = Worker::create(['name' => 'Упаковщик', 'position' => 'Мастер']);
    $this->receiver = Worker::create(['name' => 'Мастер',    'position' => 'Мастер']);
    $this->store    = Store::factory()->create();

    $this->product = Product::factory()->create([
        'sku'             => '04-01-10',
        'prod_cost_coeff' => 1.5,
    ]);
    $this->packageProduct = Product::factory()->create([
        'sku'             => '07-03-01',
        'prod_cost_coeff' => 2.0,
    ]);

    $this->mockSync = \Mockery::mock(PackagingSyncService::class);
    $this->mockSync->shouldReceive('syncPackaging')->andReturn(null);
});

// ══════════════════════════════════════════════════════════════════════════════
// PackagingService::create()
// ══════════════════════════════════════════════════════════════════════════════

describe('PackagingService::create()', function () {

    test('сохраняет worker_cost_per_m2 по новой формуле упаковщика', function () {
        $service = new PackagingService($this->mockSync);

        $packaging = $service->create([
            'packer_id'          => $this->packer->id,
            'receiver_id'        => $this->receiver->id,
            'store_id'           => $this->store->id,
            'package_product_id' => $this->packageProduct->id,
            'package_quantity'   => 1.0,
            'products'           => [
                ['product_id' => $this->product->id, 'quantity' => 5.0],
            ],
        ], false);

        $item = $packaging->items()->first();

        // 100 × 1.5 + 30 × 2.0 = 210
        expect((float) $item->worker_cost_per_m2)->toBe(210.0);
    });

    test('worker_cost_per_m2 учитывает коэффициент упаковочной тары', function () {
        Setting::set('PACKAGING_PROD_COST', 0);
        Setting::set('PACKAGING_COST', 50);

        $this->packageProduct->update(['prod_cost_coeff' => 3.5]);

        $service = new PackagingService($this->mockSync);
        $packaging = $service->create([
            'packer_id'          => $this->packer->id,
            'receiver_id'        => $this->receiver->id,
            'store_id'           => $this->store->id,
            'package_product_id' => $this->packageProduct->id,
            'package_quantity'   => 1.0,
            'products'           => [
                ['product_id' => $this->product->id, 'quantity' => 1.0],
            ],
        ], false);

        // 0 × 1.5 + 50 × 3.5 = 175
        expect((float) $packaging->items()->first()->worker_cost_per_m2)->toBe(175.0);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// PackagingService::refreshItemCoeffs()
// ══════════════════════════════════════════════════════════════════════════════

describe('PackagingService::refreshItemCoeffs()', function () {

    test('пересчитывает worker_cost_per_m2 после изменения настроек', function () {
        $service = new PackagingService($this->mockSync);

        $packaging = $service->create([
            'packer_id'          => $this->packer->id,
            'receiver_id'        => $this->receiver->id,
            'store_id'           => $this->store->id,
            'package_product_id' => $this->packageProduct->id,
            'package_quantity'   => 1.0,
            'products'           => [
                ['product_id' => $this->product->id, 'quantity' => 1.0],
            ],
        ], false);

        // Меняем настройки и пересчитываем
        Setting::set('PACKAGING_PROD_COST', 200);
        Setting::set('PACKAGING_COST', 60);

        $service->refreshItemCoeffs($packaging);

        // 200 × 1.5 + 60 × 2.0 = 420
        expect((float) $packaging->items()->first()->fresh()->worker_cost_per_m2)->toBe(420.0);
    });
});
