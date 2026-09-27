<?php

use App\Services\Moysklad\MoySkladMoveService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// Фейковый МойСклад: GET /entity/{type}/{id} отдаёт meta с href запроса,
// GET /entity/organization — одну организацию. $routes — 'МЕТОД путь' => ответ,
// $missing — пути сущностей, которых «нет» (404).
function mvHttpFake(array $routes = [], array $missing = []): void
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

function mvMoveData(array $overrides = []): array
{
    return array_merge([
        'from_store_id' => 'store-from',
        'to_store_id'   => 'store-to',
        'products'      => [['product_id' => 'prod-1', 'quantity' => 2.5]],
    ], $overrides);
}

beforeEach(function () {
    config()->set('services.moysklad.token', 'test-token');
    config()->set('services.moysklad.base_url', 'https://ms.test/api');
});

// ══════════════════════════════════════════════════════════════════════════════
// MoySkladMoveService::createMove() — HTTP
// ══════════════════════════════════════════════════════════════════════════════

describe('MoySkladMoveService::createMove() — HTTP', function () {

    test('отправляет перемещение с метаданными складов, позициями и реквизитами', function () {
        mvHttpFake(['POST /entity/move' => Http::response(['id' => 'move-new', 'externalCode' => 'movement_7'])]);

        $result = (new MoySkladMoveService())->createMove(mvMoveData([
            'products'    => [
                ['product_id' => 'prod-1', 'quantity' => 2.5],
                ['id' => 'prod-2', 'quantity' => '3'],
            ],
            'name'        => 'Партия: 42',
            'description' => 'Автоматическое перемещение',
            'external_id' => 'movement_7',
            'created_at'  => '2026-05-10 12:00:00',
        ]));

        expect($result['success'])->toBeTrue()
            ->and($result['move_id'])->toBe('move-new')
            ->and($result['external_id'])->toBe('movement_7')
            ->and($result['code'])->toBe('');

        Http::assertSent(function (Request $r) {
            if ($r->method() !== 'POST' || !str_ends_with($r->url(), '/entity/move')) {
                return false;
            }
            $b = $r->data();

            return $r->hasHeader('Authorization', 'Bearer test-token')
                && $b['organization']['meta']['href'] === 'https://ms.test/api/entity/organization/org-1'
                && $b['sourceStore']['meta']['href'] === 'https://ms.test/api/entity/store/store-from'
                && $b['targetStore']['meta']['href'] === 'https://ms.test/api/entity/store/store-to'
                && count($b['positions']) === 2
                && $b['positions'][0]['quantity'] == 2.5
                && $b['positions'][0]['assortment']['meta']['href'] === 'https://ms.test/api/entity/product/prod-1'
                && $b['positions'][1]['quantity'] == 3.0
                && $b['positions'][1]['assortment']['meta']['href'] === 'https://ms.test/api/entity/product/prod-2'
                && $b['name'] === 'Партия: 42'
                && $b['description'] === 'Автоматическое перемещение'
                && $b['externalCode'] === 'movement_7'
                // Время приложения UTC+5 → МойСклад UTC+3
                && $b['moment'] === '2026-05-10 10:00:00';
        });
    });

    test('без необязательных полей не отправляет name/description/externalCode/moment', function () {
        mvHttpFake(['POST /entity/move' => Http::response(['id' => 'move-new'])]);

        $result = (new MoySkladMoveService())->createMove(mvMoveData());

        expect($result['success'])->toBeTrue()->and($result['external_id'])->toBeNull();
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && !array_key_exists('name', $r->data())
            && !array_key_exists('description', $r->data())
            && !array_key_exists('externalCode', $r->data())
            && !array_key_exists('moment', $r->data()));
    });

    test('пропускает позиции с нулевым количеством, без id и с ненайденным товаром', function () {
        mvHttpFake(['POST /entity/move' => Http::response(['id' => 'move-new'])], ['/entity/product/prod-missing']);

        $result = (new MoySkladMoveService())->createMove(mvMoveData(['products' => [
            ['product_id' => 'prod-zero', 'quantity' => 0],
            ['quantity' => 5],
            ['product_id' => 'prod-missing', 'quantity' => 1],
            ['product_id' => 'prod-ok', 'quantity' => 4],
        ]]));

        expect($result['success'])->toBeTrue();
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && count($r->data()['positions']) === 1
            && str_ends_with($r->data()['positions'][0]['assortment']['meta']['href'], '/product/prod-ok'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/product/prod-zero'));
    });

    test('все позиции невалидны — перемещение не отправляется', function () {
        mvHttpFake([], ['/entity/product/prod-1']);

        $result = (new MoySkladMoveService())->createMove(mvMoveData());

        expect($result['success'])->toBeFalse()
            ->and($result['message'])->toContain('ни одного товара');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    });

    test('ошибка API — текст из errors[0].error, код api_error, без исключения', function () {
        mvHttpFake(['POST /entity/move' => Http::response(['errors' => [['error' => 'Недостаточно товара', 'code' => 3007]]], 412)]);

        $result = (new MoySkladMoveService())->createMove(mvMoveData());

        expect($result['success'])->toBeFalse()
            ->and($result['move_id'])->toBeNull()
            ->and($result['code'])->toBe('api_error')
            ->and($result['message'])->toBe('Ошибка МойСклад: Недостаточно товара');
    });

    test('коллизия имени — код duplicate_name', function () {
        mvHttpFake(['POST /entity/move' => Http::response(['errors' => [['error' => 'Поле name должно быть уникальным', 'code' => 3006]]], 412)]);

        $result = (new MoySkladMoveService())->createMove(mvMoveData(['name' => 'Партия: 1']));

        expect($result['success'])->toBeFalse()
            ->and($result['code'])->toBe('duplicate_name');
    });

    test('нет организации — понятная ошибка, POST не отправляется', function () {
        mvHttpFake(['GET /entity/organization' => Http::response(['rows' => []])]);

        $result = (new MoySkladMoveService())->createMove(mvMoveData());

        expect($result['success'])->toBeFalse()
            ->and($result['message'])->toContain('организации');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    });

    test('склад не найден — понятная ошибка', function () {
        mvHttpFake([], ['/entity/store/store-to']);

        $result = (new MoySkladMoveService())->createMove(mvMoveData());

        expect($result['success'])->toBeFalse()
            ->and($result['message'])->toContain('складов');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    });

    test('сетевое исключение не выбрасывается наружу', function () {
        mvHttpFake(['POST /entity/move' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

        $result = (new MoySkladMoveService())->createMove(mvMoveData());

        expect($result['success'])->toBeFalse()
            ->and($result['message'])->toContain('timeout');
    });

    test('без токена запросы не отправляются', function () {
        config()->set('services.moysklad.token', null);
        Http::fake();

        $result = (new MoySkladMoveService())->createMove(mvMoveData());

        expect($result['success'])->toBeFalse()->and($result['message'])->toContain('токен');
        Http::assertNothingSent();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// MoySkladMoveService::updateMove() — HTTP
// ══════════════════════════════════════════════════════════════════════════════

describe('MoySkladMoveService::updateMove() — HTTP', function () {

    test('отправляет PUT по id перемещения с новыми позициями', function () {
        mvHttpFake(['PUT /entity/move/move-1' => Http::response(['id' => 'move-1'])]);

        $result = (new MoySkladMoveService())->updateMove('move-1', mvMoveData([
            'products' => [['product_id' => 'prod-1', 'quantity' => 7.125]],
            'name'     => 'Партия: 5',
        ]));

        expect($result['success'])->toBeTrue()->and($result['move_id'])->toBe('move-1');
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/entity/move/move-1')
            && $r->data()['positions'][0]['quantity'] == 7.125
            && $r->data()['sourceStore']['meta']['href'] === 'https://ms.test/api/entity/store/store-from'
            && $r->data()['name'] === 'Партия: 5');
    });

    test('ошибка API — текст из errors[0].error', function () {
        mvHttpFake(['PUT /entity/move/move-1' => Http::response(['errors' => [['error' => 'Документ заблокирован']]], 412)]);

        $result = (new MoySkladMoveService())->updateMove('move-1', mvMoveData());

        expect($result['success'])->toBeFalse()
            ->and($result['message'])->toBe('Ошибка МойСклад: Документ заблокирован');
    });

    test('склад не найден — PUT не отправляется', function () {
        mvHttpFake([], ['/entity/store/store-from']);

        $result = (new MoySkladMoveService())->updateMove('move-1', mvMoveData());

        expect($result['success'])->toBeFalse()->and($result['message'])->toContain('складов');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// MoySkladMoveService::deleteMove() — HTTP
// ══════════════════════════════════════════════════════════════════════════════

describe('MoySkladMoveService::deleteMove() — HTTP', function () {

    test('отправляет DELETE по id перемещения', function () {
        mvHttpFake(['DELETE /entity/move/move-9' => Http::response(null, 200)]);

        $result = (new MoySkladMoveService())->deleteMove('move-9');

        expect($result['success'])->toBeTrue();
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/entity/move/move-9'));
    });

    test('ошибка API — success=false с HTTP-кодом, без исключения', function () {
        mvHttpFake(['DELETE /entity/move/move-9' => Http::response(['errors' => [['title' => 'Не найдено', 'error' => 'Объект не найден']]], 404)]);

        $result = (new MoySkladMoveService())->deleteMove('move-9');

        expect($result['success'])->toBeFalse()
            ->and($result['message'])->toContain('Объект не найден')
            ->and($result['message'])->toContain('HTTP 404');
    });

    test('ответ только с title — берётся он', function () {
        mvHttpFake(['DELETE /entity/move/move-9' => Http::response(['errors' => [['title' => 'Не найдено']]], 404)]);

        expect((new MoySkladMoveService())->deleteMove('move-9')['message'])->toContain('Не найдено');
    });

    test('без токена запрос не отправляется', function () {
        config()->set('services.moysklad.token', '');
        Http::fake();

        $result = (new MoySkladMoveService())->deleteMove('move-9');

        expect($result['success'])->toBeFalse()->and($result['message'])->toContain('токен');
        Http::assertNothingSent();
    });
});
