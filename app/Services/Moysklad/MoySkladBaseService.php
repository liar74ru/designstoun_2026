<?php

namespace App\Services\Moysklad;

use App\Models\SupplierOrder;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

abstract class MoySkladBaseService
{
    protected string $token;
    protected string $baseUrl;
    protected ?array $organizationMeta = null;

    public function __construct()
    {
        $this->token   = config('services.moysklad.token');
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
            $response = Http::withHeaders([
                'Authorization'   => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
            ])->get($this->baseUrl . $endpoint, $query);

            return $response->successful() ? $response->json() : null;
        } catch (\Exception $e) {
            Log::error('МойСклад GET ошибка', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);
            return null;
        }
    }

    protected function post(string $endpoint, array $body): Response
    {
        return Http::withHeaders([
            'Authorization'   => 'Bearer ' . $this->token,
            'Accept-Encoding' => 'gzip',
            'Content-Type'    => 'application/json',
        ])->post($this->baseUrl . $endpoint, $body);
    }

    protected function put(string $endpoint, array $body): Response
    {
        return Http::withHeaders([
            'Authorization'   => 'Bearer ' . $this->token,
            'Accept-Encoding' => 'gzip',
            'Content-Type'    => 'application/json',
        ])->put($this->baseUrl . $endpoint, $body);
    }

    protected function delete(string $endpoint): Response
    {
        return Http::withHeaders([
            'Authorization'   => 'Bearer ' . $this->token,
            'Accept-Encoding' => 'gzip',
        ])->delete($this->baseUrl . $endpoint);
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
