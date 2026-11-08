<?php

namespace App\Services\Moysklad;

use App\Models\RawMaterialBatch;
use App\Models\ReceptionLog;
use App\Models\StoneReception;
use App\Models\Worker;
use App\Support\DepartmentSettings;
use App\Support\Uom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** @extends ProcessingSyncService<StoneReception> */
class StoneReceptionSyncService extends ProcessingSyncService
{
    /**
     * Накладные расходы на единицу продукции для отдела документа —
     * сумма строк `department_expenses`. Наследования из глобальных настроек
     * нет, поэтому без отдела возвращается 0; вызывающий должен передавать
     * StoneReception::effectiveDepartmentId().
     */
    public function manualCostPerUnit(?int $departmentId = null): float
    {
        return DepartmentSettings::overheadPerUnit($departmentId);
    }

    /**
     * Создать техоперацию для приёмки (1 приёмка = 1 техоперация).
     *
     * @return array ['success', 'processing_id', 'processing_name', 'code', 'message']
     */
    public function createProcessingForReception(StoneReception $reception, ?string $customName = null, ?string $description = null): array
    {
        return $this->createProcessing($reception, $customName, $description, function () use ($reception) {
            $reception->loadMissing('rawMaterialBatch.product', 'items.product');

            $storeMeta = $this->getEntityMeta('store', $reception->store_id);
            if (!$storeMeta) {
                throw new \Exception('Не удалось получить данные склада');
            }

            $rawProduct = $reception->rawMaterialBatch->product;
            if (!$rawProduct || !$rawProduct->moysklad_id) {
                throw new \Exception('Товар партии не синхронизирован с МойСклад (нет moysklad_id)');
            }
            $rawProductMeta = $this->getEntityMeta('product', $rawProduct->moysklad_id);
            if (!$rawProductMeta) {
                throw new \Exception('Не удалось получить метаданные сырья из МойСклад');
            }

            $productsGrouped = $this->groupPositions($reception->items);
            if (empty($productsGrouped)) {
                throw new \Exception('Нет продуктов с moysklad_id для создания техоперации');
            }

            $totalQuantity = (float) $reception->items->sum('quantity');

            return [
                'productsStore'  => ['meta' => $storeMeta],
                'materialsStore' => ['meta' => $storeMeta],
                'processingSum'  => $this->processingSum($reception->items, $totalQuantity, $reception->effectiveDepartmentId()),
                'products'       => array_values($productsGrouped),
                'materials'      => [[
                    'quantity'   => (float) $reception->raw_quantity_used,
                    'assortment' => ['meta' => $rawProductMeta],
                ]],
                'quantity'       => $totalQuantity,
            ];
        });
    }

    /**
     * Обновить продукты (и опционально количество материала) в существующей техоперации.
     *
     * @param  string             $processingId       UUID техоперации в МойСклад
     * @param  Collection  $allItems  позиции приёмки (StoneReceptionItem)
     * @param  string             $storeId            UUID склада
     * @param  float|null         $materialQuantity   Новое кол-во сырья (null или 0 = не менять материал)
     * @param  string|null        $materialMoyskladId moysklad_id товара-сырья
     * @param  int|null           $departmentId       Отдел документа — источник накладных расходов
     * @return array ['success', 'code', 'message']
     */
    public function updateProcessingProducts(
        string $processingId,
        Collection $allItems,
        string $storeId,
        ?float $materialQuantity = null,
        ?string $materialMoyskladId = null,
        ?string $description = null,
        ?int $departmentId = null
    ): array {
        return $this->updateProcessing($processingId, $description, function () use (
            $processingId, $allItems, $storeId, $materialQuantity, $materialMoyskladId, $departmentId
        ) {
            $storeMeta = $this->getEntityMeta('store', $storeId);
            if (!$storeMeta) {
                throw new \Exception('Не удалось получить данные склада');
            }

            $productsGrouped = $this->groupPositions($allItems);
            if (empty($productsGrouped)) {
                throw new \Exception('Нет продуктов с moysklad_id для обновления техоперации');
            }

            $totalQuantity = array_sum(array_column($productsGrouped, 'quantity'));

            $payload = [
                'productsStore'  => ['meta' => $storeMeta],
                'materialsStore' => ['meta' => $storeMeta],
                'processingSum'  => $this->processingSum($allItems, $totalQuantity, $departmentId),
                'products'       => $this->withExistingIds($productsGrouped, $processingId, 'products'),
                'quantity'       => $totalQuantity,
            ];

            if ($materialQuantity !== null && $materialQuantity > 0) {
                if (!$materialMoyskladId) {
                    throw new \Exception('Товар партии не синхронизирован с МойСклад (нет moysklad_id)');
                }
                $rawProductMeta = $this->getEntityMeta('product', $materialMoyskladId);
                if (!$rawProductMeta) {
                    throw new \Exception('Не удалось получить метаданные сырья из МойСклад');
                }
                $payload['materials'] = [[
                    'quantity'   => $materialQuantity,
                    'assortment' => ['meta' => $rawProductMeta],
                ]];
            }

            return $payload;
        });
    }

    /**
     * Синхронизирует приёмку с МойСклад: создаёт техоперацию (первая синхронизация)
     * или обновляет продукты/материал (повторная).
     *
     * @return array ['success', 'message', 'retryable', 'created']
     */
    public function syncReception(StoneReception $reception, ?string $customName = null, array $alsoRefresh = []): array
    {
        return $this->sync($reception, $customName, $alsoRefresh);
    }

    /**
     * Задача очереди: синхронизировать приёмку и привести статус техоперации к её статусу.
     *
     * @return array ['success', 'message', 'retryable', 'created']
     */
    public function pushReception(StoneReception $reception, ?string $customName = null, array $alsoRefresh = []): array
    {
        return $this->push($reception, $customName, $alsoRefresh);
    }

    protected function documentType(): string
    {
        return 'reception';
    }

    protected function namePrefix(): string
    {
        return 'ТО';
    }

    protected function relationsForSync(): array
    {
        return ['items.product', 'rawMaterialBatch.product'];
    }

    /** @param  StoneReception  $document */
    protected function syncPrecheck(Model $document): ?string
    {
        return $document->rawMaterialBatch ? null : 'Партия сырья не привязана к приёмке';
    }

    /** @param  StoneReception  $document */
    protected function describe(Model $document): string
    {
        return $this->buildReceptionDescription($document, $document->rawMaterialBatch);
    }

    /** @param  StoneReception  $document */
    protected function createDocument(Model $document, ?string $customName, ?string $description): array
    {
        return $this->createProcessingForReception($document, $customName, $description);
    }

    /** @param  StoneReception  $document */
    protected function updateDocument(Model $document, ?string $description): array
    {
        return $this->updateProcessingProducts(
            $document->moysklad_processing_id,
            $document->items,
            $document->store_id ?? '',
            (float) $document->raw_quantity_used,
            $document->rawMaterialBatch->product->moysklad_id ?? '',
            $description,
            $document->effectiveDepartmentId()
        );
    }

    /**
     * Сырьё партии + вся готовая продукция. Товары «до правки» (убранные позиции,
     * сырьё прежней партии) приходят в sync() через $alsoRefresh.
     *
     * @param  StoneReception  $document
     */
    protected function affectedMoyskladIds(Model $document): iterable
    {
        return collect([$document->rawMaterialBatch?->product?->moysklad_id])
            ->merge($document->items->map(fn ($item) => $item->product?->moysklad_id));
    }

    /**
     * processingSum за единицу: зарплаты пильщика и мастера по позициям + накладные отдела.
     *
     * @param  iterable  $items  StoneReceptionItem
     */
    private function processingSum(iterable $items, float $totalQuantity, ?int $departmentId): int
    {
        [$workerTotal, $masterTotal] = $this->salaryTotals($items);

        return $this->calcProcessingSum(
            $workerTotal + $masterTotal + $this->manualCostPerUnit($departmentId) * $totalQuantity,
            $totalQuantity
        );
    }

    /**
     * Итоги зарплат пильщика и мастера по позициям: ставка × количество.
     *
     * @param  iterable  $items  StoneReceptionItem
     * @return array{0: float, 1: float}
     */
    private function salaryTotals(iterable $items): array
    {
        $worker = 0.0;
        $master = 0.0;
        foreach ($items as $item) {
            $worker += $item->effectiveProdCost() * (float) $item->quantity;
            $master += (float) ($item->master_cost_per_m2 ?? 0) * (float) $item->quantity;
        }

        return [$worker, $master];
    }

    private function buildReceptionDescription(StoneReception $reception, RawMaterialBatch $batch): string
    {
        $batchName = $batch->moysklad_processing_name
            ?? ($batch->batch_number ? "партия №{$batch->batch_number}" : '');

        $logs = ReceptionLog::where('stone_reception_id', $reception->id)
            ->orderBy('created_at')
            ->with('items.product')
            ->get();

        $costSummary = $this->buildCostSummary($reception);

        if ($logs->isEmpty()) {
            return trim($batchName . "\n___\n" . $costSummary, "\n");
        }

        // Теги строки — названия применённых правил отдела: набор у каждого
        // отдела свой, и «подкол/торцовка» перестали быть исчерпывающими.
        $reception->loadMissing('items.modifiers');
        $tagMap = $reception->items->keyBy('product_id')
            ->map(fn ($i) => $i->modifiers->pluck('name')->all());

        $receiverIds = $logs->pluck('receiver_id')->filter()->unique();
        $receivers   = Worker::whereIn('id', $receiverIds)->pluck('name', 'id');

        $blocks = $logs->map(function (ReceptionLog $log) use ($receivers, $tagMap) {
            $date         = $log->created_at->format('d.m.Y');
            $receiverName = $receivers[$log->receiver_id] ?? '—';
            $lines        = ['___', "{$date} #{$log->id} {$receiverName}"];

            foreach ($log->items as $item) {
                $productName = $item->product?->name ?? "Товар #{$item->product_id}";
                $delta       = (float) $item->quantity_delta;
                $sign        = $delta >= 0 ? '+' : '';
                $tags        = $tagMap[$item->product_id] ?? [];
                $suffix      = $tags ? ' (' . implode(', ', $tags) . ')' : '';
                $lines[]     = "{$productName}: {$sign}" . number_format($delta, 3, '.', '') . $suffix;
            }

            return implode("\n", $lines);
        });

        return trim($batchName . "\n" . $blocks->join("\n") . "\n___\n" . $costSummary, "\n");
    }

    /**
     * Расшифровка себестоимости за единицу продукции для примечания техоперации.
     *
     * Зарплаты — средние за единицу по всей приёмке (итог / общее количество),
     * поэтому три строки в сумме дают ровно тот processingSum, который уходит
     * в МойСклад, и его можно проверить глазами.
     */
    private function buildCostSummary(StoneReception $reception): string
    {
        $reception->loadMissing('items.product');

        $totalQuantity = (float) $reception->items->sum('quantity');

        [$workerTotal, $masterTotal] = $this->salaryTotals($reception->items);

        $overhead = $this->manualCostPerUnit($reception->effectiveDepartmentId());
        $worker   = $totalQuantity > 0 ? $workerTotal / $totalQuantity : 0.0;
        $master   = $totalQuantity > 0 ? $masterTotal / $totalQuantity : 0.0;

        // Единица — общая у продукции приёмки; разные или не заданы → «₽/ед.»
        $rate = Uom::rate(Uom::common($reception->items->map(fn ($item) => $item->product?->uom)));

        return implode("\n", [
            'Накладные расходы отдела: ' . $this->formatMoney($overhead) . ' ' . $rate,
            'Зарплата пильщика (средн.): ' . $this->formatMoney($worker) . ' ' . $rate,
            'Зарплата мастера (средн.): ' . $this->formatMoney($master) . ' ' . $rate,
        ]);
    }

    /** Сумма без лишних нулей в дробной части: 430, 430.5, 430.55 */
    private function formatMoney(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ' '), '0'), '.');
    }
}
