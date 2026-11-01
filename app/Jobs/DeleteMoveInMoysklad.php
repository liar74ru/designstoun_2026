<?php

namespace App\Jobs;

use App\Services\Moysklad\RawMaterialBatchSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Удаление перемещения после локального удаления партии. Записи уже нет, поэтому аргументы —
 * значения, а итог виден только в логе и в failed_jobs: показать ошибку на документе негде.
 */
class DeleteMoveInMoysklad implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 300;

    /** @param array $moyskladIds товары, остатки которых перечитываются после удаления */
    public function __construct(
        public ?string $moveId,
        public string $externalCode,
        public array $moyskladIds = [],
    ) {}

    public function handle(RawMaterialBatchSyncService $service): void
    {
        $result = $service->deleteDocument($this->moveId, $this->externalCode, $this->moyskladIds);

        if ($result['success']) {
            return;
        }

        if (!$result['retryable'] || $this->attempts() >= $this->tries) {
            $this->fail($result['message']);
            return;
        }

        $this->release(SyncDocumentJob::BACKOFF[$this->attempts() - 1]);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Не удалось удалить перемещение в МойСклад', [
            'move_id'       => $this->moveId,
            'external_code' => $this->externalCode,
            'error'         => $e?->getMessage(),
        ]);
    }
}
