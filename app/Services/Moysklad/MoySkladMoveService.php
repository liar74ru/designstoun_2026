<?php

namespace App\Services\Moysklad;

use App\Support\DocumentNaming;
use Illuminate\Support\Facades\Log;
use App\Models\Store;
use App\Models\Product;

class MoySkladMoveService extends MoySkladBaseService
{
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
            $organizationMeta = $this->getOrganizationMeta();
            if (!$organizationMeta) {
                $result['message'] = 'Не удалось получить данные организации';
                return $result;
            }

            $fromStoreMeta = $this->getEntityMeta('store', $data['from_store_id']);
            $toStoreMeta   = $this->getEntityMeta('store', $data['to_store_id']);

            if (!$fromStoreMeta || !$toStoreMeta) {
                $result['message'] = 'Не удалось получить данные складов';
                return $result;
            }

            $positions = $this->preparePositions($data['products'] ?? []);

            if (empty($positions)) {
                $result['message'] = 'Не добавлено ни одного товара в перемещение';
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

            $response = $this->post('/entity/move', $moveData);

            if (!$response->successful()) {
                $errors   = $response->json()['errors'] ?? [];
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
            $result['success']     = true;
            $result['move_id']     = $moveResponse['id'] ?? null;
            $result['external_id'] = $moveResponse['externalCode'] ?? null;
            $result['message']     = 'Перемещение успешно создано';

            Log::info('Перемещение создано в МойСклад', [
                'move_id'    => $result['move_id'],
                'from_store' => $data['from_store_id'],
                'to_store'   => $data['to_store_id'],
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('Исключение при создании перемещения', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $result['message'] = 'Ошибка: ' . $e->getMessage();
            return $result;
        }
    }

    /**
     * Обновить существующее перемещение в МойСклад (PUT /entity/move/{id})
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

            $fromStoreMeta = $this->getEntityMeta('store', $data['from_store_id']);
            $toStoreMeta   = $this->getEntityMeta('store', $data['to_store_id']);

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

            $response = $this->put('/entity/move/' . $moveId, $moveData);

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
            $response = $this->delete('/entity/move/' . $moveId);

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

    private function preparePositions(array $products): array
    {
        $positions = [];

        foreach ($products as $product) {
            try {
                $productId = $product['product_id'] ?? $product['id'] ?? null;
                $quantity  = $product['quantity'] ?? 0;

                if (!$productId || $quantity <= 0) {
                    Log::warning('Некорректные данные товара для перемещения', [
                        'product_id' => $productId,
                        'quantity'   => $quantity,
                    ]);
                    continue;
                }

                $productMeta = $this->getEntityMeta('product', $productId);
                if (!$productMeta) {
                    Log::warning('Не удалось получить метаданные товара', ['product_id' => $productId]);
                    continue;
                }

                $positions[] = [
                    'quantity'   => (float) $quantity,
                    'assortment' => ['meta' => $productMeta],
                ];

            } catch (\Exception $e) {
                Log::warning('Ошибка обработки товара для перемещения', [
                    'product_id' => $productId ?? 'unknown',
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return $positions;
    }
}
