<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Отправка документа в МойСклад: приёмка и цех — техоперация, партия — перемещение.
 * Подробно — docs/moysklad-sync-pattern.md.
 *
 * Исключений наружу не бросает: сервисы отвечают массивом, а исход решает settle().
 * Уникальность задаче не нужна: она читает запись при выполнении, лишний запуск — обычный PUT.
 */
abstract class SyncDocumentJob implements ShouldQueue
{
    use Queueable;

    /** Паузы между попытками, с. Всего попыток на одну больше. */
    public const BACKOFF = [30, 60, 120, 300, 900];

    public int $tries = 6;

    public int $timeout = 300;

    /** Документ удалили, пока задача ждала: отправлять нечего. */
    public bool $deleteWhenMissingModels = true;

    /** @return Model документ с HasMoyskladSync */
    abstract protected function document(): Model;

    /**
     * Временный сбой — ещё попытка, документ остаётся «Ожидает». Ошибку или успех сервис уже
     * записал. Успех с временным сбоем тоже повторяем: некритичный GET мог не прочитаться,
     * например id позиций. Товар из документа не выпадает: без него POST/PUT не уходит.
     */
    protected function settle(array $outcome): void
    {
        if (!$outcome['retryable'] || $this->attempts() >= $this->tries) {
            return;
        }

        Log::warning(static::class . ': временный сбой, задача повторится', [
            'document_id' => $this->document()->getKey(),
            'attempt'     => $this->attempts(),
            'message'     => $outcome['message'],
        ]);

        $this->document()->markSyncPending();
        $this->release(self::BACKOFF[$this->attempts() - 1]);
    }

    /** Воркер упал или вышел по таймауту: без этого документ навсегда остался бы «Ожидает». */
    public function failed(?Throwable $e): void
    {
        $this->document()->markSyncError('Не удалось отправить в МойСклад: ' . ($e?->getMessage() ?? 'задача прервана'));
    }
}
