<?php

namespace App\Services\Moysklad;

use App\Models\SupplierOrder;
use App\Support\DocumentNaming;
use Illuminate\Support\Facades\Log;

class MoySkladSupplyService extends MoySkladBaseService
{
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

            $response = $this->post('/entity/supply', $body);

            if (!$response->successful()) {
                $errors   = $response->json()['errors'] ?? [];
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
            $result['success']            = true;
            $result['supply_moysklad_id'] = $data['id'] ?? null;
            $result['message']            = 'Приёмка успешно создана в МойСклад';

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
}
