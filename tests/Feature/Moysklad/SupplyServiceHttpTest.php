<?php

use App\Models\Counterparty;
use App\Models\Product;
use App\Models\Store;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderItem;
use App\Services\Moysklad\MoySkladSupplyService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// Фейковый МойСклад: GET /entity/{type}/{id} отдаёт meta с href запроса,
// GET /entity/organization — одну организацию. $routes — 'МЕТОД путь' => ответ,
// $missing — пути сущностей, которых «нет» (404).
function supHttpFake(array $routes = [], array $missing = []): void
{
    Http::fake(function (Request $request) use ($routes, $missing) {
        $path = str_replace('https://ms.test/api', '', strtok($request->url(), '?'));
        $key  = $request->method() . ' ' . $path;

        if (array_key_exists($key, $routes)) {
            return $routes[$key];
        }
        if (in_array($path, $missing, true)) {
            return Http::response(['errors' => [['error' => 'Не найдено']]], 404);
        }
        if ($request->method() === 'GET' && $path === '/entity/organization') {
            return Http::response(['rows' => [['meta' => ['href' => 'https://ms.test/api/entity/organization/org-1', 'type' => 'organization']]]]);
        }
        if ($request->method() === 'GET' && preg_match('#^/entity/(\w+)/([\w-]+)$#', $path, $m)) {
            return Http::response(['meta' => ['href' => 'https://ms.test/api' . $path, 'type' => $m[1], 'mediaType' => 'application/json']]);
        }

        return Http::response(['errors' => [['error' => 'Неожиданный запрос ' . $key]]], 500);
    });
}

function supMakeOrder(array $attrs = []): SupplierOrder
{
    $store        = Store::create(['id' => 'store-sup', 'name' => 'Склад сырья']);
    $counterparty = Counterparty::create(['name' => 'Карьер', 'moysklad_id' => 'cp-sup']);

    $order = SupplierOrder::create(array_merge([
        'number'          => 'ЗП-20',
        'store_id'        => $store->id,
        'counterparty_id' => $counterparty->id,
        'moysklad_id'     => 'po-20',
        'status'          => SupplierOrder::STATUS_NEW,
        'note'            => 'Привезти до обеда',
        'created_at'      => '2026-05-10 12:00:00',
    ], $attrs));

    $product = Product::factory()->create(['moysklad_id' => 'prod-s', 'buy_price' => 999.99, 'min_price' => null]);
    SupplierOrderItem::create(['supplier_order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 3.25]);

    return $order->fresh();
}

beforeEach(function () {
    config()->set('services.moysklad.token', 'test-token');
    config()->set('services.moysklad.base_url', 'https://ms.test/api');
});

// ══════════════════════════════════════════════════════════════════════════════
// MoySkladSupplyService::createSupply() — HTTP
// ══════════════════════════════════════════════════════════════════════════════

describe('MoySkladSupplyService::createSupply() — HTTP', function () {

    test('отправляет приёмку со ссылкой на заказ поставщику и позициями', function () {
        $order = supMakeOrder();
        supHttpFake(['POST /entity/supply' => Http::response(['id' => 'supply-new'])]);

        $result = (new MoySkladSupplyService())->createSupply($order);

        expect($result['success'])->toBeTrue()
            ->and($result['supply_moysklad_id'])->toBe('supply-new')
            ->and($result['code'])->toBe('');

        Http::assertSent(function (Request $r) {
            if ($r->method() !== 'POST' || !str_ends_with($r->url(), '/entity/supply')) {
                return false;
            }
            $b = $r->data();

            return $b['name'] === 'ЗП-20'
                && $b['organization']['meta']['href'] === 'https://ms.test/api/entity/organization/org-1'
                && $b['agent']['meta']['href'] === 'https://ms.test/api/entity/counterparty/cp-sup'
                && $b['store']['meta']['href'] === 'https://ms.test/api/entity/store/store-sup'
                && $b['purchaseOrder']['meta']['href'] === 'https://ms.test/api/entity/purchaseorder/po-20'
                && $b['purchaseOrder']['meta']['type'] === 'purchaseorder'
                && $b['moment'] === '2026-05-10 10:00:00.000'
                && $b['description'] === 'Привезти до обеда'
                && count($b['positions']) === 1
                && $b['positions'][0]['quantity'] == 3.25
                && $b['positions'][0]['price'] === 99999
                && $b['positions'][0]['assortment']['meta']['href'] === 'https://ms.test/api/entity/product/prod-s';
        });
    });

    test('имя переопределяется, без примечания description не отправляется', function () {
        $order = supMakeOrder(['note' => null]);
        supHttpFake(['POST /entity/supply' => Http::response(['id' => 'supply-new'])]);

        (new MoySkladSupplyService())->createSupply($order, 'ЗП-20_01');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->data()['name'] === 'ЗП-20_01'
            && !array_key_exists('description', $r->data()));
    });

    test('ошибка API — текст из errors[0].error, код api_error', function () {
        $order = supMakeOrder();
        supHttpFake(['POST /entity/supply' => Http::response(['errors' => [['error' => 'Склад закрыт']]], 412)]);

        $result = (new MoySkladSupplyService())->createSupply($order);

        expect($result['success'])->toBeFalse()
            ->and($result['supply_moysklad_id'])->toBeNull()
            ->and($result['code'])->toBe('api_error')
            ->and($result['message'])->toBe('Ошибка МойСклад: Склад закрыт');
    });

    test('коллизия имени — код duplicate_name', function () {
        $order = supMakeOrder();
        supHttpFake(['POST /entity/supply' => Http::response(['errors' => [['error' => 'Значение поля name должно быть уникальным', 'parameter' => 'name']]], 412)]);

        $result = (new MoySkladSupplyService())->createSupply($order);

        expect($result['code'])->toBe('duplicate_name');
    });

    test('без позиций — ошибка, POST не отправляется', function () {
        $order = supMakeOrder();
        $order->items()->delete();
        supHttpFake();

        $result = (new MoySkladSupplyService())->createSupply($order);

        expect($result['success'])->toBeFalse()->and($result['message'])->toContain('Нет позиций');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    });

    test('контрагент без moysklad_id — ошибка', function () {
        $order = supMakeOrder();
        $order->counterparty->update(['moysklad_id' => '']);
        supHttpFake();

        $result = (new MoySkladSupplyService())->createSupply($order->fresh());

        expect($result['success'])->toBeFalse()->and($result['message'])->toContain('Контрагент');
    });

    test('склад не найден в МойСклад — ошибка', function () {
        $order = supMakeOrder();
        supHttpFake([], ['/entity/store/store-sup']);

        $result = (new MoySkladSupplyService())->createSupply($order);

        expect($result['success'])->toBeFalse()->and($result['message'])->toContain('склада');
    });

    test('контрагент не найден в МойСклад — ошибка', function () {
        $order = supMakeOrder();
        supHttpFake([], ['/entity/counterparty/cp-sup']);

        $result = (new MoySkladSupplyService())->createSupply($order);

        expect($result['success'])->toBeFalse()->and($result['message'])->toContain('контрагента');
    });

    test('нет организации — ошибка', function () {
        $order = supMakeOrder();
        supHttpFake(['GET /entity/organization' => Http::response(['rows' => []])]);

        $result = (new MoySkladSupplyService())->createSupply($order);

        expect($result['success'])->toBeFalse()->and($result['message'])->toContain('организации');
    });

    test('сетевое исключение не выбрасывается наружу', function () {
        $order = supMakeOrder();
        supHttpFake(['POST /entity/supply' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

        $result = (new MoySkladSupplyService())->createSupply($order);

        expect($result['success'])->toBeFalse()->and($result['message'])->toContain('timeout');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// MoySkladSupplyService::deleteSupply() — HTTP
// ══════════════════════════════════════════════════════════════════════════════

describe('MoySkladSupplyService::deleteSupply() — HTTP', function () {

    test('отправляет DELETE по id приёмки', function () {
        supHttpFake(['DELETE /entity/supply/supply-1' => Http::response(null, 204)]);

        $result = (new MoySkladSupplyService())->deleteSupply('supply-1');

        expect($result['success'])->toBeTrue()->and($result['message'])->toContain('удалена');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/entity/supply/supply-1'));
    });

    test('ошибка API — текст из errors[0].error и HTTP-код', function () {
        supHttpFake(['DELETE /entity/supply/supply-1' => Http::response(['errors' => [['error' => 'Документ не найден']]], 404)]);

        $result = (new MoySkladSupplyService())->deleteSupply('supply-1');

        expect($result['success'])->toBeFalse()
            ->and($result['message'])->toBe('Ошибка API МойСклад: Документ не найден (HTTP 404)');
    });

    test('сетевое исключение не выбрасывается наружу', function () {
        supHttpFake(['DELETE /entity/supply/supply-1' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

        $result = (new MoySkladSupplyService())->deleteSupply('supply-1');

        expect($result['success'])->toBeFalse()->and($result['message'])->toContain('timeout');
    });

    test('без токена запрос не отправляется', function () {
        config()->set('services.moysklad.token', '');
        Http::fake();

        $result = (new MoySkladSupplyService())->deleteSupply('supply-1');

        expect($result['success'])->toBeFalse()->and($result['message'])->toContain('токен');
        Http::assertNothingSent();
    });
});
