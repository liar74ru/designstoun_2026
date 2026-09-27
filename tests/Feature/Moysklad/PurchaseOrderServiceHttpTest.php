<?php

use App\Models\Counterparty;
use App\Models\Product;
use App\Models\Store;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderItem;
use App\Models\User;
use App\Models\Worker;
use App\Services\Moysklad\MoySkladPurchaseOrderService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// Фейковый МойСклад: GET /entity/{type}/{id} отдаёт meta с href запроса,
// GET /entity/organization — одну организацию. $routes — 'МЕТОД путь' => ответ,
// $missing — пути сущностей, которых «нет» (404).
function poHttpFake(array $routes = [], array $missing = []): void
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

// Заказ поставщику: склад store-po, контрагент cp-po, две позиции
// (min_price имеет приоритет над buy_price), автор — пользователь с работником.
function poMakeOrder(array $attrs = []): SupplierOrder
{
    $store        = Store::create(['id' => 'store-po', 'name' => 'Склад сырья']);
    $counterparty = Counterparty::create(['name' => 'Карьер', 'moysklad_id' => 'cp-po']);
    $worker       = Worker::create(['name' => 'Иванов Иван', 'position' => 'Мастер']);
    $user         = User::factory()->create(['name' => 'ivanov']);
    $user->forceFill(['worker_id' => $worker->id])->save();

    $order = SupplierOrder::create(array_merge([
        'number'             => 'ЗП-15',
        'store_id'           => $store->id,
        'counterparty_id'    => $counterparty->id,
        'created_by_user_id' => $user->id,
        'status'             => SupplierOrder::STATUS_NEW,
        'created_at'         => '2026-05-10 12:00:00',
    ], $attrs));

    $p1 = Product::factory()->create(['moysklad_id' => 'prod-a', 'buy_price' => 1500, 'min_price' => 0]);
    $p2 = Product::factory()->create(['moysklad_id' => 'prod-b', 'buy_price' => 1000, 'min_price' => 1234.56]);
    SupplierOrderItem::create(['supplier_order_id' => $order->id, 'product_id' => $p1->id, 'quantity' => 2.5]);
    SupplierOrderItem::create(['supplier_order_id' => $order->id, 'product_id' => $p2->id, 'quantity' => 1]);

    return $order->fresh();
}

beforeEach(function () {
    config()->set('services.moysklad.token', 'test-token');
    config()->set('services.moysklad.base_url', 'https://ms.test/api');
});

// ══════════════════════════════════════════════════════════════════════════════
// MoySkladPurchaseOrderService::createPurchaseOrder() — HTTP
// ══════════════════════════════════════════════════════════════════════════════

describe('MoySkladPurchaseOrderService::createPurchaseOrder() — HTTP', function () {

    test('отправляет заказ с метаданными, позициями в копейках и автором', function () {
        $order = poMakeOrder();
        poHttpFake(['POST /entity/purchaseorder' => Http::response(['id' => 'po-new'])]);

        $result = (new MoySkladPurchaseOrderService())->createPurchaseOrder($order);

        expect($result['success'])->toBeTrue()
            ->and($result['moysklad_id'])->toBe('po-new')
            ->and($result['code'])->toBe('');

        Http::assertSent(function (Request $r) {
            if ($r->method() !== 'POST' || !str_ends_with($r->url(), '/entity/purchaseorder')) {
                return false;
            }
            $b = $r->data();

            return $b['name'] === 'ЗП-15'
                && $b['organization']['meta']['href'] === 'https://ms.test/api/entity/organization/org-1'
                && $b['agent']['meta']['href'] === 'https://ms.test/api/entity/counterparty/cp-po'
                && $b['store']['meta']['href'] === 'https://ms.test/api/entity/store/store-po'
                && $b['moment'] === '2026-05-10 10:00:00.000'
                && $b['description'] === 'Иванов Иван'
                && count($b['positions']) === 2
                && $b['positions'][0]['quantity'] == 2.5
                && $b['positions'][0]['price'] === 150000
                && $b['positions'][0]['assortment']['meta']['href'] === 'https://ms.test/api/entity/product/prod-a'
                && $b['positions'][1]['quantity'] == 1.0
                && $b['positions'][1]['price'] === 123456
                && $b['positions'][1]['assortment']['meta']['href'] === 'https://ms.test/api/entity/product/prod-b';
        });
    });

    test('имя можно переопределить (суффикс при коллизии)', function () {
        $order = poMakeOrder();
        poHttpFake(['POST /entity/purchaseorder' => Http::response(['id' => 'po-new'])]);

        (new MoySkladPurchaseOrderService())->createPurchaseOrder($order, 'ЗП-15_01');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data()['name'] === 'ЗП-15_01');
    });

    test('товар без moysklad_id пропускается', function () {
        $order = poMakeOrder();
        $order->items()->first()->product->update(['moysklad_id' => null]);
        poHttpFake(['POST /entity/purchaseorder' => Http::response(['id' => 'po-new'])]);

        $result = (new MoySkladPurchaseOrderService())->createPurchaseOrder($order);

        expect($result['success'])->toBeTrue();
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && count($r->data()['positions']) === 1);
    });

    test('без позиций — ошибка, POST не отправляется', function () {
        $order = poMakeOrder();
        $order->items()->delete();
        poHttpFake();

        $result = (new MoySkladPurchaseOrderService())->createPurchaseOrder($order);

        expect($result['success'])->toBeFalse()->and($result['message'])->toContain('Нет позиций');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    });

    test('контрагент без moysklad_id — ошибка', function () {
        $order = poMakeOrder();
        $order->counterparty->update(['moysklad_id' => '']);
        poHttpFake();

        $result = (new MoySkladPurchaseOrderService())->createPurchaseOrder($order->fresh());

        expect($result['success'])->toBeFalse()->and($result['message'])->toContain('Контрагент');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    });

    test('склад не найден в МойСклад — ошибка', function () {
        $order = poMakeOrder();
        poHttpFake([], ['/entity/store/store-po']);

        $result = (new MoySkladPurchaseOrderService())->createPurchaseOrder($order);

        expect($result['success'])->toBeFalse()->and($result['message'])->toContain('склада');
    });

    test('контрагент не найден в МойСклад — ошибка', function () {
        $order = poMakeOrder();
        poHttpFake([], ['/entity/counterparty/cp-po']);

        $result = (new MoySkladPurchaseOrderService())->createPurchaseOrder($order);

        expect($result['success'])->toBeFalse()->and($result['message'])->toContain('контрагента');
    });

    test('нет организации — ошибка', function () {
        $order = poMakeOrder();
        poHttpFake(['GET /entity/organization' => Http::response(['rows' => []])]);

        $result = (new MoySkladPurchaseOrderService())->createPurchaseOrder($order);

        expect($result['success'])->toBeFalse()->and($result['message'])->toContain('организации');
    });

    test('ошибка API — текст из errors[0].error, код api_error', function () {
        $order = poMakeOrder();
        poHttpFake(['POST /entity/purchaseorder' => Http::response(['errors' => [['error' => 'Неверный формат цены', 'code' => 1000]]], 400)]);

        $result = (new MoySkladPurchaseOrderService())->createPurchaseOrder($order);

        expect($result['success'])->toBeFalse()
            ->and($result['moysklad_id'])->toBeNull()
            ->and($result['code'])->toBe('api_error')
            ->and($result['message'])->toBe('Ошибка МойСклад: Неверный формат цены');
    });

    test('коллизия имени — код duplicate_name', function () {
        $order = poMakeOrder();
        poHttpFake(['POST /entity/purchaseorder' => Http::response(['errors' => [['error' => 'Имя не уникально', 'code' => 3006]]], 412)]);

        $result = (new MoySkladPurchaseOrderService())->createPurchaseOrder($order);

        expect($result['code'])->toBe('duplicate_name')->and($result['success'])->toBeFalse();
    });

    test('сетевое исключение не выбрасывается наружу', function () {
        $order = poMakeOrder();
        poHttpFake(['POST /entity/purchaseorder' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

        $result = (new MoySkladPurchaseOrderService())->createPurchaseOrder($order);

        expect($result['success'])->toBeFalse()->and($result['message'])->toContain('timeout');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// MoySkladPurchaseOrderService::updatePurchaseOrder() — HTTP
// ══════════════════════════════════════════════════════════════════════════════

describe('MoySkladPurchaseOrderService::updatePurchaseOrder() — HTTP', function () {

    test('отправляет PUT с агентом, моментом и позициями', function () {
        $order = poMakeOrder(['moysklad_id' => 'po-1']);
        poHttpFake(['PUT /entity/purchaseorder/po-1' => Http::response(['id' => 'po-1'])]);

        $result = (new MoySkladPurchaseOrderService())->updatePurchaseOrder($order);

        expect($result['success'])->toBeTrue();
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/entity/purchaseorder/po-1')
            && $r->data()['agent']['meta']['href'] === 'https://ms.test/api/entity/counterparty/cp-po'
            && $r->data()['moment'] === '2026-05-10 10:00:00.000'
            && $r->data()['description'] === 'Иванов Иван'
            && count($r->data()['positions']) === 2
            && $r->data()['positions'][0]['price'] === 150000
            && !array_key_exists('name', $r->data()));
    });

    test('без moysklad_id создаёт заказ заново', function () {
        $order = poMakeOrder();
        poHttpFake(['POST /entity/purchaseorder' => Http::response(['id' => 'po-new'])]);

        $result = (new MoySkladPurchaseOrderService())->updatePurchaseOrder($order);

        expect($result['success'])->toBeTrue()->and($result['moysklad_id'])->toBe('po-new');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
    });

    test('ошибка API — текст из errors[0].error', function () {
        $order = poMakeOrder(['moysklad_id' => 'po-1']);
        poHttpFake(['PUT /entity/purchaseorder/po-1' => Http::response(['errors' => [['error' => 'Документ проведён']]], 412)]);

        $result = (new MoySkladPurchaseOrderService())->updatePurchaseOrder($order);

        expect($result['success'])->toBeFalse()->and($result['message'])->toBe('Ошибка МойСклад: Документ проведён');
    });

    test('без позиций — PUT не отправляется', function () {
        $order = poMakeOrder(['moysklad_id' => 'po-1']);
        $order->items()->delete();
        poHttpFake();

        $result = (new MoySkladPurchaseOrderService())->updatePurchaseOrder($order);

        expect($result['success'])->toBeFalse()->and($result['message'])->toContain('Нет позиций');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
    });

    test('контрагент без moysklad_id — ошибка', function () {
        $order = poMakeOrder(['moysklad_id' => 'po-1']);
        $order->counterparty->update(['moysklad_id' => '']);
        poHttpFake();

        $result = (new MoySkladPurchaseOrderService())->updatePurchaseOrder($order->fresh());

        expect($result['success'])->toBeFalse()->and($result['message'])->toContain('Контрагент');
    });

    test('контрагент не найден в МойСклад — ошибка', function () {
        $order = poMakeOrder(['moysklad_id' => 'po-1']);
        poHttpFake([], ['/entity/counterparty/cp-po']);

        $result = (new MoySkladPurchaseOrderService())->updatePurchaseOrder($order);

        expect($result['success'])->toBeFalse()->and($result['message'])->toContain('контрагента');
    });

    test('сетевое исключение не выбрасывается наружу', function () {
        $order = poMakeOrder(['moysklad_id' => 'po-1']);
        poHttpFake(['PUT /entity/purchaseorder/po-1' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

        $result = (new MoySkladPurchaseOrderService())->updatePurchaseOrder($order);

        expect($result['success'])->toBeFalse()->and($result['message'])->toContain('timeout');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// MoySkladPurchaseOrderService::deletePurchaseOrder() — HTTP
// ══════════════════════════════════════════════════════════════════════════════

describe('MoySkladPurchaseOrderService::deletePurchaseOrder() — HTTP', function () {

    test('отправляет DELETE по id заказа', function () {
        poHttpFake(['DELETE /entity/purchaseorder/po-1' => Http::response(null, 200)]);

        $result = (new MoySkladPurchaseOrderService())->deletePurchaseOrder('po-1');

        expect($result['success'])->toBeTrue();
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/entity/purchaseorder/po-1'));
    });

    test('ошибка API — success=false, без исключения', function () {
        poHttpFake(['DELETE /entity/purchaseorder/po-1' => Http::response(['errors' => [['title' => 'Не найдено', 'error' => 'Объект не найден']]], 404)]);

        $result = (new MoySkladPurchaseOrderService())->deletePurchaseOrder('po-1');

        expect($result['success'])->toBeFalse()
            ->and($result['message'])->toStartWith('Ошибка МойСклад')
            ->and($result['message'])->toContain('Объект не найден');
    });

    test('ответ только с title — берётся он', function () {
        poHttpFake(['DELETE /entity/purchaseorder/po-1' => Http::response(['errors' => [['title' => 'Не найдено']]], 404)]);

        expect((new MoySkladPurchaseOrderService())->deletePurchaseOrder('po-1')['message'])->toContain('Не найдено');
    });

    test('без токена запрос не отправляется', function () {
        config()->set('services.moysklad.token', '');
        Http::fake();

        $result = (new MoySkladPurchaseOrderService())->deletePurchaseOrder('po-1');

        expect($result['success'])->toBeFalse()->and($result['message'])->toContain('токен');
        Http::assertNothingSent();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// MoySkladPurchaseOrderService::checkExists() — HTTP
// ══════════════════════════════════════════════════════════════════════════════

describe('MoySkladPurchaseOrderService::checkExists() — HTTP', function () {

    test('запрашивает заказ по id с токеном', function () {
        poHttpFake();

        expect((new MoySkladPurchaseOrderService())->checkExists('po-7'))->toBeTrue();
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && str_ends_with($r->url(), '/entity/purchaseorder/po-7')
            && $r->hasHeader('Authorization', 'Bearer test-token'));
    });

    test('сетевое исключение → false', function () {
        poHttpFake(['GET /entity/purchaseorder/po-7' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

        expect((new MoySkladPurchaseOrderService())->checkExists('po-7'))->toBeFalse();
    });
});
