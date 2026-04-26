<?php

namespace App\Services\Moysklad;

use App\Models\SupplierOrder;
use App\Support\DocumentNaming;
use Illuminate\Support\Facades\Log;

class MoySkladPurchaseOrderService extends MoySkladBaseService
{
    /**
     * Проверить, существует ли заказ поставщику в МойСклад.
     */
    public function checkExists(string $moyskladId): bool
    {
        return $this->get('/entity/purchaseorder/' . $moyskladId) !== null;
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

            $storeMeta = $this->getEntityMeta('store', $order->store_id);
            if (!$storeMeta) {
                $result['message'] = 'Не удалось получить метаданные склада из МойСклад';
                return $result;
            }

            $counterparty = $order->counterparty;
            if (!$counterparty?->moysklad_id) {
                $result['message'] = 'Контрагент не синхронизирован с МойСклад';
                return $result;
            }
            $agentMeta = $this->getEntityMeta('counterparty', $counterparty->moysklad_id);
            if (!$agentMeta) {
                $result['message'] = 'Не удалось получить метаданные контрагента из МойСклад';
                return $result;
            }

            $positions = $this->buildOrderPositions($order);
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

            $response = $this->post('/entity/purchaseorder', $body);

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
            $response = $this->delete('/entity/purchaseorder/' . $moyskladId);

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
            $counterparty = $order->counterparty;
            if (!$counterparty?->moysklad_id) {
                $result['message'] = 'Контрагент не синхронизирован с МойСклад';
                return $result;
            }
            $agentMeta = $this->getEntityMeta('counterparty', $counterparty->moysklad_id);
            if (!$agentMeta) {
                $result['message'] = 'Не удалось получить метаданные контрагента из МойСклад';
                return $result;
            }

            $positions = $this->buildOrderPositions($order);
            if (empty($positions)) {
                $result['message'] = 'Нет позиций для отправки';
                return $result;
            }

            $body = [
                'agent'     => ['meta' => $agentMeta],
                'positions' => $positions,
            ];
            if ($order->note !== null) {
                $body['description'] = $order->note;
            }

            $response = $this->put('/entity/purchaseorder/' . $order->moysklad_id, $body);

            if ($response->successful()) {
                $result['success'] = true;
                $result['message'] = 'Заказ обновлён в МойСклад';
                Log::info('Заказ поставщику обновлён в МойСклад', ['moysklad_id' => $order->moysklad_id]);
            } else {
                $errorMsg = $response->json()['errors'][0]['error'] ?? 'Неизвестная ошибка';
                Log::error('Ошибка обновления заказа в МойСклад', [
                    'status'   => $response->status(),
                    'response' => $response->json(),
                    'order_id' => $order->id,
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
}
