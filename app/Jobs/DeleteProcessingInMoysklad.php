<?php

namespace App\Jobs;

use App\Services\Moysklad\ProcessingDeleteService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Удаление техоперации после локального удаления документа. Записи уже нет, поэтому аргументы —
 * значения, а итог виден только в логе и в failed_jobs: показать ошибку на документе негде.
 */
class DeleteProcessingInMoysklad implements ShouldQueue
{
    use Queueable;

    private const BACKOFF = [30, 60, 120, 300, 900];

    public int $tries = 6;

    public int $timeout = 300;

    /** @param array $moyskladIds товары, остатки которых перечитываются после удаления */
    public function __construct(
        public ?string $processingId,
        public string $externalCode,
        public array $moyskladIds = [],
    ) {}

    public function handle(ProcessingDeleteService $service): void
    {
        $result = $service->deleteDocument($this->processingId, $this->externalCode, $this->moyskladIds);

        if ($result['success']) {
            return;
        }

        if (!$result['retryable'] || $this->attempts() >= $this->tries) {
            $this->fail($result['message']);
            return;
        }

        $this->release(self::BACKOFF[$this->attempts() - 1]);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Не удалось удалить техоперацию в МойСклад', [
            'processing_id' => $this->processingId,
            'external_code' => $this->externalCode,
            'error'         => $e?->getMessage(),
        ]);
    }
}
