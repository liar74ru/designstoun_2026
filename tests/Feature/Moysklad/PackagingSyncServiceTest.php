<?php

use App\Models\Packaging;
use App\Models\PackagingItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Worker;
use App\Services\Moysklad\PackagingSyncService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('services.moysklad.token', 'test-token');
    config()->set('services.moysklad.base_url', 'https://api.moysklad.ru/api/remap/1.2');

    Setting::set('PACKAGING_PROD_COST', 100);
    Setting::set('PACKAGING_COST', 30);

    $this->packer   = Worker::create(['name' => 'Упаковщик', 'positions' => ['Упаковщик']]);
    $this->receiver = Worker::create(['name' => 'Мастер',    'positions' => ['Мастер']]);
    $this->store    = Store::factory()->create();

    $this->product = Product::factory()->create([
        'sku'             => '04-01-10',
        'prod_cost_coeff' => 1.5,
        'moysklad_id'     => (string) Str::uuid(),
    ]);
    $this->packageProduct = Product::factory()->create([
        'sku'             => '07-03-01',
        'prod_cost_coeff' => 2.0,
        'moysklad_id'     => (string) Str::uuid(),
    ]);

    $this->packaging = Packaging::create([
        'packer_id'          => $this->packer->id,
        'receiver_id'        => $this->receiver->id,
        'store_id'           => $this->store->id,
        'package_product_id' => $this->packageProduct->id,
        'package_quantity'   => 1.0,
        'status'             => Packaging::STATUS_ACTIVE,
    ]);
});

function fakeMoyskladForPackaging(): void {
    Http::fake([
        '*entity/processing/metadata*'   => Http::response(['states' => []], 200),
        '*entity/organization*'          => Http::response([
            'rows' => [['meta' => ['href' => 'org-href', 'type' => 'organization', 'mediaType' => 'application/json']]],
        ], 200),
        '*entity/store/*'                => Http::response([
            'meta' => ['href' => 'store-href', 'type' => 'store', 'mediaType' => 'application/json'],
        ], 200),
        '*entity/product/*'              => Http::response([
            'meta' => ['href' => 'product-href', 'type' => 'product', 'mediaType' => 'application/json'],
        ], 200),
        '*entity/processing'             => Http::response(['id' => 'proc-id', 'name' => 'УПАК-1'], 200),
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// PackagingSyncService::createProcessingForPackaging() — processingSum
// ══════════════════════════════════════════════════════════════════════════════

describe('PackagingSyncService::createProcessingForPackaging()', function () {

    test('processingSum = round(workerSalaryTotal × 100 / totalQty), без накладных PACKAGING_COST × qty', function () {
        // worker_cost_per_m2 = 100 × 1.5 + 30 × 2.0 = 210 руб/м²
        PackagingItem::create([
            'packaging_id'         => $this->packaging->id,
            'product_id'           => $this->product->id,
            'quantity'             => 5.0,
            'effective_cost_coeff' => 1.5,
            'worker_cost_per_m2'   => 210.0,
            'master_cost_per_m2'   => 100.0,
        ]);

        fakeMoyskladForPackaging();

        $service = new PackagingSyncService();
        $result  = $service->createProcessingForPackaging($this->packaging);

        expect($result['success'])->toBeTrue();

        // workerSalaryTotal = 210 × 5 = 1050 руб
        // processingSum копейки/ед = round(1050 × 100 / 5) = 21000
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/entity/processing')
                && ($request->data()['processingSum'] ?? null) === 21000;
        });
    });

    test('processingSum НЕ дублирует PACKAGING_COST как накладные', function () {
        // worker_cost_per_m2 = 0 (имитируем, что коэффициенты = 0)
        PackagingItem::create([
            'packaging_id'         => $this->packaging->id,
            'product_id'           => $this->product->id,
            'quantity'             => 10.0,
            'effective_cost_coeff' => 0,
            'worker_cost_per_m2'   => 0.0,
            'master_cost_per_m2'   => 0.0,
        ]);

        fakeMoyskladForPackaging();

        $service = new PackagingSyncService();
        $service->createProcessingForPackaging($this->packaging);

        // Без накладных PACKAGING_COST × totalQty: processingSum должен быть 0,
        // а не 30 × 100 = 3000 как в старой формуле.
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/entity/processing')
                && ($request->data()['processingSum'] ?? null) === 0;
        });
    });
});
