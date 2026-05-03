<?php

namespace App\Services\Moysklad\Concerns;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;

trait HandlesProcessingSync
{
    private array $processingStatesCache = [];

    /**
     * processingSum в копейках за единицу.
     * МойСклад хранит и умножает это значение на quantity самостоятельно.
     */
    protected function calcProcessingSum(float $totalRubles, float $totalQty): int
    {
        if ($totalQty <= 0) {
            return 0;
        }
        return (int) round($totalRubles * 100 / $totalQty);
    }

    /**
     * Найти href статуса техоперации по его имени.
     * Загружает список статусов из /entity/processing/metadata один раз за запрос.
     */
    protected function getProcessingStateHref(string $name): ?string
    {
        if (empty($this->processingStatesCache)) {
            $data = $this->get('/entity/processing/metadata');
            if ($data) {
                foreach ($data['states'] ?? [] as $state) {
                    $this->processingStatesCache[$state['name']] = $state['meta']['href'];
                }
            } else {
                Log::warning(static::class . '::getProcessingStateHref: не удалось загрузить метаданные техопераций');
            }
        }

        return $this->processingStatesCache[$name] ?? null;
    }

    /**
     * Извлечь текст ошибки из тела ответа МойСклад.
     * Возвращает сообщение из errors[0].error или .title, либо запасной вариант.
     */
    protected function extractApiError(?array $body): string
    {
        $errors = $body['errors'] ?? [];
        return $errors[0]['error'] ?? $errors[0]['title'] ?? 'Неизвестная ошибка';
    }

    /**
     * Получить id существующих позиций техоперации для секции (products / materials).
     * Возвращает map: moysklad_assortment_uuid → position_id.
     */
    protected function fetchExistingPositionIds(string $processingId, string $section = 'products'): array
    {
        $data = $this->get('/entity/processing/' . $processingId, [
            'expand' => $section . '.assortment',
            'limit'  => 100,
        ]);

        if (!$data) {
            return [];
        }

        $positions = $data[$section]['rows'] ?? [];
        $map = [];
        foreach ($positions as $pos) {
            $assortmentHref = $pos['assortment']['meta']['href'] ?? '';
            $productId = basename(parse_url($assortmentHref, PHP_URL_PATH));
            if ($productId && isset($pos['id'])) {
                $map[$productId] = $pos['id'];
            }
        }
        return $map;
    }

    /**
     * Перевести техоперацию в указанный статус.
     *
     * @return array ['success', 'code', 'message']
     */
    protected function updateProcessingState(string $processingId, string $stateName, string $context): array
    {
        $result = ['success' => false, 'code' => '', 'message' => ''];
        $logPrefix = static::class . "::{$context}";

        try {
            if (!$this->hasCredentials()) {
                throw new \Exception('MoySklad токен не установлен');
            }

            if (empty($stateName)) {
                throw new \Exception("Не задано имя статуса для контекста {$context}");
            }

            $stateMetaHref = $this->getProcessingStateHref($stateName);
            if (empty($stateMetaHref)) {
                throw new \Exception("Статус «{$stateName}» не найден в МойСклад");
            }

            $payload = [
                'state' => [
                    'meta' => [
                        'href'      => $stateMetaHref,
                        'type'      => 'state',
                        'mediaType' => 'application/json',
                    ],
                ],
            ];

            $response = $this->put('/entity/processing/' . $processingId, $payload);

            if (!$response->successful()) {
                $result['code']    = 'api_error';
                $result['message'] = 'Ошибка МойСклад: ' . $this->extractApiError($response->json());
                Log::error("{$logPrefix}: ошибка API", [
                    'processing_id' => $processingId,
                    'status'        => $response->status(),
                    'response'      => $response->json(),
                ]);
                return $result;
            }

            $result['success'] = true;
            $result['message'] = 'Статус техоперации обновлён';
            Log::info("{$logPrefix}: успешно", ['processing_id' => $processingId]);
            return $result;

        } catch (\Exception $e) {
            $result['code']    = 'exception';
            $result['message'] = 'Ошибка: ' . $e->getMessage();
            Log::error("{$logPrefix}: исключение", [
                'processing_id' => $processingId,
                'error'         => $e->getMessage(),
            ]);
            return $result;
        }
    }

    /**
     * Перевести техоперацию в завершённый статус (MOYSKLAD_DONE_STATE).
     *
     * @return array ['success', 'code', 'message']
     */
    public function completeProcessing(string $processingId): array
    {
        return $this->updateProcessingState(
            $processingId,
            (string) Setting::get('MOYSKLAD_DONE_STATE', ''),
            'completeProcessing'
        );
    }

    /**
     * Вернуть техоперацию в статус «В работе» (MOYSKLAD_IN_WORK_STATE).
     *
     * @return array ['success', 'code', 'message']
     */
    public function reactivateProcessing(string $processingId): array
    {
        return $this->updateProcessingState(
            $processingId,
            (string) Setting::get('MOYSKLAD_IN_WORK_STATE', ''),
            'reactivateProcessing'
        );
    }
}
