<?php

use App\Models\Department;
use App\Models\DepartmentExpense;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use App\Services\Moysklad\StoneReceptionSyncService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Helpers\ReceptionTestHelper as H;

// ══════════════════════════════════════════════════════════════════════════════
// StoneReceptionSyncService — повторная синхронизация (обновление техоперации)
// ══════════════════════════════════════════════════════════════════════════════

const SRPU_BASE = 'https://api.moysklad.ru/api/remap/1.2';

beforeEach(function () {
    Cache::flush();
    config()->set('services.moysklad.token', 'test-token');
    config()->set('services.moysklad.base_url', SRPU_BASE);

    $this->dept = Department::create(['name' => 'Отдел резки', 'is_active' => true]);
    DepartmentExpense::insert([
        ['department_id' => $this->dept->id, 'name' => 'Расход пилы', 'amount' => 100],
    ]);

    $this->store    = H::store();
    $this->receiver = H::worker('Приёмщик');
    $this->cutter   = H::cutter();

    $this->rawProduct = H::product(['name' => 'Сырьё', 'sku' => '01-01-01', 'moysklad_id' => (string) Str::uuid()]);
    $this->productA   = H::product(['name' => 'Плитка А', 'sku' => '04-01-10', 'moysklad_id' => (string) Str::uuid()]);
    $this->productB   = H::product(['name' => 'Плитка Б', 'sku' => '04-01-11', 'moysklad_id' => (string) Str::uuid()]);

    $this->batch     = H::batch($this->rawProduct, $this->store, $this->cutter, 100.0);
    $this->reception = H::reception($this->batch, $this->receiver, $this->cutter, $this->store, 7.5, [
        'department_id' => $this->dept->id,
    ]);
});

function srpuItem(StoneReception $reception, $product, float $qty, float $worker = 0, float $master = 0): StoneReceptionItem
{
    return StoneReceptionItem::create([
        'stone_reception_id' => $reception->id,
        'product_id'         => $product->id,
        'quantity'           => $qty,
        'worker_cost_per_m2' => $worker,
        'master_cost_per_m2' => $master,
    ]);
}

/**
 * Фейк МойСклад на замыкании: GET и PUT по одному URL техоперации различаются
 * методом, что паттернами Http::fake() не выразить.
 *
 * @param array $existingPositions  moysklad_id товара → id позиции в техоперации
 */
function srpuFake(array $existingPositions = [], $putResponse = null, bool $existingOk = true): void
{
    Http::fake(function (Request $request) use ($existingPositions, $putResponse, $existingOk) {
        $url = $request->url();

        if (str_contains($url, '/report/stock/bystore')) {
            return Http::response(['rows' => []], 200);
        }
        if (str_contains($url, '/entity/store/')) {
            return Http::response(['meta' => ['href' => 'store-href', 'type' => 'store']], 200);
        }
        if (preg_match('~/entity/product/([^/?]+)~', $url, $m)) {
            return Http::response(['meta' => [
                'href' => SRPU_BASE . '/entity/product/' . $m[1], 'type' => 'product',
            ]], 200);
        }
        if (str_contains($url, '/entity/processing/') && $request->method() === 'GET') {
            if (!$existingOk) {
                return Http::response(['errors' => [['error' => 'Не найдено']]], 404);
            }
            $rows = [];
            foreach ($existingPositions as $msId => $posId) {
                $rows[] = [
                    'id'         => $posId,
                    'assortment' => ['meta' => ['href' => SRPU_BASE . '/entity/product/' . $msId]],
                ];
            }
            return Http::response(['id' => 'proc-1', 'products' => ['rows' => $rows]], 200);
        }
        if (str_contains($url, '/entity/processing/') && $request->method() === 'PUT') {
            return $putResponse ?? Http::response(['id' => 'proc-1'], 200);
        }

        return Http::response(['errors' => [['error' => 'unexpected ' . $url]]], 500);
    });
}

function srpuSentPut(): ?Request
{
    $put = Http::recorded(fn (Request $r) => $r->method() === 'PUT')->first();

    return $put[0] ?? null;
}

function srpuStockIds(): array
{
    return Http::recorded(fn (Request $r) => str_contains($r->url(), 'report/stock/bystore'))
        ->map(fn ($pair) => basename(explode(';', urldecode($pair[0]->url()))[0]))
        ->sort()->values()->all();
}

// ══════════════════════════════════════════════════════════════════════════════
// updateProcessingProducts()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionSyncService::updateProcessingProducts()', function () {

    test('PUT на техоперацию: продукты сгруппированы, склад, количество, материал и описание', function () {
        // Один товар в двух приёмках партии — в техоперации одна позиция с суммой
        $second = H::reception($this->batch, $this->receiver, $this->cutter, $this->store, 1.0);
        srpuItem($this->reception, $this->productA, 2.0);
        srpuItem($second, $this->productA, 1.0);
        srpuItem($this->reception, $this->productB, 1.5);

        srpuFake();

        $items = StoneReceptionItem::with('product')->get();

        $result = app(StoneReceptionSyncService::class)->updateProcessingProducts(
            'proc-1',
            $items,
            $this->store->id,
            7.5,
            $this->rawProduct->moysklad_id,
            'Описание',
            $this->dept->id,
        );

        expect($result)->toMatchArray(['success' => true, 'code' => '', 'message' => 'Техоперация обновлена']);

        $put = srpuSentPut();
        expect($put)->not->toBeNull();
        expect($put->url())->toBe(SRPU_BASE . '/entity/processing/proc-1');

        $data = $put->data();
        expect($data['productsStore']['meta']['href'])->toBe('store-href');
        expect($data['materialsStore']['meta']['href'])->toBe('store-href');
        expect((float) $data['quantity'])->toBe(4.5);
        expect($data['description'])->toBe('Описание');

        $byHref = collect($data['products'])->mapWithKeys(fn ($p) => [basename($p['assortment']['meta']['href']) => (float) $p['quantity']]);
        expect($byHref->all())->toEqual([
            $this->productA->moysklad_id => 3.0,
            $this->productB->moysklad_id => 1.5,
        ]);

        expect($data['materials'])->toHaveCount(1);
        expect((float) $data['materials'][0]['quantity'])->toBe(7.5);
        expect(basename($data['materials'][0]['assortment']['meta']['href']))->toBe($this->rawProduct->moysklad_id);
    });

    test('processingSum = (зарплата пильщика + мастера + накладные × кол-во) × 100 / кол-во', function () {
        srpuItem($this->reception, $this->productA, 2.0, 300.0, 50.0);
        srpuItem($this->reception, $this->productB, 3.0, 200.0, 40.0);

        srpuFake();

        app(StoneReceptionSyncService::class)->updateProcessingProducts(
            'proc-1',
            $this->reception->fresh()->load('items.product')->items,
            $this->store->id,
            null, null, null,
            $this->dept->id,
        );

        // пильщик: 600 + 600 = 1200; мастер: 100 + 120 = 220; накладные: 100 × 5 = 500
        // (1200 + 220 + 500) × 100 / 5 = 38400
        expect(srpuSentPut()->data()['processingSum'])->toBe(38400);
    });

    test('без отдела накладные не добавляются', function () {
        srpuItem($this->reception, $this->productA, 2.0, 300.0);

        srpuFake();

        app(StoneReceptionSyncService::class)->updateProcessingProducts(
            'proc-1',
            $this->reception->fresh()->load('items.product')->items,
            $this->store->id,
        );

        expect(srpuSentPut()->data()['processingSum'])->toBe(30000);
    });

    test('существующие позиции техоперации получают свой id, новые — без id', function () {
        srpuItem($this->reception, $this->productA, 2.0);
        srpuItem($this->reception, $this->productB, 1.0);

        srpuFake([$this->productA->moysklad_id => 'pos-a']);

        app(StoneReceptionSyncService::class)->updateProcessingProducts(
            'proc-1',
            $this->reception->fresh()->load('items.product')->items,
            $this->store->id,
        );

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && str_contains($r->url(), '/entity/processing/proc-1')
            && ($r->data()['expand'] ?? null) === 'products.assortment');

        $products = collect(srpuSentPut()->data()['products'])
            ->keyBy(fn ($p) => basename($p['assortment']['meta']['href']));

        expect($products[$this->productA->moysklad_id]['id'])->toBe('pos-a');
        expect($products[$this->productB->moysklad_id])->not->toHaveKey('id');
    });

    test('не удалось прочитать позиции техоперации → позиции уходят без id', function () {
        srpuItem($this->reception, $this->productA, 2.0);

        srpuFake([], null, existingOk: false);

        $result = app(StoneReceptionSyncService::class)->updateProcessingProducts(
            'proc-1',
            $this->reception->fresh()->load('items.product')->items,
            $this->store->id,
        );

        expect($result['success'])->toBeTrue();
        expect(srpuSentPut()->data()['products'][0])->not->toHaveKey('id');
    });

    test('без количества сырья материалы в payload не передаются', function () {
        srpuItem($this->reception, $this->productA, 2.0);

        srpuFake();

        app(StoneReceptionSyncService::class)->updateProcessingProducts(
            'proc-1',
            $this->reception->fresh()->load('items.product')->items,
            $this->store->id,
            0.0,
            $this->rawProduct->moysklad_id,
        );

        $data = srpuSentPut()->data();
        expect($data)->not->toHaveKey('materials');
        expect($data)->not->toHaveKey('description');
    });

    test('позиции без moysklad_id пропускаются и не участвуют в processingSum', function () {
        $local = H::product(['name' => 'Локальный', 'moysklad_id' => null]);
        srpuItem($this->reception, $this->productA, 2.0, 100.0);
        srpuItem($this->reception, $local, 8.0, 1000.0);

        srpuFake();

        app(StoneReceptionSyncService::class)->updateProcessingProducts(
            'proc-1',
            $this->reception->fresh()->load('items.product')->items,
            $this->store->id,
        );

        $data = srpuSentPut()->data();
        expect($data['products'])->toHaveCount(1);
        expect((float) $data['quantity'])->toBe(2.0);
        expect($data['processingSum'])->toBe(10000);
    });

    test('ошибка МойСклад → success = false, текст из errors[0].error', function () {
        srpuItem($this->reception, $this->productA, 2.0);

        srpuFake([], Http::response(['errors' => [['error' => 'Техоперация заблокирована']]], 412));

        $result = app(StoneReceptionSyncService::class)->updateProcessingProducts(
            'proc-1',
            $this->reception->fresh()->load('items.product')->items,
            $this->store->id,
        );

        expect($result)->toMatchArray([
            'success' => false,
            'code'    => 'api_error',
            'message' => 'Ошибка МойСклад: Техоперация заблокирована',
        ]);
    });

    test('нет продуктов с moysklad_id → exception, PUT не отправляется', function () {
        srpuItem($this->reception, H::product(['moysklad_id' => null]), 2.0);

        srpuFake();

        $result = app(StoneReceptionSyncService::class)->updateProcessingProducts(
            'proc-1',
            $this->reception->fresh()->load('items.product')->items,
            $this->store->id,
        );

        expect($result['success'])->toBeFalse();
        expect($result['code'])->toBe('exception');
        expect($result['message'])->toBe('Ошибка: Нет продуктов с moysklad_id для обновления техоперации');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
    });

    test('склад не найден в МойСклад → exception', function () {
        srpuItem($this->reception, $this->productA, 2.0);

        Http::fake(['*' => Http::response(['errors' => [['error' => 'nope']]], 404)]);

        $result = app(StoneReceptionSyncService::class)->updateProcessingProducts(
            'proc-1',
            $this->reception->fresh()->load('items.product')->items,
            $this->store->id,
        );

        expect($result['code'])->toBe('exception');
        expect($result['message'])->toBe('Ошибка: Не удалось получить данные склада');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
    });

    test('без токена → exception, запросы не уходят', function () {
        config()->set('services.moysklad.token', '');
        Http::fake();

        $result = app(StoneReceptionSyncService::class)->updateProcessingProducts(
            'proc-1', collect(), $this->store->id,
        );

        expect($result['success'])->toBeFalse();
        expect($result['message'])->toBe('Ошибка: MoySklad токен не установлен');
        Http::assertNothingSent();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// syncReception() — повторная синхронизация и refreshAffectedStocks()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionSyncService::syncReception() — повторная приёмка', function () {

    beforeEach(function () {
        $this->reception->update([
            'moysklad_processing_id'   => 'proc-1',
            'moysklad_processing_name' => 'ТО-01',
            'moysklad_sync_status'     => StoneReception::SYNC_STATUS_NOT_SYNCED,
            'moysklad_sync_error'      => 'старая ошибка',
        ]);
    });

    test('обновляет существующую техоперацию (PUT), а не создаёт новую', function () {
        srpuItem($this->reception, $this->productA, 2.0, 300.0);

        srpuFake();

        app(StoneReceptionSyncService::class)->syncReception($this->reception->fresh());

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');

        $data = srpuSentPut()->data();
        expect((float) $data['materials'][0]['quantity'])->toBe(7.5);
        expect(basename($data['materials'][0]['assortment']['meta']['href']))->toBe($this->rawProduct->moysklad_id);
        // накладные отдела приёмки: (600 + 100 × 2) × 100 / 2
        expect($data['processingSum'])->toBe(40000);
        expect($data['description'])->toContain('партия №TEST-01');

        $fresh = $this->reception->fresh();
        expect($fresh->isSynced())->toBeTrue();
        expect($fresh->moysklad_sync_error)->toBeNull();
        expect($fresh->moysklad_processing_id)->toBe('proc-1');
        expect($fresh->moysklad_processing_name)->toBe('ТО-01');
    });

    test('после успеха подтягивает остатки сырья, продукции и товаров из $alsoRefresh без повторов', function () {
        srpuItem($this->reception, $this->productA, 2.0);
        srpuItem($this->reception, $this->productB, 1.0);
        $removed = (string) Str::uuid();

        srpuFake();

        app(StoneReceptionSyncService::class)->syncReception(
            $this->reception->fresh(),
            null,
            [$removed, $this->productA->moysklad_id, null],
        );

        $expected = collect([
            $this->rawProduct->moysklad_id,
            $this->productA->moysklad_id,
            $this->productB->moysklad_id,
            $removed,
        ])->sort()->values()->all();

        expect(srpuStockIds())->toBe($expected);
    });

    test('ошибка МойСклад → markSyncError с текстом, остатки не дергаются', function () {
        srpuItem($this->reception, $this->productA, 2.0);

        srpuFake([], Http::response(['errors' => [['error' => 'Документ проведён']]], 400));

        app(StoneReceptionSyncService::class)->syncReception($this->reception->fresh());

        $fresh = $this->reception->fresh();
        expect($fresh->moysklad_sync_status)->toBe(StoneReception::SYNC_STATUS_NOT_SYNCED);
        expect($fresh->moysklad_sync_error)->toBe('Ошибка МойСклад: Документ проведён');
        expect($fresh->moysklad_processing_id)->toBe('proc-1');
        expect(srpuStockIds())->toBe([]);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// deleteProcessingForReception() — refreshAffectedStocks после удаления
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionSyncService::deleteProcessingForReception()', function () {

    test('после удаления подтягивает остатки сырья и продукции', function () {
        srpuItem($this->reception, $this->productA, 2.0);
        $this->reception->update(['moysklad_processing_id' => 'proc-del']);

        Http::fake([
            '*entity/processing/proc-del' => Http::response(null, 200),
            '*report/stock/bystore*'      => Http::response(['rows' => []], 200),
        ]);

        $result = app(StoneReceptionSyncService::class)
            ->deleteProcessingForReception($this->reception->fresh()->load('items.product', 'rawMaterialBatch.product'));

        expect($result['success'])->toBeTrue();
        expect(srpuStockIds())->toBe(collect([$this->rawProduct->moysklad_id, $this->productA->moysklad_id])->sort()->values()->all());
    });

    test('приёмка без техоперации → успех без запросов', function () {
        Http::fake();

        $result = app(StoneReceptionSyncService::class)->deleteProcessingForReception($this->reception->fresh());

        expect($result)->toBe(['success' => true, 'message' => 'Техоперация не синхронизирована']);
        Http::assertNothingSent();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// getProcessing()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionSyncService::getProcessing()', function () {

    test('возвращает тело техоперации', function () {
        Http::fake(['*entity/processing/proc-7' => Http::response(['id' => 'proc-7', 'name' => 'ТО-07'], 200)]);

        expect(app(StoneReceptionSyncService::class)->getProcessing('proc-7'))
            ->toBe(['id' => 'proc-7', 'name' => 'ТО-07']);

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->url() === SRPU_BASE . '/entity/processing/proc-7'
            && $r->hasHeader('Authorization', 'Bearer test-token'));
    });

    test('ошибка МойСклад → null', function () {
        Http::fake(['*' => Http::response(['errors' => [['error' => 'Не найдено']]], 404)]);

        expect(app(StoneReceptionSyncService::class)->getProcessing('proc-7'))->toBeNull();
    });

    test('без токена → null, запрос не уходит', function () {
        config()->set('services.moysklad.token', '');
        Http::fake();

        expect(app(StoneReceptionSyncService::class)->getProcessing('proc-7'))->toBeNull();
        Http::assertNothingSent();
    });
});
