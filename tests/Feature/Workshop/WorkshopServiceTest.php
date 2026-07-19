<?php

use App\Models\Department;
use App\Models\Workshop;
use App\Models\WorkshopItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Worker;
use App\Services\WorkshopService;
use App\Services\Moysklad\WorkshopSyncService;

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
    $this->outProduct = Product::factory()->create([
        'sku'             => '05-01-01',
        'prod_cost_coeff' => 1.0,
    ]);

    $this->mockSync = \Mockery::mock(WorkshopSyncService::class);
    $this->mockSync->shouldReceive('syncWorkshop')->andReturn(null);
});

/** Базовый payload новой формы цеха. */
function basePayload($ctx, array $overrides = []): array
{
    return array_merge([
        'packer_id'     => $ctx->packer->id,
        'receiver_id'   => $ctx->receiver->id,
        'store_id'      => $ctx->store->id,
        'raw_materials' => [
            ['product_id' => $ctx->product->id, 'quantity' => 5.0],
        ],
        'packages' => [
            ['product_id' => $ctx->packageProduct->id, 'quantity' => 1.0],
        ],
        'products' => [
            ['product_id' => $ctx->outProduct->id, 'quantity' => 1.0],
        ],
    ], $overrides);
}

// ══════════════════════════════════════════════════════════════════════════════
// WorkshopService::create()
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkshopService::create()', function () {

    test('строки сырья/упаковки/продукта раскладываются по ролям', function () {
        $service  = new WorkshopService($this->mockSync);
        $workshop = $service->create(basePayload($this), false);

        expect($workshop->rawItems()->count())->toBe(1)
            ->and($workshop->packageItems()->count())->toBe(1)
            ->and($workshop->productItems()->count())->toBe(1)
            ->and((float) $workshop->rawItems()->first()->quantity)->toBe(5.0)
            ->and((float) $workshop->productItems()->first()->quantity)->toBe(1.0);
    });

    test('сохраняет worker_cost_per_m2 сырья по формуле работника', function () {
        $service  = new WorkshopService($this->mockSync);
        $workshop = $service->create(basePayload($this), false);

        // 100 × 1.5 (коэф сырья) + 30 × 2.0 (коэф тары) = 210
        expect((float) $workshop->rawItems()->first()->worker_cost_per_m2)->toBe(210.0);
    });

    test('worker_cost_per_m2 учитывает коэффициент упаковочной тары', function () {
        Setting::set('PACKAGING_PROD_COST', 0);
        Setting::set('PACKAGING_COST', 50);
        $this->packageProduct->update(['prod_cost_coeff' => 3.5]);

        $service  = new WorkshopService($this->mockSync);
        $workshop = $service->create(basePayload($this), false);

        // 0 × 1.5 + 50 × 3.5 = 175
        expect((float) $workshop->rawItems()->first()->worker_cost_per_m2)->toBe(175.0);
    });

    test('сохраняет manual_processing_sum и явно переданный department_id', function () {
        $dept = Department::create(['name' => 'Цех', 'code' => 'TSEH']);

        $service  = new WorkshopService($this->mockSync);
        $workshop = $service->create(basePayload($this, [
            'department_id'         => $dept->id,
            'manual_processing_sum' => 12.50,
        ]), false);

        expect((float) $workshop->manual_processing_sum)->toBe(12.50)
            ->and($workshop->department_id)->toBe($dept->id);
    });

    test('без department_id — отдел работника, manual_processing_sum NULL', function () {
        $dept = Department::create(['name' => 'Цех', 'code' => 'TSEH']);
        $this->packer->update(['department_id' => $dept->id]);

        $service  = new WorkshopService($this->mockSync);
        $workshop = $service->create(basePayload($this), false);

        expect($workshop->manual_processing_sum)->toBeNull()
            ->and($workshop->department_id)->toBe($dept->id);
    });

    test('операция без упаковки создаётся (packages опциональны)', function () {
        $service  = new WorkshopService($this->mockSync);
        $workshop = $service->create(basePayload($this, ['packages' => []]), false);

        expect($workshop->packageItems()->count())->toBe(0)
            ->and($workshop->rawItems()->count())->toBe(1);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// WorkshopService::update()
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkshopService::update()', function () {

    test('добавляет и удаляет продукт на выходе', function () {
        $service  = new WorkshopService($this->mockSync);
        $workshop = $service->create(basePayload($this), false);

        $extra = Product::factory()->create(['sku' => '05-02-02']);

        // Добавляем второй продукт.
        $service->update($workshop, basePayload($this, [
            'products' => [
                ['product_id' => $this->outProduct->id, 'quantity' => 1.0],
                ['product_id' => $extra->id,            'quantity' => 2.0],
            ],
        ]), false);
        expect($workshop->fresh()->productItems()->count())->toBe(2);

        // Убираем второй.
        $service->update($workshop->fresh(), basePayload($this), false);
        expect($workshop->fresh()->productItems()->count())->toBe(1);
    });

    test('обновляет manual_processing_sum', function () {
        $service  = new WorkshopService($this->mockSync);
        $workshop = $service->create(basePayload($this), false);

        $service->update($workshop, basePayload($this, ['manual_processing_sum' => 33.0]), false);
        expect((float) $workshop->fresh()->manual_processing_sum)->toBe(33.0);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Склады: product_store_id и перенос остатка тары
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkshopService: склады', function () {

    test('create сохраняет product_store_id', function () {
        $productStore = Store::factory()->create();
        $service      = new WorkshopService($this->mockSync);

        $workshop = $service->create(basePayload($this, [
            'product_store_id' => $productStore->id,
        ]), false);

        expect($workshop->product_store_id)->toBe($productStore->id)
            ->and($workshop->store_id)->toBe($this->store->id);
    });

    test('create списывает тару со склада сырья', function () {
        $service = new WorkshopService($this->mockSync);
        $service->create(basePayload($this, [
            'product_store_id' => $this->store->id,
            'packages'         => [['product_id' => $this->packageProduct->id, 'quantity' => 3.0]],
        ]), false);

        $stock = \App\Models\ProductStock::where('product_id', $this->packageProduct->id)
            ->where('store_id', $this->store->id)->first();
        expect((float) $stock->quantity)->toBe(-3.0);
    });

    test('update со сменой склада сырья переносит списанную тару', function () {
        $service = new WorkshopService($this->mockSync);

        $workshop = $service->create(basePayload($this, [
            'product_store_id' => $this->store->id,
            'packages'         => [['product_id' => $this->packageProduct->id, 'quantity' => 3.0]],
        ]), false);

        $oldStock = \App\Models\ProductStock::where('product_id', $this->packageProduct->id)
            ->where('store_id', $this->store->id)->first();
        expect((float) $oldStock->quantity)->toBe(-3.0);

        $newStore = Store::factory()->create();
        $service->update($workshop, basePayload($this, [
            'store_id'         => $newStore->id,
            'product_store_id' => $this->store->id,
            'packages'         => [['product_id' => $this->packageProduct->id, 'quantity' => 3.0]],
        ]), false);

        expect((float) $oldStock->fresh()->quantity)->toBe(0.0);

        $newStock = \App\Models\ProductStock::where('product_id', $this->packageProduct->id)
            ->where('store_id', $newStore->id)->first();
        expect((float) $newStock->quantity)->toBe(-3.0)
            ->and($workshop->fresh()->store_id)->toBe($newStore->id);
    });

    test('update без смены склада корректирует остаток на дельту тары', function () {
        $service = new WorkshopService($this->mockSync);

        $workshop = $service->create(basePayload($this, [
            'product_store_id' => $this->store->id,
            'packages'         => [['product_id' => $this->packageProduct->id, 'quantity' => 3.0]],
        ]), false);

        // Меняем количество тары 3 → 5 (дельта +2 списывается).
        $service->update($workshop, basePayload($this, [
            'product_store_id' => $this->store->id,
            'packages'         => [['product_id' => $this->packageProduct->id, 'quantity' => 5.0]],
        ]), false);

        $stock = \App\Models\ProductStock::where('product_id', $this->packageProduct->id)
            ->where('store_id', $this->store->id)->first();
        expect((float) $stock->quantity)->toBe(-5.0);
    });

    test('удаление операции возвращает тару на склад', function () {
        $service = new WorkshopService($this->mockSync);
        $workshop = $service->create(basePayload($this, [
            'product_store_id' => $this->store->id,
            'packages'         => [['product_id' => $this->packageProduct->id, 'quantity' => 3.0]],
        ]), false);

        $workshop->delete();

        $stock = \App\Models\ProductStock::where('product_id', $this->packageProduct->id)
            ->where('store_id', $this->store->id)->first();
        expect((float) $stock->quantity)->toBe(0.0);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// WorkshopService::refreshItemCoeffs()
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkshopService::refreshItemCoeffs()', function () {

    test('пересчитывает worker_cost_per_m2 сырья после изменения настроек', function () {
        $service  = new WorkshopService($this->mockSync);
        $workshop = $service->create(basePayload($this), false);

        Setting::set('PACKAGING_PROD_COST', 200);
        Setting::set('PACKAGING_COST', 60);

        $service->refreshItemCoeffs($workshop);

        // 200 × 1.5 + 60 × 2.0 = 420
        expect((float) $workshop->rawItems()->first()->fresh()->worker_cost_per_m2)->toBe(420.0);
    });
});
