<?php

namespace App\Services\Moysklad;

use App\Models\RawMaterialBatch;
use App\Models\RawMaterialMovement;
use App\Support\DocumentNaming;
use Illuminate\Support\Facades\Log;

class RawMaterialBatchSyncService
{
    /** Сколько раз подбирать суффикс занятого имени, как у техопераций. */
    private const NAME_ATTEMPTS = 10;

    public function __construct(
        private MoySkladMoveService $moveService,
        private StockSyncService $stockSyncService,
    ) {}

    /**
     * Задача очереди: привести перемещение в МойСклад к текущей партии. Перемещение равно партии,
     * количество читается при выполнении, поэтому повтор не применит изменение дважды.
     *
     * @param array $alsoRefresh moysklad_id товаров, остатки которых тоже надо перечитать
     * @return array ['success', 'message', 'retryable']
     */
    public function pushBatch(RawMaterialBatch $batch, array $alsoRefresh = []): array
    {
        $this->moveService->resetTransientFailure();

        try {
            $reason = $batch->moveBlockReason();
            if ($reason) {
                $batch->markSyncError($reason);
                return $this->outcome(false, $reason);
            }

            $movement = $batch->primaryMovement();
            if (!$movement->from_store_id || !$movement->to_store_id) {
                $batch->markSyncError('Нет складов перемещения');
                return $this->outcome(false, 'Нет складов перемещения');
            }

            $externalCode = DocumentNaming::externalCode('movement', $movement->id);
            $moveId       = $movement->moysklad_move_id ?? $batch->moysklad_processing_id;
            $name         = $batch->moysklad_processing_name;

            if (!$moveId) {
                // Таймаут мог оборвать ответ на принятый POST: без поиска повтор создал бы второе
                // перемещение, и остатки в МойСклад задвоились бы.
                $found = $this->moveService->findMoveByExternalCode($externalCode);

                if ($found === false) {
                    $message = 'МойСклад не ответил: не удалось проверить, создано ли перемещение';
                    $batch->markSyncError($message);
                    return $this->outcome(false, $message);
                }

                $moveId = $found['id'] ?? null;
                $name   = $found['name'] ?? $name;
            }

            $result = $moveId
                ? $this->moveService->updateMove($moveId, $this->moveData($batch, $movement) + [
                    'name'        => $name,
                    'description' => 'Обновлено через систему. Количество: ' . number_format($batch->moveQuantity(), 3) . ' м³',
                ])
                : $this->createMove($batch, $movement, $externalCode);

            $moveId = $moveId ?: ($result['move_id'] ?? null);
            $name   = $result['name'] ?? $name;

            // МойСклад ответил успехом, но без id документа — считать синхронизацией нельзя
            if ($result['success'] && !$moveId) {
                $result = ['success' => false, 'message' => 'МойСклад не вернул id перемещения'];
            }

            if (!$result['success']) {
                $batch->markSyncError($result['message']);
                Log::warning('pushBatch: не удалось отправить перемещение', [
                    'batch_id' => $batch->id,
                    'message'  => $result['message'],
                ]);
                return $this->outcome(false, $result['message']);
            }

            $movement->update(['moysklad_move_id' => $moveId, 'moysklad_synced' => true]);
            $batch->markSynced($moveId, $name);
            $this->stockSyncService->refreshProducts([$batch->product->moysklad_id, ...$alsoRefresh]);

            return $this->outcome(true, 'Синхронизировано');
        } catch (\Throwable $e) {
            $batch->markSyncError($e->getMessage());
            Log::error('pushBatch: исключение', ['batch_id' => $batch->id, 'error' => $e->getMessage()]);
            return $this->outcome(false, $e->getMessage());
        }
    }

    /**
     * Удалить перемещение уже удалённой партии и подтянуть остатки товаров.
     * Без id ищет по внешнему коду: партию могли удалить, пока её создание ждало в очереди.
     *
     * @return array ['success', 'message', 'retryable']
     */
    public function deleteDocument(?string $moveId, string $externalCode, array $moyskladIds = []): array
    {
        $this->moveService->resetTransientFailure();

        if (!$moveId) {
            $found = $this->moveService->findMoveByExternalCode($externalCode);

            if ($found === false) {
                return $this->outcome(false, 'МойСклад не ответил при поиске перемещения');
            }

            $moveId = $found['id'] ?? null;

            if (!$moveId) {
                return $this->outcome(true, 'Перемещение не создавалось');
            }
        }

        $result = $this->moveService->deleteMove($moveId);

        if ($result['success']) {
            $this->stockSyncService->refreshProducts($moyskladIds);
        }

        return $this->outcome($result['success'], $result['message']);
    }

    private function createMove(RawMaterialBatch $batch, RawMaterialMovement $movement, string $externalCode): array
    {
        $isReturn = $movement->movement_type === 'return_to_store';
        $prefix   = $isReturn ? 'Возврат партии: ' : 'Партия: ';
        $number   = (string) ($batch->batch_number ?? ($isReturn ? '№' . $batch->id : $batch->id));
        $original = $number;
        $result   = [];

        for ($attempt = 0; $attempt < self::NAME_ATTEMPTS; $attempt++) {
            $result = $this->moveService->createMove($this->moveData($batch, $movement) + [
                'name'        => $prefix . $number,
                'description' => $isReturn
                    ? 'Автоматический возврат партии на склад из системы'
                    : 'Автоматическое перемещение из системы',
                'external_id' => $externalCode,
            ]);

            if ($result['success'] || ($result['code'] ?? '') !== 'duplicate_name') {
                break;
            }

            $number = DocumentNaming::nextSuffix($number);
        }

        if ($result['success'] && !$isReturn && $number !== $original) {
            $batch->update(['batch_number' => $number]);
        }

        return $result + ['name' => $prefix . $number];
    }

    /** Общие поля перемещения: склады, товар и дата читаются из записи при выполнении. */
    private function moveData(RawMaterialBatch $batch, RawMaterialMovement $movement): array
    {
        return [
            'from_store_id' => $movement->from_store_id,
            'to_store_id'   => $movement->to_store_id,
            'products'      => [['product_id' => $batch->product->moysklad_id, 'quantity' => $batch->moveQuantity()]],
            'created_at'    => $batch->created_at,
        ];
    }

    private function outcome(bool $success, string $message): array
    {
        return [
            'success'   => $success,
            'message'   => $message,
            'retryable' => $this->moveService->hadTransientFailure(),
        ];
    }
}
