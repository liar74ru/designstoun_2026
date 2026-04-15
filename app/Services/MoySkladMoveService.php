<?php

namespace App\Services;

use App\Support\DocumentNaming;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Store;
use App\Models\Product;

class MoySkladMoveService
{
    private $token;
    private $baseUrl = 'https://api.moysklad.ru/api/remap/1.2';
    private $organizationMeta;

    public function __construct()
    {

        $this->token = config('services.moysklad.token');
        $this->baseUrl = config('services.moysklad.base_url');

        if (empty($this->token)) {
            Log::warning('MOYSKLAD_TOKEN не установлен в .env файле');
        }
    }

    /**
     * Проверка наличия учетных данных
     */
    public function hasCredentials(): bool
    {
        return !empty($this->token);
    }

    /**
     * Получить метаданные организации
     * Необходимо для создания перемещения
     */
    public function getOrganizationMeta()
    {
        if ($this->organizationMeta) {
            return $this->organizationMeta;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
            ])->get($this->baseUrl . '/entity/organization');

            if (!$response->successful()) {
                Log::error('Ошибка получения данных организации', [
                    'status' => $response->status()
                ]);
                return null;
            }

            $data = $response->json();
            $rows = $data['rows'] ?? [];

            if (empty($rows)) {
                Log::error('Организации не найдены');
                return null;
            }

            // Используем первую организацию
            $this->organizationMeta = $rows[0]['meta'];
            return $this->organizationMeta;

        } catch (\Exception $e) {
            Log::error('Ошибка получения метаданных организации', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Получить метаданные склада по ID
     */
    private function getStoreMeta($storeId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
            ])->get($this->baseUrl . '/entity/store/' . $storeId);

            if (!$response->successful()) {
                Log::error('Ошибка получения данных склада', [
                    'store_id' => $storeId,
                    'status' => $response->status()
                ]);
                return null;
            }

            $data = $response->json();
            return $data['meta'] ?? null;

        } catch (\Exception $e) {
            Log::error('Ошибка получения метаданных склада', [
                'store_id' => $storeId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Создать перемещение в МойСклад
     *
     * @param array $data Массив с данными перемещения
     *   - from_store_id: UUID склада источника
     *   - to_store_id: UUID склада назначения
     *   - products: массив товаров [['product_id' => '...', 'quantity' => 10], ...]
     *
     * @return array ['success' => bool, 'move_id' => string|null, 'message' => string]
     */
    public function createMove(array $data): array
    {
        $result = [
            'success'     => false,
            'move_id'     => null,
            'code'        => '',
            'message'     => '',
            'external_id' => null,
        ];

        if (!$this->hasCredentials()) {
            $result['message'] = 'MoySklad токен не установлен';
            Log::error('Попытка создания перемещения без учетных данных');
            return $result;
        }

        try {
            // Получаем метаданные организации
            $organizationMeta = $this->getOrganizationMeta();
            if (!$organizationMeta) {
                $result['message'] = 'Не удалось получить данные организации';
                return $result;
            }

            // Получаем метаданные складов
            $fromStoreMeta = $this->getStoreMeta($data['from_store_id']);
            $toStoreMeta = $this->getStoreMeta($data['to_store_id']);

            if (!$fromStoreMeta || !$toStoreMeta) {
                $result['message'] = 'Не удалось получить данные складов';
                return $result;
            }

            // Подготавливаем позиции товаров
            $positions = $this->preparePositions($data['products'] ?? []);

            if (empty($positions)) {
                $result['message'] = 'Не добавлено ни одного товара в перемещение';
                return $result;
            }

            // Формируем тело запроса
            $moveData = [
                'organization' => [
                    'meta' => $organizationMeta
                ],
                'sourceStore' => [
                    'meta' => $fromStoreMeta
                ],
                'targetStore' => [
                    'meta' => $toStoreMeta
                ],
                'positions' => $positions
            ];

            // Добавляем опциональные поля если они есть
            if (!empty($data['name'])) {
                $moveData['name'] = $data['name'];
            }

            if (!empty($data['description'])) {
                $moveData['description'] = $data['description'];
            }

            if (!empty($data['external_id'])) {
                $moveData['externalCode'] = $data['external_id'];
            }

            if (!empty($data['created_at'])) {
                $moveData['moment'] = \Carbon\Carbon::parse($data['created_at'])->format('Y-m-d H:i:s');
            }
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
                'Content-Type' => 'application/json'
            ])->post($this->baseUrl . '/entity/move', $moveData);

            if (!$response->successful()) {
                $errors  = $response->json()['errors'] ?? [];
                $errorMsg = $errors[0]['error'] ?? $errors[0]['title'] ?? 'Неизвестная ошибка';
                Log::error('Ошибка создания перемещения в МойСклад', [
                    'status'   => $response->status(),
                    'response' => $response->json(),
                ]);
                $result['code']    = DocumentNaming::isDuplicateName($errors) ? 'duplicate_name' : 'api_error';
                $result['message'] = 'Ошибка МойСклад: ' . $errorMsg;
                return $result;
            }

            $moveResponse = $response->json();
            $result['success'] = true;
            $result['move_id'] = $moveResponse['id'] ?? null;
            $result['external_id'] = $moveResponse['externalCode'] ?? null;
            $result['message'] = 'Перемещение успешно создано';

            Log::info('Перемещение создано в МойСклад', [
                'move_id' => $result['move_id'],
                'from_store' => $data['from_store_id'],
                'to_store' => $data['to_store_id']
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('Исключение при создании перемещения', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $result['message'] = 'Ошибка: ' . $e->getMessage();
            return $result;
        }
    }

    /**
     * Обновить существующее перемещение в МойСклад (PUT /entity/move/{id})
     * Используется при корректировке партии — обновляем исходное перемещение
     * с новым суммарным количеством.
     *
     * @param string $moveId  UUID перемещения в МойСклад
     * @param array  $data    Те же поля что и в createMove + 'new_quantity' для позиций
     */
    public function updateMove(string $moveId, array $data): array
    {
        $result = ['success' => false, 'move_id' => $moveId, 'message' => ''];

        if (!$this->hasCredentials()) {
            $result['message'] = 'MoySklad токен не установлен';
            return $result;
        }

        try {
            $organizationMeta = $this->getOrganizationMeta();
            if (!$organizationMeta) {
                $result['message'] = 'Не удалось получить данные организации';
                return $result;
            }

            $fromStoreMeta = $this->getStoreMeta($data['from_store_id']);
            $toStoreMeta   = $this->getStoreMeta($data['to_store_id']);

            if (!$fromStoreMeta || !$toStoreMeta) {
                $result['message'] = 'Не удалось получить данные складов';
                return $result;
            }

            $positions = $this->preparePositions($data['products'] ?? []);

            if (empty($positions)) {
                $result['message'] = 'Нет позиций для обновления перемещения';
                return $result;
            }

            $moveData = [
                'organization' => ['meta' => $organizationMeta],
                'sourceStore'  => ['meta' => $fromStoreMeta],
                'targetStore'  => ['meta' => $toStoreMeta],
                'positions'    => $positions,
            ];

            if (!empty($data['name']))        $moveData['name']         = $data['name'];
            if (!empty($data['description'])) $moveData['description']  = $data['description'];
            if (!empty($data['external_id'])) $moveData['externalCode'] = $data['external_id'];
            if (!empty($data['created_at']))  $moveData['moment']       = \Carbon\Carbon::parse($data['created_at'])->format('Y-m-d H:i:s');

            $response = Http::withHeaders([
                'Authorization'   => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
                'Content-Type'    => 'application/json',
            ])->put($this->baseUrl . '/entity/move/' . $moveId, $moveData);

            if (!$response->successful()) {
                $errors   = $response->json()['errors'] ?? [];
                $errorMsg = $errors[0]['error'] ?? $errors[0]['title'] ?? 'Неизвестная ошибка';
                Log::error('Ошибка обновления перемещения в МойСклад', [
                    'move_id'  => $moveId,
                    'status'   => $response->status(),
                    'response' => $response->json(),
                ]);
                $result['message'] = 'Ошибка МойСклад: ' . $errorMsg;
                return $result;
            }

            $result['success'] = true;
            $result['message'] = 'Перемещение обновлено';
            Log::info('Перемещение обновлено в МойСклад', ['move_id' => $moveId]);
            return $result;

        } catch (\Exception $e) {
            Log::error('Исключение при обновлении перемещения', ['move_id' => $moveId, 'error' => $e->getMessage()]);
            $result['message'] = 'Ошибка: ' . $e->getMessage();
            return $result;
        }
    }

    /**
     * Удалить перемещение в МойСклад (DELETE /entity/move/{id})
     *
     * @param string $moveId UUID перемещения в МойСклад
     * @return array ['success' => bool, 'message' => string]
     */
    public function deleteMove(string $moveId): array
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
            ])->delete($this->baseUrl . '/entity/move/' . $moveId);

            if ($response->status() === 200 || $response->status() === 204) {
                $result['success'] = true;
                $result['message'] = 'Перемещение удалено';
                Log::info('Перемещение удалено в МойСклад', ['move_id' => $moveId]);
            } else {
                $result['message'] = 'Ошибка API МойСклад: '
                    . ($response->json()['errors'][0]['title'] ?? 'Неизвестная ошибка')
                    . ' (HTTP ' . $response->status() . ')';
                Log::error('Ошибка удаления перемещения в МойСклад', [
                    'move_id'  => $moveId,
                    'status'   => $response->status(),
                    'response' => $response->json(),
                ]);
            }

            return $result;

        } catch (\Exception $e) {
            $result['message'] = 'Ошибка: ' . $e->getMessage();
            Log::error('Исключение при удалении перемещения в МойСклад', [
                'move_id' => $moveId,
                'error'   => $e->getMessage(),
            ]);
            return $result;
        }
    }

    /**
     * Подготовить позиции товаров для перемещения
     */
    private function preparePositions(array $products): array
    {
        $positions = [];

        foreach ($products as $product) {
            try {
                $productId = $product['product_id'] ?? $product['id'] ?? null;
                $quantity = $product['quantity'] ?? 0;

                if (!$productId || $quantity <= 0) {
                    Log::warning('Некорректные данные товара для перемещения', [
                        'product_id' => $productId,
                        'quantity' => $quantity
                    ]);
                    continue;
                }

                // Получаем метаданные товара из МойСклад
                $productMeta = $this->getProductMeta($productId);
                if (!$productMeta) {
                    Log::warning('Не удалось получить метаданные товара', [
                        'product_id' => $productId
                    ]);
                    continue;
                }

                $positions[] = [
                    'quantity' => (float)$quantity,
                    'assortment' => [
                        'meta' => $productMeta
                    ]
                ];

            } catch (\Exception $e) {
                Log::warning('Ошибка обработки товара для перемещения', [
                    'product_id' => $productId ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        return $positions;
    }

    /**
     * Получить метаданные товара
     */
    private function getProductMeta($moyskladId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
            ])->get($this->baseUrl . '/entity/product/' . $moyskladId);

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();
            return $data['meta'] ?? null;

        } catch (\Exception $e) {
            Log::error('Ошибка получения метаданных товара', [
                'product_id' => $moyskladId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

}
