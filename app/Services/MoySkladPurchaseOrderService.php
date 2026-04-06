<?php

namespace App\Services;

use App\Models\SupplierOrder;
use App\Support\DocumentNaming;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MoySkladPurchaseOrderService
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
     * Проверить, существует ли заказ поставщику в МойСклад.
     */
    public function checkExists(string $moyskladId): bool
    {
        try {
            $response = Http::withHeaders([
                'Authorization'   => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
            ])->get($this->baseUrl . '/entity/purchaseorder/' . $moyskladId);
            return $response->successful();
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Создать заказ поставщику в МойСклад и вернуть результат.
     * $name — опциональное переопределение имени (для суффикса _01 и т.п.)
     */
    public function createPurchaseOrder(SupplierOrder $order, ?string $name = null): array
    {
        $result = ['success' => false, 'moysklad_id' => null, 'code' => '', 'message' => ''];

        if (!$this->hasCredentials()) {
            $result['message'] = 'MoySklad токен не установлен';
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
                $result['message'] = 'Нет позиций для отправки в МойСклад';
                return $result;
            }

            $body = [
                'name'         => $name ?? $order->number,
                'organization' => ['meta' => $orgMeta],
                'agent'        => ['meta' => $agentMeta],
                'store'        => ['meta' => $storeMeta],
                'moment'       => $order->created_at->format('Y-m-d H:i:s.000'),
                'positions'    => $positions,
            ];

            if ($order->note) {
                $body['description'] = $order->note;
            }

            $response = Http::withHeaders([
                'Authorization'  => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
                'Content-Type'   => 'application/json',
            ])->post($this->baseUrl . '/entity/purchaseorder', $body);

            if (!$response->successful()) {
                $errors   = $response->json()['errors'] ?? [];
                $errorMsg = $errors[0]['error'] ?? $errors[0]['title'] ?? 'Неизвестная ошибка';
                Log::error('Ошибка создания заказа поставщику в МойСклад', [
                    'status'   => $response->status(),
                    'response' => $response->json(),
                    'order_id' => $order->id,
                ]);
                $result['code']    = DocumentNaming::isDuplicateName($errors) ? 'duplicate_name' : 'api_error';
                $result['message'] = 'Ошибка МойСклад: ' . $errorMsg;
                return $result;
            }

            $data = $response->json();
            $result['success']     = true;
            $result['moysklad_id'] = $data['id'] ?? null;
            $result['message']     = 'Заказ успешно создан в МойСклад';

            Log::info('Заказ поставщику создан в МойСклад', [
                'moysklad_id' => $result['moysklad_id'],
                'order_id'    => $order->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Исключение при создании заказа поставщику в МойСклад', [
                'error'    => $e->getMessage(),
                'order_id' => $order->id,
            ]);
            $result['message'] = 'Ошибка: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Удалить заказ поставщику из МойСклад.
     */
    public function deletePurchaseOrder(string $moyskladId): array
    {
        $result = ['success' => false, 'message' => ''];

        if (!$this->hasCredentials()) {
            $result['message'] = 'MoySklad токен не установлен';
            return $result;
        }

        try {
            $response = Http::withHeaders([
                'Authorization'   => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
            ])->delete($this->baseUrl . '/entity/purchaseorder/' . $moyskladId);

            if ($response->successful() || $response->status() === 200) {
                $result['success'] = true;
                $result['message'] = 'Заказ удалён из МойСклад';
            } else {
                $errorMsg = $response->json()['errors'][0]['title'] ?? 'Неизвестная ошибка';
                Log::error('Ошибка удаления заказа поставщику из МойСклад', [
                    'status'      => $response->status(),
                    'response'    => $response->json(),
                    'moysklad_id' => $moyskladId,
                ]);
                $result['message'] = 'Ошибка МойСклад: ' . $errorMsg;
            }
        } catch (\Exception $e) {
            Log::error('Исключение при удалении заказа из МойСклад', [
                'error'       => $e->getMessage(),
                'moysklad_id' => $moyskladId,
            ]);
            $result['message'] = 'Ошибка: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Обновить заказ поставщику в МойСклад (позиции и примечание).
     */
    public function updatePurchaseOrder(SupplierOrder $order): array
    {
        $result = ['success' => false, 'message' => ''];

        if (!$this->hasCredentials()) {
            $result['message'] = 'MoySklad токен не установлен';
            return $result;
        }

        if (!$order->moysklad_id) {
            $result['message'] = 'Нет moysklad_id — создаём заново';
            return $this->createPurchaseOrder($order);
        }

        try {
            $positions = $this->buildPositions($order);
            if (empty($positions)) {
                $result['message'] = 'Нет позиций для отправки';
                return $result;
            }

            $body = ['positions' => $positions];
            if ($order->note !== null) {
                $body['description'] = $order->note;
            }

            $response = Http::withHeaders([
                'Authorization'   => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
                'Content-Type'    => 'application/json',
            ])->put($this->baseUrl . '/entity/purchaseorder/' . $order->moysklad_id, $body);

            if ($response->successful()) {
                $result['success'] = true;
                $result['message'] = 'Заказ обновлён в МойСклад';
                Log::info('Заказ поставщику обновлён в МойСклад', ['moysklad_id' => $order->moysklad_id]);
            } else {
                $errorMsg = $response->json()['errors'][0]['title'] ?? 'Неизвестная ошибка';
                Log::error('Ошибка обновления заказа в МойСклад', [
                    'status'      => $response->status(),
                    'response'    => $response->json(),
                    'order_id'    => $order->id,
                ]);
                $result['message'] = 'Ошибка МойСклад: ' . $errorMsg;
            }
        } catch (\Exception $e) {
            Log::error('Исключение при обновлении заказа в МойСклад', [
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
                'Authorization'  => 'Bearer ' . $this->token,
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
                'Authorization'  => 'Bearer ' . $this->token,
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
                'Authorization'  => 'Bearer ' . $this->token,
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
