<?php

namespace App\Services\Moysklad;

use App\Models\SupplierOrder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

abstract class MoySkladBaseService
{
    /** Всего попыток запроса, включая первую. */
    private const TRIES = 3;

    private const MAX_RETRY_DELAY_MS = 5000;

    protected string $token;
    protected string $baseUrl;
    protected ?array $organizationMeta = null;

    public function __construct()
    {
        // Без MOYSKLAD_TOKEN в .env конфиг отдаёт null — типизированное свойство упало бы
        // TypeError'ом вместо штатного «токен не установлен».
        $this->token   = (string) config('services.moysklad.token');
        $this->baseUrl = config('services.moysklad.base_url');

        if (empty($this->token)) {
            Log::warning('MOYSKLAD_TOKEN не установлен в .env файле');
        }
    }

    public function hasCredentials(): bool
    {
        return !empty($this->token);
    }

    protected function get(string $endpoint, array $query = []): ?array
    {
        try {
            $response = $this->request()->get($this->baseUrl . $endpoint, $query);

            return $response->successful() ? $response->json() : null;
        } catch (\Exception $e) {
            Log::error('МойСклад GET ошибка', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Несколько GET одновременно (Http::pool) — для постраничных выгрузок, где страницы
     * друг от друга не зависят. Ключи результата — ключи $requests, в том же порядке;
     * неудачный запрос — null, как у get().
     *
     * @param  array<int|string, array{0: string, 1: array}>  $requests  [ключ => [endpoint, query]]
     * @return array<int|string, ?array>
     */
    protected function getMany(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        try {
            $responses = Http::pool(fn (Pool $pool) => collect($requests)
                ->map(fn (array $request, $key) => $this->configure($pool->as((string) $key))
                    ->get($this->baseUrl . $request[0], $request[1]))
                ->all());
        } catch (\Throwable $e) {
            Log::error('МойСклад GET (пул) ошибка', ['error' => $e->getMessage()]);

            return array_fill_keys(array_keys($requests), null);
        }

        $result = [];
        foreach (array_keys($requests) as $key) {
            $response = $responses[(string) $key] ?? null;

            // В пуле сетевая ошибка приходит объектом исключения, а не бросается
            $result[$key] = $response instanceof Response && $response->successful()
                ? $response->json()
                : null;
        }

        return $result;
    }

    /** POST создаёт документ: повтор только там, где МойСклад его точно не принял. */
    protected function post(string $endpoint, array $body): Response
    {
        return $this->request(idempotent: false)->asJson()->post($this->baseUrl . $endpoint, $body);
    }

    protected function put(string $endpoint, array $body): Response
    {
        return $this->request()->asJson()->put($this->baseUrl . $endpoint, $body);
    }

    protected function delete(string $endpoint): Response
    {
        return $this->request()->delete($this->baseUrl . $endpoint);
    }

    private function request(bool $idempotent = true): PendingRequest
    {
        return $this->configure(Http::createPendingRequest(), $idempotent);
    }

    /**
     * Заголовки, таймауты и повторы — одни на все запросы, в т.ч. в пуле (там повтор
     * не блокирует остальные запросы).
     *
     * Повторяем: 429 (лимит запросов — МойСклад отклонил, ничего не выполнив), обрыв
     * соединения до отправки; для GET/PUT/DELETE ещё 502/503/504. Не повторяем: таймаут
     * ответа (запрос мог выполниться, а ждать ещё раз — вдвое дольше) и прочие ошибки —
     * их разбирает вызывающий код. Исчерпав попытки, возвращаем последний ответ;
     * сетевое исключение, как и раньше, ловит вызывающий код.
     */
    private function configure(PendingRequest $request, bool $idempotent = true): PendingRequest
    {
        return $request
            ->withHeaders([
                'Authorization'   => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
            ])
            ->timeout((int) config('services.moysklad.timeout', 20))
            ->connectTimeout((int) config('services.moysklad.connect_timeout', 5))
            ->retry(
                self::TRIES,
                fn (int $attempt, ?Throwable $e) => $this->retryDelayMs($attempt, $e),
                fn (?Throwable $e) => $this->shouldRetry($e, $idempotent),
                throw: false,
            );
    }

    private function shouldRetry(?Throwable $e, bool $idempotent): bool
    {
        if ($e instanceof RequestException) {
            $status = $e->response->status();

            return $status === 429 || ($idempotent && in_array($status, [502, 503, 504], true));
        }

        // cURL 6/7 — адрес не разрешился или соединение не установлено: запрос не ушёл,
        // повтор безопасен и для POST. Таймаут (cURL 28) не повторяем.
        return $e instanceof ConnectionException
            && preg_match('/cURL error (6|7)\b/', $e->getMessage()) === 1;
    }

    private function retryDelayMs(int $attempt, ?Throwable $e): int
    {
        $base = (int) config('services.moysklad.retry_delay_ms', 500);

        if ($e instanceof RequestException && $e->response->status() === 429) {
            // МойСклад сообщает, сколько ждать до сброса лимита
            $interval = (int) $e->response->header('X-Lognex-Retry-TimeInterval');
            if ($interval > 0) {
                return min($interval, self::MAX_RETRY_DELAY_MS);
            }
        }

        return min($base * 2 ** ($attempt - 1), self::MAX_RETRY_DELAY_MS);
    }

    public function getOrganizationMeta(): ?array
    {
        if ($this->organizationMeta) {
            return $this->organizationMeta;
        }

        $data = $this->get('/entity/organization');
        $rows = $data['rows'] ?? [];

        if (empty($rows)) {
            Log::error('Организации не найдены в МойСклад');
            return null;
        }

        return $this->organizationMeta = $rows[0]['meta'];
    }

    protected function getEntityMeta(string $type, string $id): ?array
    {
        $data = $this->get('/entity/' . $type . '/' . $id);
        return $data['meta'] ?? null;
    }

    protected function buildOrderPositions(SupplierOrder $order): array
    {
        $positions = [];

        foreach ($order->items()->with('product')->get() as $item) {
            $product = $item->product;
            if (!$product?->moysklad_id) {
                Log::warning('Товар не синхронизирован с МойСклад, пропускаем', [
                    'product_id' => $item->product_id,
                ]);
                continue;
            }

            $priceKopecks = (int) round($product->effectiveBuyPrice() * 100);

            $positions[] = [
                'quantity'   => (float) $item->quantity,
                'price'      => $priceKopecks,
                'assortment' => [
                    'meta' => [
                        'href'      => $this->baseUrl . '/entity/product/' . $product->moysklad_id,
                        'type'      => 'product',
                        'mediaType' => 'application/json',
                    ],
                ],
            ];
        }

        return $positions;
    }
}
