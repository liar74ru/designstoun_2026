<?php

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Store;
use App\Services\Moysklad\StockSyncService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const SSBS_P1 = 'aaaa0000-0000-0000-0000-00000000000a';
const SSBS_P2 = 'bbbb0000-0000-0000-0000-00000000000b';

// Строка отчёта /report/stock/bystore: товар + остатки по складам [storeId => [stock, reserve, inTransit]].
function ssbsRow(string $productMsId, array $stores): array
{
    $byStore = [];
    foreach ($stores as $storeId => [$stock, $reserve, $transit]) {
        $byStore[] = [
            'meta'      => ['href' => 'https://ms.test/api/entity/store/' . $storeId],
            'stock'     => $stock,
            'reserve'   => $reserve,
            'inTransit' => $transit,
        ];
    }

    return ['meta' => ['href' => 'https://ms.test/api/entity/product/' . $productMsId . '?expand=supplier'], 'stockByStore' => $byStore];
}

function ssbsStock(Product $product, string $storeId): ?ProductStock
{
    return ProductStock::where('product_id', $product->id)->where('store_id', $storeId)->first();
}

beforeEach(function () {
    config()->set('services.moysklad.token', 'test-token');
    config()->set('services.moysklad.base_url', 'https://ms.test/api');

    Store::create(['id' => 'store-a', 'name' => 'Склад А']);
    Store::create(['id' => 'store-b', 'name' => 'Склад Б']);
    $this->p1 = Product::factory()->create(['moysklad_id' => SSBS_P1]);
    $this->p2 = Product::factory()->create(['moysklad_id' => SSBS_P2]);
});

// ══════════════════════════════════════════════════════════════════════════════
// StockSyncService::syncAllStocksByStores()
// ══════════════════════════════════════════════════════════════════════════════

describe('StockSyncService::syncAllStocksByStores()', function () {

    test('записывает остаток, резерв и транзит по всем складам', function () {
        Http::fake(['*/report/stock/bystore*' => Http::response(['rows' => [
            ssbsRow(SSBS_P1, ['store-a' => [12.5, 2, 1], 'store-b' => [3, 0, 0]]),
            ssbsRow(SSBS_P2, ['store-a' => [7, 0, 0.5]]),
        ]])]);

        $result = (new StockSyncService())->syncAllStocksByStores();

        expect($result['success'])->toBeTrue()
            ->and($result['updated'])->toBe(3)
            ->and($result['message'])->toBe('Обновлено остатков: 3');

        $a = ssbsStock($this->p1, 'store-a');
        expect((float) $a->quantity)->toBe(12.5)
            ->and((float) $a->reserved)->toBe(2.0)
            ->and((float) $a->in_transit)->toBe(1.0)
            ->and((float) ssbsStock($this->p1, 'store-b')->quantity)->toBe(3.0)
            ->and((float) ssbsStock($this->p2, 'store-a')->in_transit)->toBe(0.5);
    });

    test('обновляет существующую строку, а не создаёт дубль', function () {
        ProductStock::create(['product_id' => $this->p1->id, 'store_id' => 'store-a', 'quantity' => 100]);
        Http::fake(['*/report/stock/bystore*' => Http::response(['rows' => [
            ssbsRow(SSBS_P1, ['store-a' => [40, 0, 0]]),
        ]])]);

        (new StockSyncService())->syncAllStocksByStores();

        expect(ProductStock::where('product_id', $this->p1->id)->count())->toBe(1)
            ->and((float) ssbsStock($this->p1, 'store-a')->quantity)->toBe(40.0);
    });

    test('запрос без фильтра по складу, с токеном и stockMode=all', function () {
        Http::fake(['*' => Http::response(['rows' => []])]);

        (new StockSyncService())->syncAllStocksByStores();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/report/stock/bystore')
            && $r->hasHeader('Authorization', 'Bearer test-token')
            && ($r->data()['filter'] ?? null) === 'stockMode=all'
            && !array_key_exists('store', $r->data())
            && (int) $r->data()['offset'] === 0
            && (int) $r->data()['limit'] === 1000);
    });

    test('листает страницы, пока приходит полная страница', function () {
        $fullPage = array_fill(0, 1000, ssbsRow('cccc0000-0000-0000-0000-00000000000c', []));
        Http::fakeSequence('*/report/stock/bystore*')
            ->push(['rows' => $fullPage])
            ->push(['rows' => [ssbsRow(SSBS_P1, ['store-a' => [5, 0, 0]])]]);

        $result = (new StockSyncService())->syncAllStocksByStores();

        expect($result['success'])->toBeTrue()->and($result['updated'])->toBe(1);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => (int) $r->data()['offset'] === 1000);
    });

    test('ошибка API — success=false, остатки не меняются', function () {
        ProductStock::create(['product_id' => $this->p1->id, 'store_id' => 'store-a', 'quantity' => 9]);
        Http::fake(['*' => Http::response(['errors' => [['error' => 'Нет доступа']]], 403)]);

        $result = (new StockSyncService())->syncAllStocksByStores();

        expect($result['success'])->toBeFalse()
            ->and($result['message'])->toBe('Ошибка получения данных из МойСклад')
            ->and((float) ssbsStock($this->p1, 'store-a')->quantity)->toBe(9.0);
    });

    test('сетевое исключение — success=false без выброса', function () {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $result = (new StockSyncService())->syncAllStocksByStores();

        expect($result['success'])->toBeFalse();
    });

    test('строки с неизвестным товаром и складом пропускаются', function () {
        Http::fake(['*' => Http::response(['rows' => [
            ssbsRow('dddd0000-0000-0000-0000-00000000000d', ['store-a' => [1, 0, 0]]),
            ssbsRow(SSBS_P1, ['store-unknown' => [1, 0, 0], 'store-b' => [2, 0, 0]]),
            ['meta' => ['href' => 'https://ms.test/api/entity/service/xyz'], 'stockByStore' => []],
        ]])]);

        $result = (new StockSyncService())->syncAllStocksByStores();

        expect($result['updated'])->toBe(1)
            ->and(ProductStock::count())->toBe(1)
            ->and((float) ssbsStock($this->p1, 'store-b')->quantity)->toBe(2.0);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// StockSyncService::syncStocksByStore()
// ══════════════════════════════════════════════════════════════════════════════

describe('StockSyncService::syncStocksByStore()', function () {

    test('передаёт склад в запрос и пишет только его остатки', function () {
        ProductStock::create(['product_id' => $this->p1->id, 'store_id' => 'store-b', 'quantity' => 50]);
        Http::fake(['*/report/stock/bystore*' => Http::response(['rows' => [
            ssbsRow(SSBS_P1, ['store-a' => [8, 1, 0], 'store-b' => [0, 0, 0]]),
        ]])]);

        $result = (new StockSyncService())->syncStocksByStore('store-a');

        expect($result['success'])->toBeTrue()->and($result['updated'])->toBe(1);
        expect((float) ssbsStock($this->p1, 'store-a')->quantity)->toBe(8.0)
            ->and((float) ssbsStock($this->p1, 'store-a')->reserved)->toBe(1.0)
            // Чужой склад не трогается, даже если в ответе нули
            ->and((float) ssbsStock($this->p1, 'store-b')->quantity)->toBe(50.0);

        Http::assertSent(fn (Request $r) => ($r->data()['store'] ?? null) === 'store-a'
            && ($r->data()['filter'] ?? null) === 'stockMode=all');
    });

    test('нулевой остаток на складе обнуляет существующую строку', function () {
        ProductStock::create(['product_id' => $this->p2->id, 'store_id' => 'store-a', 'quantity' => 15, 'reserved' => 3]);
        Http::fake(['*' => Http::response(['rows' => [ssbsRow(SSBS_P2, ['store-a' => [0, 0, 0]])]])]);

        $result = (new StockSyncService())->syncStocksByStore('store-a');

        expect($result['updated'])->toBe(1)
            ->and((float) ssbsStock($this->p2, 'store-a')->quantity)->toBe(0.0)
            ->and((float) ssbsStock($this->p2, 'store-a')->reserved)->toBe(0.0);
    });

    test('ошибка API — success=false', function () {
        Http::fake(['*' => Http::response(null, 500)]);

        $result = (new StockSyncService())->syncStocksByStore('store-a');

        expect($result['success'])->toBeFalse()->and(ProductStock::count())->toBe(0);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Запись пачкой
// ══════════════════════════════════════════════════════════════════════════════

describe('StockSyncService — запись остатков пачкой', function () {

    test('неизменившаяся ячейка не перезаписывается и не считается', function () {
        $stock = ProductStock::create(['product_id' => $this->p1->id, 'store_id' => 'store-a', 'quantity' => 5, 'reserved' => 1]);
        $stock->timestamps = false;
        $stock->forceFill(['updated_at' => now()->subDay()])->save();

        Http::fake(['*/report/stock/bystore*' => Http::response(['rows' => [
            ssbsRow(SSBS_P1, ['store-a' => [5, 1, 0]]),
        ]])]);

        $result = (new StockSyncService())->syncAllStocksByStores();

        expect($result['updated'])->toBe(0)
            ->and(ssbsStock($this->p1, 'store-a')->updated_at->lt(now()->subHours(12)))->toBeTrue();
    });

    test('available считается при записи пачкой — как хук saving модели', function () {
        ProductStock::create(['product_id' => $this->p1->id, 'store_id' => 'store-a', 'quantity' => 1]);
        Http::fake(['*/report/stock/bystore*' => Http::response(['rows' => [
            ssbsRow(SSBS_P1, ['store-a' => [10, 3, 0], 'store-b' => [4, 1.5, 0]]),
        ]])]);

        (new StockSyncService())->syncAllStocksByStores();

        expect((float) ssbsStock($this->p1, 'store-a')->available)->toBe(7.0)
            ->and((float) ssbsStock($this->p1, 'store-b')->available)->toBe(2.5);
    });

    test('пустая существующая ячейка обнуляется, пустая отсутствующая не создаётся', function () {
        ProductStock::create(['product_id' => $this->p1->id, 'store_id' => 'store-a', 'quantity' => 8, 'reserved' => 2]);
        Http::fake(['*/report/stock/bystore*' => Http::response(['rows' => [
            ssbsRow(SSBS_P1, ['store-a' => [0, 0, 0], 'store-b' => [0, 0, 0]]),
        ]])]);

        $result = (new StockSyncService())->syncAllStocksByStores();

        $a = ssbsStock($this->p1, 'store-a');
        expect($result['updated'])->toBe(1)
            ->and((float) $a->quantity)->toBe(0.0)
            ->and((float) $a->reserved)->toBe(0.0)
            ->and((float) $a->available)->toBe(0.0)
            ->and(ssbsStock($this->p1, 'store-b'))->toBeNull();
    });

    test('число запросов к БД не зависит от числа ячеек', function () {
        $rows = [];
        foreach (range(1, 200) as $i) {
            $msId = sprintf('eeee0000-0000-0000-0000-%012d', $i);
            Product::factory()->create(['moysklad_id' => $msId]);
            $rows[] = ssbsRow($msId, ['store-a' => [$i, 0, 0], 'store-b' => [$i * 2, 1, 0]]);
        }
        Http::fake(['*/report/stock/bystore*' => Http::response(['rows' => $rows])]);

        DB::enableQueryLog();
        $result = (new StockSyncService())->syncAllStocksByStores();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 400 ячеек: раньше ~1200 запросов, теперь три выборки и один upsert
        expect($result['updated'])->toBe(400)
            ->and($queries)->toBeLessThan(10)
            ->and(ProductStock::count())->toBe(400);
    });

    test('остатки одного товара после документа — тем же правилом', function () {
        ProductStock::create(['product_id' => $this->p1->id, 'store_id' => 'store-a', 'quantity' => 6]);
        Http::fake(['*/report/stock/bystore*' => Http::response(['rows' => [
            ssbsRow(SSBS_P1, ['store-a' => [0, 0, 0], 'store-b' => [2, 0, 0]]),
        ]])]);

        $result = (new StockSyncService())->updateProductStocksByMoyskladId(SSBS_P1);

        expect($result['updated'])->toBe(2)
            ->and((float) ssbsStock($this->p1, 'store-a')->quantity)->toBe(0.0)
            ->and((float) ssbsStock($this->p1, 'store-b')->quantity)->toBe(2.0);
    });
});
