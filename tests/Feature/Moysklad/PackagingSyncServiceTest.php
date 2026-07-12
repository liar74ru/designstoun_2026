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

    $this->packer   = Worker::create(['name' => 'Упаковщик', 'position' => 'Мастер']);
    $this->receiver = Worker::create(['name' => 'Мастер',    'position' => 'Мастер']);
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
        '*report/stock/bystore*'         => Http::response(['rows' => []], 200),
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

        $service = app(PackagingSyncService::class);
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

        $service = app(PackagingSyncService::class);
        $service->createProcessingForPackaging($this->packaging);

        // Без накладных PACKAGING_COST × totalQty: processingSum должен быть 0,
        // а не 30 × 100 = 3000 как в старой формуле.
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/entity/processing')
                && ($request->data()['processingSum'] ?? null) === 0;
        });
    });

    test('регрессия: без result_product_id продукты и quantity — по позициям упаковки', function () {
        PackagingItem::create([
            'packaging_id'       => $this->packaging->id,
            'product_id'         => $this->product->id,
            'quantity'           => 5.0,
            'worker_cost_per_m2' => 210.0,
        ]);

        fakeMoyskladForPackaging();

        $result = app(PackagingSyncService::class)->createProcessingForPackaging($this->packaging);
        expect($result['success'])->toBeTrue();

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || !str_ends_with($request->url(), '/entity/processing')) {
                return false;
            }
            $data = $request->data();
            return count($data['products']) === 1
                && (float) $data['products'][0]['quantity'] === 5.0
                && (float) $data['quantity'] === 5.0
                && count($data['materials']) === 2; // продукт + тара
        });
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Режим товара-результата (result_product_id)
// ══════════════════════════════════════════════════════════════════════════════

describe('PackagingSyncService: товар-результат', function () {

    beforeEach(function () {
        $this->resultProduct = Product::factory()->create([
            'sku'         => '05-01-01',
            'moysklad_id' => (string) Str::uuid(),
        ]);
    });

    test('с result_product_id: products = товар-результат (кол-во = тара), materials = продукты + тара', function () {
        $this->packaging->update([
            'result_product_id' => $this->resultProduct->id,
            'package_quantity'  => 3.0,
        ]);
        PackagingItem::create([
            'packaging_id'       => $this->packaging->id,
            'product_id'         => $this->product->id,
            'quantity'           => 5.0,
            'worker_cost_per_m2' => 210.0,
        ]);

        fakeMoyskladForPackaging();

        $result = app(PackagingSyncService::class)->createProcessingForPackaging($this->packaging->fresh());
        expect($result['success'])->toBeTrue();

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || !str_ends_with($request->url(), '/entity/processing')) {
                return false;
            }
            $data = $request->data();
            $materialQtys = array_map(fn($m) => (float) $m['quantity'], $data['materials']);
            sort($materialQtys);
            return count($data['products']) === 1
                && (float) $data['products'][0]['quantity'] === 3.0   // кол-во результата = кол-ву тары
                && (float) $data['quantity'] === 3.0
                && $materialQtys === [3.0, 5.0];                      // тара 3 шт + продукт 5 м²
        });
    });

    test('с result_product_id: делитель processingSum — package_quantity', function () {
        $this->packaging->update([
            'result_product_id' => $this->resultProduct->id,
            'package_quantity'  => 3.0,
        ]);
        PackagingItem::create([
            'packaging_id'       => $this->packaging->id,
            'product_id'         => $this->product->id,
            'quantity'           => 5.0,
            'worker_cost_per_m2' => 210.0,
        ]);

        fakeMoyskladForPackaging();

        app(PackagingSyncService::class)->createProcessingForPackaging($this->packaging->fresh());

        // workerSalaryTotal = 210 × 5 = 1050 руб; копейки/ед = round(1050 × 100 / 3) = 35000
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/entity/processing')
                && ($request->data()['processingSum'] ?? null) === 35000;
        });
    });

    test('result-товар без moysklad_id → success = false', function () {
        $this->resultProduct->update(['moysklad_id' => null]);
        $this->packaging->update(['result_product_id' => $this->resultProduct->id]);
        PackagingItem::create([
            'packaging_id'       => $this->packaging->id,
            'product_id'         => $this->product->id,
            'quantity'           => 5.0,
            'worker_cost_per_m2' => 210.0,
        ]);

        fakeMoyskladForPackaging();

        $result = app(PackagingSyncService::class)->createProcessingForPackaging($this->packaging->fresh());

        expect($result['success'])->toBeFalse()
            ->and($result['message'])->toContain('Товар-результат');
    });

    test('result-режим с нулевой тарой → success = false', function () {
        $this->packaging->update([
            'result_product_id' => $this->resultProduct->id,
            'package_quantity'  => 0,
        ]);
        PackagingItem::create([
            'packaging_id'       => $this->packaging->id,
            'product_id'         => $this->product->id,
            'quantity'           => 5.0,
            'worker_cost_per_m2' => 210.0,
        ]);

        fakeMoyskladForPackaging();

        $result = app(PackagingSyncService::class)->createProcessingForPackaging($this->packaging->fresh());

        expect($result['success'])->toBeFalse()
            ->and($result['message'])->toContain('количество тары');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// syncPackaging(): актуализация остатков после успешного синка
// ══════════════════════════════════════════════════════════════════════════════

describe('PackagingSyncService::syncPackaging() — остатки', function () {

    test('после успешного создания дергает report/stock/bystore для продуктов, тары и результата', function () {
        $resultProduct = Product::factory()->create([
            'sku'         => '05-01-01',
            'moysklad_id' => (string) Str::uuid(),
        ]);
        $this->packaging->update(['result_product_id' => $resultProduct->id]);
        PackagingItem::create([
            'packaging_id'       => $this->packaging->id,
            'product_id'         => $this->product->id,
            'quantity'           => 5.0,
            'worker_cost_per_m2' => 210.0,
        ]);

        fakeMoyskladForPackaging();

        app(PackagingSyncService::class)->syncPackaging($this->packaging->fresh());

        expect($this->packaging->fresh()->isSynced())->toBeTrue();

        $stockRequests = Http::recorded(
            fn($request) => str_contains($request->url(), 'report/stock/bystore')
        );
        // продукт + тара + товар-результат = 3 уникальных moysklad_id
        expect($stockRequests)->toHaveCount(3);
    });

    test('без result_product_id остатки дергаются для продукта и тары', function () {
        PackagingItem::create([
            'packaging_id'       => $this->packaging->id,
            'product_id'         => $this->product->id,
            'quantity'           => 5.0,
            'worker_cost_per_m2' => 210.0,
        ]);

        fakeMoyskladForPackaging();

        app(PackagingSyncService::class)->syncPackaging($this->packaging->fresh());

        $stockRequests = Http::recorded(
            fn($request) => str_contains($request->url(), 'report/stock/bystore')
        );
        expect($stockRequests)->toHaveCount(2);
    });

    test('при ошибке создания техоперации остатки не дергаются', function () {
        PackagingItem::create([
            'packaging_id'       => $this->packaging->id,
            'product_id'         => $this->product->id,
            'quantity'           => 5.0,
            'worker_cost_per_m2' => 210.0,
        ]);

        Http::fake([
            '*report/stock/bystore*'       => Http::response(['rows' => []], 200),
            '*entity/processing/metadata*' => Http::response(['states' => []], 200),
            '*entity/organization*'        => Http::response([
                'rows' => [['meta' => ['href' => 'org-href', 'type' => 'organization', 'mediaType' => 'application/json']]],
            ], 200),
            '*entity/store/*'              => Http::response([
                'meta' => ['href' => 'store-href', 'type' => 'store', 'mediaType' => 'application/json'],
            ], 200),
            '*entity/product/*'            => Http::response([
                'meta' => ['href' => 'product-href', 'type' => 'product', 'mediaType' => 'application/json'],
            ], 200),
            '*entity/processing'           => Http::response(['errors' => [['error' => 'boom']]], 500),
        ]);

        app(PackagingSyncService::class)->syncPackaging($this->packaging->fresh());

        expect($this->packaging->fresh()->hasSyncError())->toBeTrue();

        $stockRequests = Http::recorded(
            fn($request) => str_contains($request->url(), 'report/stock/bystore')
        );
        expect($stockRequests)->toHaveCount(0);
    });
});
