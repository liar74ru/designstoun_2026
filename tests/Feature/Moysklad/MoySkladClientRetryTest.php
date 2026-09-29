<?php

use App\Services\Moysklad\MoySkladBaseService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * HTTP-клиент МойСклад: таймауты и повторы. Повторяются 429, обрыв соединения до
 * отправки, а для GET/PUT/DELETE ещё 502/503/504. POST (создание документа) при 5xx
 * и таймаут ответа не повторяются — запрос мог выполниться, повтор создал бы дубль.
 */

beforeEach(function () {
    config()->set('services.moysklad.token', 'test-token');
    config()->set('services.moysklad.base_url', 'https://api.moysklad.ru/api/remap/1.2');
    Sleep::fake();
});

/** Открывает защищённые методы базового клиента. */
function msClient(): MoySkladBaseService
{
    return new class extends MoySkladBaseService {
        public function callGet(string $endpoint): ?array { return $this->get($endpoint); }
        public function callPost(string $endpoint): \Illuminate\Http\Client\Response { return $this->post($endpoint, ['a' => 1]); }
        public function callPut(string $endpoint): \Illuminate\Http\Client\Response { return $this->put($endpoint, ['a' => 1]); }
        public function callGetMany(array $requests): array { return $this->getMany($requests); }
    };
}

/** Ответы по очереди; исключение в списке — бросается. */
function msSequence(array $responses): Closure
{
    return function () use (&$responses) {
        $next = array_shift($responses);

        return $next instanceof Throwable ? throw $next : $next;
    };
}

describe('Повторы', function () {

    test('GET: 503 повторяется, второй ответ возвращается', function () {
        Http::fake(msSequence([Http::response([], 503), Http::response(['rows' => [1]], 200)]));

        expect(msClient()->callGet('/entity/store'))->toBe(['rows' => [1]]);
        Http::assertSentCount(2);
    });

    test('GET: три неудачи подряд — null, попыток не больше трёх', function () {
        Http::fake(['*' => Http::response([], 503)]);

        expect(msClient()->callGet('/entity/store'))->toBeNull();
        Http::assertSentCount(3);
    });

    test('GET: 500 и 404 не повторяются — это ответ, а не сбой связи', function (int $status) {
        Http::fake(['*' => Http::response([], $status)]);

        expect(msClient()->callGet('/entity/store'))->toBeNull();
        Http::assertSentCount(1);
    })->with([500, 404]);

    test('PUT: 502 повторяется', function () {
        Http::fake(msSequence([Http::response([], 502), Http::response(['id' => 'x'], 200)]));

        expect(msClient()->callPut('/entity/processing/x')->successful())->toBeTrue();
        Http::assertSentCount(2);
    });

    test('POST: 503 не повторяется — документ мог создаться', function () {
        Http::fake(['*' => Http::response(['errors' => [['error' => 'Сбой']]], 503)]);

        $response = msClient()->callPost('/entity/processing');

        expect($response->status())->toBe(503);
        Http::assertSentCount(1);
    });

    test('POST: 429 повторяется через паузу из заголовка МойСклад', function () {
        Http::fake(msSequence([
            Http::response([], 429, ['X-Lognex-Retry-TimeInterval' => '1200']),
            Http::response(['id' => 'new'], 200),
        ]));

        expect(msClient()->callPost('/entity/processing')->json('id'))->toBe('new');
        Http::assertSentCount(2);
        Sleep::assertSequence([Sleep::for(1200)->milliseconds()]);
    });

    test('таймаут ответа не повторяется, исключение — вызывающему коду', function () {
        Http::fake(msSequence([new ConnectionException('cURL error 28: Operation timed out')]));

        expect(fn () => msClient()->callPost('/entity/processing'))->toThrow(ConnectionException::class);
        Http::assertSentCount(0);
    });

    test('соединение не установлено — повтор безопасен и для POST', function () {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            return $attempts === 1
                ? throw new ConnectionException('cURL error 7: Failed to connect')
                : Http::response(['id' => 'new'], 200);
        });

        expect(msClient()->callPost('/entity/processing')->json('id'))->toBe('new');
        expect($attempts)->toBe(2);
    });

    test('пул: страница с 429 повторяется, остальные не страдают', function () {
        $page2 = 0;
        Http::fake(function (Request $request) use (&$page2) {
            if (str_contains($request->url(), 'offset=100')) {
                return ++$page2 === 1 ? Http::response([], 429) : Http::response(['rows' => ['p2']], 200);
            }

            return Http::response(['rows' => ['p1']], 200);
        });

        $result = msClient()->callGetMany([
            0 => ['/entity/customerorder', ['offset' => 0]],
            1 => ['/entity/customerorder', ['offset' => 100]],
        ]);

        expect($result)->toBe([0 => ['rows' => ['p1']], 1 => ['rows' => ['p2']]]);
        expect($page2)->toBe(2);
    });
});

describe('Таймауты', function () {

    test('на запрос ставятся таймаут ответа и соединения из конфига', function () {
        config()->set('services.moysklad.timeout', 17);
        config()->set('services.moysklad.connect_timeout', 4);

        $options = null;
        Http::fake(function (Request $request, array $opts) use (&$options) {
            $options = $opts;

            return Http::response([], 200);
        });

        msClient()->callGet('/entity/store');

        expect($options['timeout'])->toBe(17)
            ->and($options['connect_timeout'])->toBe(4);
    });
});
