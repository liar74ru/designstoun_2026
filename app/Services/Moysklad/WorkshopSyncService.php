<?php

namespace App\Services\Moysklad;

use App\Models\Worker;
use App\Models\Workshop;
use App\Models\WorkshopItem;
use App\Models\WorkshopLog;
use App\Support\DepartmentSettings;
use Illuminate\Database\Eloquent\Model;

/** @extends ProcessingSyncService<Workshop> */
class WorkshopSyncService extends ProcessingSyncService
{
    /**
     * Создать техоперацию для операции цеха.
     *
     * Materials = сырьё (role=raw) + упаковка (role=package);
     * Products  = продукт на выходе (role=product).
     * processingSum = ручные затраты (₽/ед × 100) или авто-fallback: зарплата работника
     * и мастера + накладные отдела.
     *
     * @return array ['success', 'processing_id', 'processing_name', 'code', 'message']
     */
    public function createProcessingForWorkshop(Workshop $workshop, ?string $customName = null, ?string $description = null): array
    {
        return $this->createProcessing($workshop, $customName, $description, function () use ($workshop) {
            [$materialsStoreMeta, $productsStoreMeta] = $this->getStoreMetas($workshop);

            $workshop->loadMissing('items.product');

            $rawGrouped     = $this->groupPositions($workshop->items->where('role', WorkshopItem::ROLE_RAW));
            $packageGrouped = $this->groupPositions($workshop->items->where('role', WorkshopItem::ROLE_PACKAGE));
            $productGrouped = $this->groupPositions($workshop->items->where('role', WorkshopItem::ROLE_PRODUCT));

            if (empty($productGrouped)) {
                throw new \Exception('Нет продуктов на выходе с moysklad_id для создания техоперации');
            }

            $totalQuantity = array_sum(array_column($productGrouped, 'quantity'));

            // Materials = сырьё + упаковка; Products = продукт на выходе.
            return [
                'productsStore'  => ['meta' => $productsStoreMeta],
                'materialsStore' => ['meta' => $materialsStoreMeta],
                'processingSum'  => $this->computeProcessingSum($workshop, $totalQuantity),
                'products'       => array_values($productGrouped),
                'materials'      => array_values($this->mergeGrouped($rawGrouped, $packageGrouped)),
                'quantity'       => $totalQuantity,
            ];
        });
    }

    /**
     * Обновить техоперацию цеха: продукты, материалы (сырьё + упаковка), processingSum, description.
     *
     * @return array ['success', 'code', 'message']
     */
    public function updateProcessingProducts(
        string $processingId,
        Workshop $workshop,
        ?string $description = null
    ): array {
        return $this->updateProcessing($processingId, $description, function () use ($processingId, $workshop) {
            $workshop->loadMissing('items.product');

            [$materialsStoreMeta, $productsStoreMeta] = $this->getStoreMetas($workshop);

            $rawGrouped     = $this->groupPositions($workshop->items->where('role', WorkshopItem::ROLE_RAW));
            $packageGrouped = $this->groupPositions($workshop->items->where('role', WorkshopItem::ROLE_PACKAGE));
            $productGrouped = $this->groupPositions($workshop->items->where('role', WorkshopItem::ROLE_PRODUCT));

            if (empty($productGrouped)) {
                throw new \Exception('Нет продуктов на выходе с moysklad_id для обновления техоперации');
            }

            $totalQuantity = array_sum(array_column($productGrouped, 'quantity'));

            return [
                'productsStore'  => ['meta' => $productsStoreMeta],
                'materialsStore' => ['meta' => $materialsStoreMeta],
                'processingSum'  => $this->computeProcessingSum($workshop, $totalQuantity),
                'products'       => $this->withExistingIds($productGrouped, $processingId, 'products'),
                // Materials = сырьё + упаковка.
                'materials'      => $this->withExistingIds(
                    $this->mergeGrouped($rawGrouped, $packageGrouped),
                    $processingId,
                    'materials'
                ),
                'quantity'       => $totalQuantity,
            ];
        });
    }

    /**
     * Синхронизировать операцию цеха с МойСклад: создать техоперацию или обновить.
     *
     * @return array ['success', 'message', 'retryable', 'created']
     */
    public function syncWorkshop(Workshop $workshop, ?string $customName = null): array
    {
        return $this->sync($workshop, $customName);
    }

    /**
     * Задача очереди: синхронизировать операцию и привести статус техоперации к её статусу.
     *
     * @return array ['success', 'message', 'retryable', 'created']
     */
    public function pushWorkshop(Workshop $workshop, ?string $customName = null): array
    {
        return $this->push($workshop, $customName);
    }

    protected function documentType(): string
    {
        return 'workshop';
    }

    protected function namePrefix(): string
    {
        return 'ЦЕХ';
    }

    /** @param  Workshop  $document */
    protected function describe(Model $document): string
    {
        return $this->buildWorkshopDescription($document);
    }

    /** @param  Workshop  $document */
    protected function createDocument(Model $document, ?string $customName, ?string $description): array
    {
        return $this->createProcessingForWorkshop($document, $customName, $description);
    }

    /** @param  Workshop  $document */
    protected function updateDocument(Model $document, ?string $description): array
    {
        return $this->updateProcessingProducts($document->moysklad_processing_id, $document, $description);
    }

    /**
     * Сырьё, тара и продукт операции.
     *
     * @param  Workshop  $document
     */
    protected function affectedMoyskladIds(Model $document): iterable
    {
        $document->loadMissing('items.product');

        return $document->items->map(fn ($item) => $item->product?->moysklad_id);
    }

    /**
     * Меты складов техоперации: [materialsStore (склад сырья), productsStore (склад продукта)].
     * Для legacy-записей без product_store_id — фолбэк на store_id.
     *
     * @throws \Exception
     */
    private function getStoreMetas(Workshop $workshop): array
    {
        $materialsStoreMeta = $this->getEntityMeta('store', $workshop->store_id ?? '');
        if (!$materialsStoreMeta) {
            throw new \Exception('Не удалось получить данные склада сырья');
        }

        $productStoreId = $workshop->product_store_id ?: $workshop->store_id;
        if ($productStoreId === $workshop->store_id) {
            return [$materialsStoreMeta, $materialsStoreMeta];
        }

        $productsStoreMeta = $this->getEntityMeta('store', $productStoreId);
        if (!$productsStoreMeta) {
            throw new \Exception('Не удалось получить данные склада продукта');
        }

        return [$materialsStoreMeta, $productsStoreMeta];
    }

    /**
     * Объединить группы позиций по moysklad_id, суммируя количества.
     */
    private function mergeGrouped(array ...$groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            foreach ($group as $moyskladId => $position) {
                if (isset($out[$moyskladId])) {
                    $out[$moyskladId]['quantity'] += $position['quantity'];
                } else {
                    $out[$moyskladId] = $position;
                }
            }
        }

        return $out;
    }

    /**
     * processingSum (копейки/ед): ручные затраты (₽/ед × 100) либо авто-fallback —
     * зафиксированные зарплаты работника и мастера по продукту плюс накладные
     * отдела на единицу, всё поделённое на количество продукта.
     */
    private function computeProcessingSum(Workshop $workshop, float $totalQuantity): int
    {
        if ($workshop->manual_processing_sum !== null) {
            return (int) round((float) $workshop->manual_processing_sum * 100);
        }

        $workerSalaryTotal = 0;
        $masterSalaryTotal = 0;
        foreach ($workshop->items->where('role', WorkshopItem::ROLE_PRODUCT) as $item) {
            $workerSalaryTotal += $item->effectiveProdCost() * (float) $item->quantity;
            $masterSalaryTotal += (float) $item->master_cost_per_m2 * (float) $item->quantity;
        }

        $overheadTotal = DepartmentSettings::overheadPerUnit($workshop->effectiveDepartmentId()) * $totalQuantity;

        return $this->calcProcessingSum($workerSalaryTotal + $masterSalaryTotal + $overheadTotal, $totalQuantity);
    }

    private function buildWorkshopDescription(Workshop $workshop): string
    {
        $header = 'Цех';

        $logs = WorkshopLog::where('workshop_id', $workshop->id)
            ->orderBy('created_at')
            ->with('items.product', 'packer', 'receiver')
            ->get();

        if ($logs->isEmpty()) {
            return $header;
        }

        $packerIds   = $logs->pluck('packer_id')->filter()->unique();
        $packers     = Worker::whereIn('id', $packerIds)->pluck('name', 'id');
        $receiverIds = $logs->pluck('receiver_id')->filter()->unique();
        $receivers   = Worker::whereIn('id', $receiverIds)->pluck('name', 'id');

        $roleLabels = [
            WorkshopItem::ROLE_RAW     => 'Сырьё',
            WorkshopItem::ROLE_PACKAGE => 'Упаковка',
            WorkshopItem::ROLE_PRODUCT => 'Продукт',
        ];

        $blocks = $logs->map(function (WorkshopLog $log) use ($packers, $receivers, $roleLabels) {
            $date         = $log->created_at->format('d.m.Y');
            $packerName   = $packers[$log->packer_id]   ?? '—';
            $receiverName = $receivers[$log->receiver_id] ?? '—';
            $lines        = ['___', "{$date} #{$log->id} работник: {$packerName} / приёмщик: {$receiverName}"];

            foreach ($log->items as $item) {
                $productName = $item->product?->name ?? "Товар #{$item->product_id}";
                $roleLabel   = $roleLabels[$item->role] ?? '';
                $prefix      = $roleLabel ? "[{$roleLabel}] " : '';
                $delta       = (float) $item->quantity_delta;
                $sign        = $delta >= 0 ? '+' : '';
                $lines[]     = "{$prefix}{$productName}: {$sign}" . number_format($delta, 3, '.', '');
            }

            return implode("\n", $lines);
        });

        return trim($header . "\n" . $blocks->join("\n") . "\n___", "\n");
    }
}
