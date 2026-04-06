<?php

namespace App\Services;

use App\Models\SupplierOrder;
use App\Support\DocumentNaming;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MoySkladSupplyService
{
    private string $token;
    private string $baseUrl;
    private ?array $organizationMeta = null;

    public function __construct()
    {
        $this->token   = config('services.moysklad.token');
        $this->baseUrl = config('services.moysklad.base_url');
    }

    public function hasCredentials(): bool
    {
        return !empty($this->token);
    }

    /**
     * Создать Приёмку в МойСклад на основе Заказа поставщику.
     * $name — опциональное переопределение имени (для суффикса _01 и т.п.)
     * Возвращает ['success' => bool, 'supply_moysklad_id' => string|null, 'code' => string, 'message' => string]
     */
    public function createSupply(SupplierOrder $order, ?string $name = null): array
    {
        $result = ['success' => false, 'supply_moysklad_id' => null, 'code' => '', 'message' => ''];

        if (!$this->hasCredentials()) {
            $result['message'] = 'MoySklad токен не установлен';
            return $result;
        }

        if (!$order->moysklad_id) {
            $result['message'] = 'Заказ поставщику не синхронизирован с МойСклад (нет moysklad_id)';
            return $result;
        }

        try {
            $orgMeta = $this->getOrganizationMeta();
            if (!$orgMeta) {
                $result['message'] = 'Не удалось получить данные организации из МойСклад';
                return $result;
            }

            $storeMeta = $this->getStoreMeta($order->store_id);
            if (!$storeMeta) {
                $result['message'] = 'Не удалось получить метаданные склада из МойСклад';
                return $result;
            }

            $counterparty = $order->counterparty;
            if (!$counterparty?->moysklad_id) {
                $result['message'] = 'Контрагент не синхронизирован с МойСклад';
                return $result;
            }
            $agentMeta = $this->getCounterpartyMeta($counterparty->moysklad_id);
            if (!$agentMeta) {
                $result['message'] = 'Не удалось получить метаданные контрагента из МойСклад';
                return $result;
            }

            $positions = $this->buildPositions($order);
            if (empty($positions)) {
                $result['message'] = 'Нет позиций для передачи в МойСклад';
                return $result;
            }

            $body = [
                'name'          => $name ?? $order->number,
                'organization'  => ['meta' => $orgMeta],
                'agent'         => ['meta' => $agentMeta],
                'store'         => ['meta' => $storeMeta],
                'moment'        => $order->created_at->format('Y-m-d H:i:s.000'),
                'purchaseOrder' => [
                    'meta' => [
                        'href'      => $this->baseUrl . '/entity/purchaseorder/' . $order->moysklad_id,
                        'type'      => 'purchaseorder',
                        'mediaType' => 'application/json',
                    ],
                ],
                'positions' => $positions,
            ];

            if ($order->note) {
                $body['description'] = $order->note;
            }

            $response = Http::withHeaders([
                'Authorization'   => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
                'Content-Type'    => 'application/json',
            ])->post($this->baseUrl . '/entity/supply', $body);

            if (!$response->successful()) {
                $errors  = $response->json()['errors'] ?? [];
                $errorMsg = $errors[0]['error'] ?? $errors[0]['title'] ?? 'Неизвестная ошибка';
                Log::error('Ошибка создания приёмки в МойСклад', [
                    'status'   => $response->status(),
                    'response' => $response->json(),
                    'order_id' => $order->id,
                ]);
                $result['code']    = DocumentNaming::isDuplicateName($errors) ? 'duplicate_name' : 'api_error';
                $result['message'] = 'Ошибка МойСклад: ' . $errorMsg;
                return $result;
            }

            $data = $response->json();
            $result['success']             = true;
            $result['supply_moysklad_id']  = $data['id'] ?? null;
            $result['message']             = 'Приёмка успешно создана в МойСклад';

            Log::info('Приёмка создана в МойСклад', [
                'supply_moysklad_id' => $result['supply_moysklad_id'],
                'order_id'           => $order->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Исключение при создании приёмки в МойСклад', [
                'error'    => $e->getMessage(),
                'order_id' => $order->id,
            ]);
            $result['message'] = 'Ошибка: ' . $e->getMessage();
        }

        return $result;
    }

    private function buildPositions(SupplierOrder $order): array
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

    private function getOrganizationMeta(): ?array
    {
        if ($this->organizationMeta) {
            return $this->organizationMeta;
        }

        try {
            $response = Http::withHeaders([
                'Authorization'   => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
            ])->get($this->baseUrl . '/entity/organization');

            if (!$response->successful()) {
                return null;
            }

            $rows = $response->json()['rows'] ?? [];
            if (empty($rows)) {
                return null;
            }

            $this->organizationMeta = $rows[0]['meta'];
            return $this->organizationMeta;

        } catch (\Exception $e) {
            Log::error('Ошибка получения метаданных организации', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getStoreMeta(string $storeId): ?array
    {
        try {
            $response = Http::withHeaders([
                'Authorization'   => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
            ])->get($this->baseUrl . '/entity/store/' . $storeId);

            if (!$response->successful()) {
                return null;
            }

            return $response->json()['meta'] ?? null;

        } catch (\Exception $e) {
            Log::error('Ошибка получения метаданных склада', ['store_id' => $storeId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function getCounterpartyMeta(string $moyskladId): ?array
    {
        try {
            $response = Http::withHeaders([
                'Authorization'   => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
            ])->get($this->baseUrl . '/entity/counterparty/' . $moyskladId);

            if (!$response->successful()) {
                return null;
            }

            return $response->json()['meta'] ?? null;

        } catch (\Exception $e) {
            Log::error('Ошибка получения метаданных контрагента', ['moysklad_id' => $moyskladId, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
