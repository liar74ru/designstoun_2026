<?php

use App\Models\Product;
use App\Models\Store;
use App\Models\Worker;
use App\Models\Workshop;
use App\Models\WorkshopItem;
use App\Services\Moysklad\WorkshopSyncService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// ══════════════════════════════════════════════════════════════════════════════
// WorkshopSyncService — обновление существующей техоперации цеха
// ══════════════════════════════════════════════════════════════════════════════

const WPU_BASE = 'https://api.moysklad.ru/api/remap/1.2';

beforeEach(function () {
    config()->set('services.moysklad.token', 'test-token');
    config()->set('services.moysklad.base_url', WPU_BASE);

    $this->packer   = Worker::create(['name' => 'Работник', 'position' => 'Мастер']);
    $this->receiver = Worker::create(['name' => 'Мастер', 'position' => 'Мастер']);
    $this->store    = Store::factory()->create();

    $this->raw     = Product::factory()->create(['sku' => '04-01-10', 'moysklad_id' => (string) Str::uuid()]);
    $this->package = Product::factory()->create(['sku' => '07-03-01', 'moysklad_id' => (string) Str::uuid()]);
    $this->out     = Product::factory()->create(['sku' => '05-01-01', 'moysklad_id' => (string) Str::uuid()]);

    $this->workshop = Workshop::create([
        'packer_id'              => $this->packer->id,
        'receiver_id'            => $this->receiver->id,
        'store_id'               => $this->store->id,
        'status'                 => Workshop::STATUS_ACTIVE,
        'moysklad_processing_id' => 'proc-ws',
    ]);
});

function wpuItem(Workshop $w, Product $p, string $role, float $qty, float $workerCost = 0): void
{
    WorkshopItem::create([
        'workshop_id'        => $w->id,
        'product_id'         => $p->id,
        'role'               => $role,
        'quantity'           => $qty,
        'worker_cost_per_m2' => $workerCost,
    ]);
}

/**
 * Фейк МойСклад: GET техоперации отдаёт позиции по секциям (expand=products|materials),
 * PUT — $putResponse. Склады отвечают href с их UUID.
 */
function wpuFake(array $existingProducts = [], array $existingMaterials = [], $putResponse = null): void
{
    Http::fake(function (Request $request) use ($existingProducts, $existingMaterials, $putResponse) {
        $url = $request->url();

        if (str_contains($url, '/report/stock/bystore')) {
            return Http::response(['rows' => []], 200);
        }
        if (preg_match('~/entity/(store|product)/([^/?]+)~', $url, $m)) {
            return Http::response(['meta' => [
                'href' => WPU_BASE . "/entity/{$m[1]}/{$m[2]}", 'type' => $m[1],
            ]], 200);
        }
        if (str_contains($url, '/entity/processing/') && $request->method() === 'GET') {
            $section = str_contains(urldecode($url), 'expand=materials') ? 'materials' : 'products';
            $map     = $section === 'materials' ? $existingMaterials : $existingProducts;
            $rows    = [];
            foreach ($map as $msId => $posId) {
                $rows[] = ['id' => $posId, 'assortment' => ['meta' => ['href' => WPU_BASE . '/entity/product/' . $msId]]];
            }
            return Http::response([$section => ['rows' => $rows]], 200);
        }
        if (str_contains($url, '/entity/processing/') && $request->method() === 'PUT') {
            return $putResponse ?? Http::response(['id' => 'proc-ws'], 200);
        }

        return Http::response(['errors' => [['error' => 'unexpected ' . $url]]], 500);
    });
}

function wpuSentPut(): ?Request
{
    $put = Http::recorded(fn (Request $r) => $r->method() === 'PUT')->first();

    return $put[0] ?? null;
}

function wpuPositions(array $rows): array
{
    return collect($rows)
        ->mapWithKeys(fn ($p) => [basename($p['assortment']['meta']['href']) => $p])
        ->all();
}

// ══════════════════════════════════════════════════════════════════════════════
// updateProcessingProducts()
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkshopSyncService::updateProcessingProducts()', function () {

    test('PUT: products = продукт, materials = сырьё + упаковка, quantity и описание', function () {
        wpuItem($this->workshop, $this->raw, WorkshopItem::ROLE_RAW, 5.0);
        wpuItem($this->workshop, $this->package, WorkshopItem::ROLE_PACKAGE, 2.0);
        wpuItem($this->workshop, $this->out, WorkshopItem::ROLE_PRODUCT, 4.0, 150.0);

        wpuFake();

        $result = app(WorkshopSyncService::class)
            ->updateProcessingProducts('proc-ws', $this->workshop->fresh(), 'Цех');

        expect($result)->toMatchArray(['success' => true, 'code' => '', 'message' => 'Техоперация обновлена']);

        $put = wpuSentPut();
        expect($put->url())->toBe(WPU_BASE . '/entity/processing/proc-ws');

        $data = $put->data();
        expect((float) $data['quantity'])->toBe(4.0);
        expect($data['description'])->toBe('Цех');
        // авто: 150 × 4 × 100 / 4
        expect($data['processingSum'])->toBe(15000);

        $products = wpuPositions($data['products']);
        expect(array_keys($products))->toBe([$this->out->moysklad_id]);
        expect((float) $products[$this->out->moysklad_id]['quantity'])->toBe(4.0);

        $materials = wpuPositions($data['materials']);
        expect($materials)->toHaveCount(2);
        expect((float) $materials[$this->raw->moysklad_id]['quantity'])->toBe(5.0);
        expect((float) $materials[$this->package->moysklad_id]['quantity'])->toBe(2.0);

        $storeHref = WPU_BASE . '/entity/store/' . $this->store->id;
        expect($data['productsStore']['meta']['href'])->toBe($storeHref);
        expect($data['materialsStore']['meta']['href'])->toBe($storeHref);
    });

    test('существующие позиции продуктов и материалов получают свои id', function () {
        wpuItem($this->workshop, $this->raw, WorkshopItem::ROLE_RAW, 5.0);
        wpuItem($this->workshop, $this->package, WorkshopItem::ROLE_PACKAGE, 1.0);
        wpuItem($this->workshop, $this->out, WorkshopItem::ROLE_PRODUCT, 4.0);

        wpuFake(
            [$this->out->moysklad_id => 'pos-out'],
            [$this->raw->moysklad_id => 'pos-raw'],
        );

        app(WorkshopSyncService::class)->updateProcessingProducts('proc-ws', $this->workshop->fresh());

        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && ($r->data()['expand'] ?? null) === 'products.assortment');
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && ($r->data()['expand'] ?? null) === 'materials.assortment');

        $data      = wpuSentPut()->data();
        $products  = wpuPositions($data['products']);
        $materials = wpuPositions($data['materials']);

        expect($products[$this->out->moysklad_id]['id'])->toBe('pos-out');
        expect($materials[$this->raw->moysklad_id]['id'])->toBe('pos-raw');
        expect($materials[$this->package->moysklad_id])->not->toHaveKey('id');
    });

    test('один товар как сырьё и как упаковка — одна позиция материалов с суммой', function () {
        wpuItem($this->workshop, $this->raw, WorkshopItem::ROLE_RAW, 5.0);
        wpuItem($this->workshop, $this->raw, WorkshopItem::ROLE_PACKAGE, 1.5);
        wpuItem($this->workshop, $this->out, WorkshopItem::ROLE_PRODUCT, 4.0);

        wpuFake();

        app(WorkshopSyncService::class)->updateProcessingProducts('proc-ws', $this->workshop->fresh());

        $materials = wpuSentPut()->data()['materials'];
        expect($materials)->toHaveCount(1);
        expect((float) $materials[0]['quantity'])->toBe(6.5);
    });

    test('ручной manual_processing_sum → processingSum = round(₽ × 100)', function () {
        $this->workshop->update(['manual_processing_sum' => 123.45]);
        wpuItem($this->workshop, $this->raw, WorkshopItem::ROLE_RAW, 5.0);
        wpuItem($this->workshop, $this->out, WorkshopItem::ROLE_PRODUCT, 4.0, 999.0);

        wpuFake();

        app(WorkshopSyncService::class)->updateProcessingProducts('proc-ws', $this->workshop->fresh());

        expect(wpuSentPut()->data()['processingSum'])->toBe(12345);
    });

    test('с product_store_id склады продукта и сырья различаются', function () {
        $productStore = Store::factory()->create();
        $this->workshop->update(['product_store_id' => $productStore->id]);
        wpuItem($this->workshop, $this->raw, WorkshopItem::ROLE_RAW, 5.0);
        wpuItem($this->workshop, $this->out, WorkshopItem::ROLE_PRODUCT, 4.0);

        wpuFake();

        app(WorkshopSyncService::class)->updateProcessingProducts('proc-ws', $this->workshop->fresh());

        $data = wpuSentPut()->data();
        expect($data['materialsStore']['meta']['href'])->toBe(WPU_BASE . '/entity/store/' . $this->store->id);
        expect($data['productsStore']['meta']['href'])->toBe(WPU_BASE . '/entity/store/' . $productStore->id);
    });

    test('без описания поле description не передаётся', function () {
        wpuItem($this->workshop, $this->out, WorkshopItem::ROLE_PRODUCT, 4.0);

        wpuFake();

        app(WorkshopSyncService::class)->updateProcessingProducts('proc-ws', $this->workshop->fresh());

        $data = wpuSentPut()->data();
        expect($data)->not->toHaveKey('description');
        expect($data['materials'])->toBe([]);
    });

    test('ошибка МойСклад → success = false, текст из errors[0].error', function () {
        wpuItem($this->workshop, $this->out, WorkshopItem::ROLE_PRODUCT, 4.0);

        wpuFake([], [], Http::response(['errors' => [['error' => 'Нельзя изменить проведённый документ']]], 412));

        $result = app(WorkshopSyncService::class)->updateProcessingProducts('proc-ws', $this->workshop->fresh());

        expect($result)->toMatchArray([
            'success' => false,
            'code'    => 'api_error',
            'message' => 'Ошибка МойСклад: Нельзя изменить проведённый документ',
        ]);
    });

    test('без продукта на выходе → exception, PUT не отправляется', function () {
        wpuItem($this->workshop, $this->raw, WorkshopItem::ROLE_RAW, 5.0);

        wpuFake();

        $result = app(WorkshopSyncService::class)->updateProcessingProducts('proc-ws', $this->workshop->fresh());

        expect($result['code'])->toBe('exception');
        expect($result['message'])->toBe('Ошибка: Нет продуктов на выходе с moysklad_id для обновления техоперации');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
    });

    test('склад сырья не найден → exception', function () {
        wpuItem($this->workshop, $this->out, WorkshopItem::ROLE_PRODUCT, 4.0);

        Http::fake(['*' => Http::response(['errors' => [['error' => 'nope']]], 404)]);

        $result = app(WorkshopSyncService::class)->updateProcessingProducts('proc-ws', $this->workshop->fresh());

        expect($result['message'])->toBe('Ошибка: Не удалось получить данные склада сырья');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
    });

    test('без токена → exception, запросы не уходят', function () {
        config()->set('services.moysklad.token', '');
        Http::fake();

        $result = app(WorkshopSyncService::class)->updateProcessingProducts('proc-ws', $this->workshop->fresh());

        expect($result['success'])->toBeFalse();
        expect($result['message'])->toBe('Ошибка: MoySklad токен не установлен');
        Http::assertNothingSent();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// syncWorkshop() — повторная синхронизация
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkshopSyncService::syncWorkshop() — повторная синхронизация', function () {

    test('операция с техоперацией обновляется через PUT и помечается синхронизированной', function () {
        $this->workshop->update([
            'moysklad_processing_name' => 'ЦЕХ-01',
            'moysklad_sync_status'     => Workshop::SYNC_STATUS_NOT_SYNCED,
            'moysklad_sync_error'      => 'старая ошибка',
        ]);
        wpuItem($this->workshop, $this->raw, WorkshopItem::ROLE_RAW, 5.0);
        wpuItem($this->workshop, $this->out, WorkshopItem::ROLE_PRODUCT, 4.0);

        wpuFake();

        app(WorkshopSyncService::class)->syncWorkshop($this->workshop->fresh());

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
        expect(wpuSentPut()->data()['description'])->toBe('Цех');

        $fresh = $this->workshop->fresh();
        expect($fresh->isSynced())->toBeTrue();
        expect($fresh->moysklad_sync_error)->toBeNull();
        expect($fresh->moysklad_processing_id)->toBe('proc-ws');
        expect($fresh->moysklad_processing_name)->toBe('ЦЕХ-01');

        expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'report/stock/bystore')))->toHaveCount(2);
    });

    test('ошибка обновления → markSyncError с текстом, остатки не дергаются', function () {
        wpuItem($this->workshop, $this->out, WorkshopItem::ROLE_PRODUCT, 4.0);

        wpuFake([], [], Http::response(['errors' => [['error' => 'boom']]], 500));

        app(WorkshopSyncService::class)->syncWorkshop($this->workshop->fresh());

        $fresh = $this->workshop->fresh();
        expect($fresh->moysklad_sync_status)->toBe(Workshop::SYNC_STATUS_NOT_SYNCED);
        expect($fresh->moysklad_sync_error)->toBe('Ошибка МойСклад: boom');
        expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'report/stock/bystore')))->toHaveCount(0);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// getProcessing()
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkshopSyncService::getProcessing()', function () {

    test('возвращает тело техоперации', function () {
        Http::fake(['*entity/processing/proc-ws' => Http::response(['id' => 'proc-ws', 'name' => 'ЦЕХ-01'], 200)]);

        expect(app(WorkshopSyncService::class)->getProcessing('proc-ws'))
            ->toBe(['id' => 'proc-ws', 'name' => 'ЦЕХ-01']);

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->url() === WPU_BASE . '/entity/processing/proc-ws');
    });

    test('ошибка МойСклад → null', function () {
        Http::fake(['*' => Http::response(['errors' => [['error' => 'Не найдено']]], 404)]);

        expect(app(WorkshopSyncService::class)->getProcessing('proc-ws'))->toBeNull();
    });

    test('без токена → null, запрос не уходит', function () {
        config()->set('services.moysklad.token', '');
        Http::fake();

        expect(app(WorkshopSyncService::class)->getProcessing('proc-ws'))->toBeNull();
        Http::assertNothingSent();
    });
});
