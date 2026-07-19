<?php

use App\Models\Workshop;
use App\Models\WorkshopItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\Worker;
use App\Services\Moysklad\WorkshopSyncService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('services.moysklad.token', 'test-token');
    config()->set('services.moysklad.base_url', 'https://api.moysklad.ru/api/remap/1.2');

    $this->packer   = Worker::create(['name' => 'Работник', 'position' => 'Мастер']);
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
    $this->outProduct = Product::factory()->create([
        'sku'         => '05-01-01',
        'moysklad_id' => (string) Str::uuid(),
    ]);

    $this->workshop = Workshop::create([
        'packer_id'   => $this->packer->id,
        'receiver_id' => $this->receiver->id,
        'store_id'    => $this->store->id,
        'status'      => Workshop::STATUS_ACTIVE,
    ]);
});

function wsItem(Workshop $w, Product $p, string $role, float $qty, float $workerCost = 0): void {
    WorkshopItem::create([
        'workshop_id'        => $w->id,
        'product_id'         => $p->id,
        'role'               => $role,
        'quantity'           => $qty,
        'worker_cost_per_m2' => $workerCost,
    ]);
}

function fakeMoyskladForWorkshop(): void {
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
        '*entity/processing'             => Http::response(['id' => 'proc-id', 'name' => 'ЦЕХ-1'], 200),
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// createProcessingForWorkshop() — materials/products/processingSum
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkshopSyncService::createProcessingForWorkshop()', function () {

    test('materials = сырьё + упаковка, products = продукт на выходе', function () {
        wsItem($this->workshop, $this->product, WorkshopItem::ROLE_RAW, 5.0, 210.0);
        wsItem($this->workshop, $this->packageProduct, WorkshopItem::ROLE_PACKAGE, 1.0);
        wsItem($this->workshop, $this->outProduct, WorkshopItem::ROLE_PRODUCT, 5.0);

        fakeMoyskladForWorkshop();

        $result = app(WorkshopSyncService::class)->createProcessingForWorkshop($this->workshop);
        expect($result['success'])->toBeTrue();

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || !str_ends_with($request->url(), '/entity/processing')) {
                return false;
            }
            $data = $request->data();
            return count($data['products']) === 1
                && (float) $data['products'][0]['quantity'] === 5.0
                && (float) $data['quantity'] === 5.0
                && count($data['materials']) === 2; // сырьё + тара
        });
    });

    test('автоматический processingSum = round(зарплата продукта × 100 / кол-во продукта)', function () {
        wsItem($this->workshop, $this->product, WorkshopItem::ROLE_RAW, 5.0);
        wsItem($this->workshop, $this->packageProduct, WorkshopItem::ROLE_PACKAGE, 1.0);
        wsItem($this->workshop, $this->outProduct, WorkshopItem::ROLE_PRODUCT, 5.0, 210.0);

        fakeMoyskladForWorkshop();
        app(WorkshopSyncService::class)->createProcessingForWorkshop($this->workshop);

        // зарплата = 210 × 5 = 1050; копейки/ед = round(1050 × 100 / 5) = 21000
        Http::assertSent(fn($r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/entity/processing')
            && ($r->data()['processingSum'] ?? null) === 21000);
    });

    test('зарплата считается только по продукту (сырьё/упаковка не участвуют)', function () {
        wsItem($this->workshop, $this->product, WorkshopItem::ROLE_RAW, 10.0, 999.0);
        wsItem($this->workshop, $this->packageProduct, WorkshopItem::ROLE_PACKAGE, 1.0, 999.0);
        wsItem($this->workshop, $this->outProduct, WorkshopItem::ROLE_PRODUCT, 10.0, 0.0);

        fakeMoyskladForWorkshop();
        app(WorkshopSyncService::class)->createProcessingForWorkshop($this->workshop);

        Http::assertSent(fn($r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/entity/processing')
            && ($r->data()['processingSum'] ?? null) === 0);
    });

    test('ручной manual_processing_sum → processingSum = round(₽ × 100)', function () {
        $this->workshop->update(['manual_processing_sum' => 12.5]);
        wsItem($this->workshop, $this->product, WorkshopItem::ROLE_RAW, 5.0, 210.0);
        wsItem($this->workshop, $this->outProduct, WorkshopItem::ROLE_PRODUCT, 7.0);

        fakeMoyskladForWorkshop();
        app(WorkshopSyncService::class)->createProcessingForWorkshop($this->workshop->fresh());

        // ручные затраты игнорируют делитель: round(12.5 × 100) = 1250
        Http::assertSent(fn($r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/entity/processing')
            && ($r->data()['processingSum'] ?? null) === 1250);
    });

    test('несколько продуктов на выходе суммируются в quantity', function () {
        $extra = Product::factory()->create(['sku' => '05-02-02', 'moysklad_id' => (string) Str::uuid()]);
        wsItem($this->workshop, $this->product, WorkshopItem::ROLE_RAW, 5.0, 210.0);
        wsItem($this->workshop, $this->outProduct, WorkshopItem::ROLE_PRODUCT, 3.0);
        wsItem($this->workshop, $extra, WorkshopItem::ROLE_PRODUCT, 4.0);

        fakeMoyskladForWorkshop();
        app(WorkshopSyncService::class)->createProcessingForWorkshop($this->workshop);

        Http::assertSent(function ($r) {
            if ($r->method() !== 'POST' || !str_ends_with($r->url(), '/entity/processing')) return false;
            $data = $r->data();
            return count($data['products']) === 2 && (float) $data['quantity'] === 7.0;
        });
    });

    test('без продуктов на выходе → success = false', function () {
        wsItem($this->workshop, $this->product, WorkshopItem::ROLE_RAW, 5.0, 210.0);

        fakeMoyskladForWorkshop();
        $result = app(WorkshopSyncService::class)->createProcessingForWorkshop($this->workshop);

        expect($result['success'])->toBeFalse()
            ->and($result['message'])->toContain('продукт');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Склады техоперации: materialsStore / productsStore
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkshopSyncService: склады техоперации', function () {

    test('с product_store_id склады в payload различаются', function () {
        $productStore = Store::factory()->create();
        $this->workshop->update(['product_store_id' => $productStore->id]);
        wsItem($this->workshop, $this->product, WorkshopItem::ROLE_RAW, 5.0, 210.0);
        wsItem($this->workshop, $this->outProduct, WorkshopItem::ROLE_PRODUCT, 5.0);

        Http::fake([
            '*report/stock/bystore*'                => Http::response(['rows' => []], 200),
            '*entity/processing/metadata*'          => Http::response(['states' => []], 200),
            '*entity/organization*'                 => Http::response([
                'rows' => [['meta' => ['href' => 'org-href', 'type' => 'organization', 'mediaType' => 'application/json']]],
            ], 200),
            "*entity/store/{$this->store->id}*"     => Http::response([
                'meta' => ['href' => 'materials-store-href', 'type' => 'store', 'mediaType' => 'application/json'],
            ], 200),
            "*entity/store/{$productStore->id}*"    => Http::response([
                'meta' => ['href' => 'products-store-href', 'type' => 'store', 'mediaType' => 'application/json'],
            ], 200),
            '*entity/product/*'                     => Http::response([
                'meta' => ['href' => 'product-href', 'type' => 'product', 'mediaType' => 'application/json'],
            ], 200),
            '*entity/processing'                    => Http::response(['id' => 'proc-id', 'name' => 'ЦЕХ-1'], 200),
        ]);

        $result = app(WorkshopSyncService::class)->createProcessingForWorkshop($this->workshop->fresh());
        expect($result['success'])->toBeTrue();

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || !str_ends_with($request->url(), '/entity/processing')) {
                return false;
            }
            $data = $request->data();
            return ($data['materialsStore']['meta']['href'] ?? null) === 'materials-store-href'
                && ($data['productsStore']['meta']['href'] ?? null) === 'products-store-href';
        });
    });

    test('без product_store_id оба склада — store_id (фолбэк)', function () {
        expect($this->workshop->product_store_id)->toBeNull();
        wsItem($this->workshop, $this->product, WorkshopItem::ROLE_RAW, 5.0, 210.0);
        wsItem($this->workshop, $this->outProduct, WorkshopItem::ROLE_PRODUCT, 5.0);

        fakeMoyskladForWorkshop();

        $result = app(WorkshopSyncService::class)->createProcessingForWorkshop($this->workshop->fresh());
        expect($result['success'])->toBeTrue();

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || !str_ends_with($request->url(), '/entity/processing')) {
                return false;
            }
            $data = $request->data();
            return ($data['materialsStore']['meta']['href'] ?? null) === 'store-href'
                && ($data['productsStore']['meta']['href'] ?? null) === 'store-href';
        });
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Нумерация имён ЦЕХ
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkshopSyncService: нумерация ЦЕХ', function () {

    function makeNamedWorkshop($ctx, string $name): Workshop {
        return Workshop::create([
            'packer_id'                => $ctx->packer->id,
            'receiver_id'              => $ctx->receiver->id,
            'store_id'                 => $ctx->store->id,
            'status'                   => Workshop::STATUS_ACTIVE,
            'moysklad_processing_name' => $name,
        ]);
    }

    function assertSentProcessingName(string $expected): void {
        Http::assertSent(function ($request) use ($expected) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/entity/processing')
                && ($request->data()['name'] ?? null) === $expected;
        });
    }

    beforeEach(function () {
        wsItem($this->workshop, $this->product, WorkshopItem::ROLE_RAW, 5.0, 210.0);
        wsItem($this->workshop, $this->outProduct, WorkshopItem::ROLE_PRODUCT, 5.0);
    });

    test('первая операция недели получает номер 01', function () {
        fakeMoyskladForWorkshop();
        app(WorkshopSyncService::class)->createProcessingForWorkshop($this->workshop->fresh());
        assertSentProcessingName(\App\Support\DocumentNaming::weeklyName('ЦЕХ', 1));
    });

    test('следующая операция недели получает max NN + 1', function () {
        makeNamedWorkshop($this, \App\Support\DocumentNaming::weeklyName('ЦЕХ', 1));
        fakeMoyskladForWorkshop();
        app(WorkshopSyncService::class)->createProcessingForWorkshop($this->workshop->fresh());
        assertSentProcessingName(\App\Support\DocumentNaming::weeklyName('ЦЕХ', 2));
    });

    test('имена чужих недель не влияют на счётчик', function () {
        makeNamedWorkshop($this, \App\Support\DocumentNaming::weeklyName('ЦЕХ', 7, now()->subWeeks(2)));
        fakeMoyskladForWorkshop();
        app(WorkshopSyncService::class)->createProcessingForWorkshop($this->workshop->fresh());
        assertSentProcessingName(\App\Support\DocumentNaming::weeklyName('ЦЕХ', 1));
    });

    test('имя с суффиксом коллизии учитывается по базовому NN', function () {
        makeNamedWorkshop($this, \App\Support\DocumentNaming::weeklyName('ЦЕХ', 2) . '_01');
        fakeMoyskladForWorkshop();
        app(WorkshopSyncService::class)->createProcessingForWorkshop($this->workshop->fresh());
        assertSentProcessingName(\App\Support\DocumentNaming::weeklyName('ЦЕХ', 3));
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// syncWorkshop(): актуализация остатков после успешного синка
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkshopSyncService::syncWorkshop() — остатки', function () {

    test('после успешного создания дергает report/stock/bystore для сырья, тары и продукта', function () {
        wsItem($this->workshop, $this->product, WorkshopItem::ROLE_RAW, 5.0, 210.0);
        wsItem($this->workshop, $this->packageProduct, WorkshopItem::ROLE_PACKAGE, 1.0);
        wsItem($this->workshop, $this->outProduct, WorkshopItem::ROLE_PRODUCT, 5.0);

        fakeMoyskladForWorkshop();

        app(WorkshopSyncService::class)->syncWorkshop($this->workshop->fresh());

        expect($this->workshop->fresh()->isSynced())->toBeTrue();

        $stockRequests = Http::recorded(
            fn($request) => str_contains($request->url(), 'report/stock/bystore')
        );
        // сырьё + тара + продукт = 3 уникальных moysklad_id
        expect($stockRequests)->toHaveCount(3);
    });

    test('без упаковки остатки дергаются для сырья и продукта', function () {
        wsItem($this->workshop, $this->product, WorkshopItem::ROLE_RAW, 5.0, 210.0);
        wsItem($this->workshop, $this->outProduct, WorkshopItem::ROLE_PRODUCT, 5.0);

        fakeMoyskladForWorkshop();

        app(WorkshopSyncService::class)->syncWorkshop($this->workshop->fresh());

        $stockRequests = Http::recorded(
            fn($request) => str_contains($request->url(), 'report/stock/bystore')
        );
        expect($stockRequests)->toHaveCount(2);
    });

    test('при ошибке создания техоперации остатки не дергаются', function () {
        wsItem($this->workshop, $this->product, WorkshopItem::ROLE_RAW, 5.0, 210.0);
        wsItem($this->workshop, $this->outProduct, WorkshopItem::ROLE_PRODUCT, 5.0);

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

        app(WorkshopSyncService::class)->syncWorkshop($this->workshop->fresh());

        expect($this->workshop->fresh()->hasSyncError())->toBeTrue();

        $stockRequests = Http::recorded(
            fn($request) => str_contains($request->url(), 'report/stock/bystore')
        );
        expect($stockRequests)->toHaveCount(0);
    });
});
