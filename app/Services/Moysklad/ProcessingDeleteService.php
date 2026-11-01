<?php

namespace App\Services\Moysklad;

use App\Services\Moysklad\Concerns\HandlesProcessingSync;

class ProcessingDeleteService extends MoySkladBaseService
{
    use HandlesProcessingSync;

    public function __construct(
        private StockSyncService $stockSyncService,
    ) {
        parent::__construct();
    }

    /**
     * Удалить техоперацию уже удалённого документа и подтянуть остатки товаров.
     * Без id ищет по внешнему коду: документ могли удалить, пока его создание ждало в очереди.
     *
     * @return array ['success', 'message', 'retryable']
     */
    public function deleteDocument(?string $processingId, string $externalCode, array $moyskladIds = []): array
    {
        $this->transientFailure = false;

        if (!$this->hasCredentials()) {
            return ['success' => false, 'message' => 'MoySklad токен не установлен', 'retryable' => false];
        }

        if (!$processingId) {
            $found = $this->findProcessingByExternalCode($externalCode);

            if ($found === false) {
                return ['success' => false, 'message' => 'МойСклад не ответил при поиске техоперации', 'retryable' => true];
            }

            $processingId = $found['id'] ?? null;

            if (!$processingId) {
                return ['success' => true, 'message' => 'Техоперация не создавалась', 'retryable' => false];
            }
        }

        $result = $this->deleteProcessing($processingId);

        if ($result['success']) {
            $this->stockSyncService->refreshProducts($moyskladIds);
        }

        return $result + ['retryable' => $this->transientFailure];
    }
}
