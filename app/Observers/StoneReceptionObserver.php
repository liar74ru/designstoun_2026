<?php

namespace App\Observers;

use App\Models\ReceptionLog;
use App\Models\ReceptionLogItem;
use App\Models\StoneReception;

class StoneReceptionObserver
{
    /**
     * Записывает лог приёмки.
     *
     * При создании (type='created') — все продукты и сырьё идут как положительные дельты.
     *
     * При редактировании (type='updated') — предыдущее состояние продуктов восстанавливается
     * из reception_logs (сумма всех дельт). Дельта сырья = новое значение минус сумма
     * всех предыдущих raw_quantity_delta для этой приёмки.
     *
     * raw_quantity_delta нужна для будущей аналитики эффективности:
     * сколько готовой продукции получено с единицы сырья.
     */
    public static function writeLog(
        StoneReception $reception,
        array $previousItems,
        string $type
    ): void {
        $reception->load('items');  // load() перезагружает всегда, loadMissing() пропускает уже загруженные (может быть пустым после create)

        // Текущее состояние продуктов после сохранения: [product_id => quantity]
        $currentItems = $reception->items
            ->pluck('quantity', 'product_id')
            ->map(fn($q) => (float) $q)
            ->toArray();

        // Дельта сырья
        $rawQuantityDelta = 0.0;

        if ($type === ReceptionLog::TYPE_UPDATED) {
            // Предыдущее состояние продуктов — сумма всех дельт прошлых логов
            $logIds = ReceptionLog::where('stone_reception_id', $reception->id)
                ->pluck('id');

            $previousItems = ReceptionLogItem::whereIn('reception_log_id', $logIds)
                ->get()
                ->groupBy('product_id')
                ->map(fn($items) => (float) $items->sum('quantity_delta'))
                ->toArray();

            // Предыдущее суммарное сырьё = сумма raw_quantity_delta всех прошлых логов
            $previousRaw = (float) ReceptionLog::where('stone_reception_id', $reception->id)
                ->sum('raw_quantity_delta');

            $rawQuantityDelta = (float) $reception->raw_quantity_used - $previousRaw;

        } else {
            // При создании — всё сырьё идёт как есть
            $rawQuantityDelta = (float) $reception->raw_quantity_used;
        }

        // Считаем дельты по продуктам
        $allProductIds = array_unique(
            array_merge(array_keys($previousItems), array_keys($currentItems))
        );

        $deltas = [];
        foreach ($allProductIds as $productId) {
            $before = $previousItems[$productId] ?? 0.0;
            $after  = $currentItems[$productId]  ?? 0.0;
            $delta  = $after - $before;

            if (abs($delta) > 0.0001) {
                $deltas[$productId] = $delta;
            }
        }

        // Нет изменений ни по продуктам ни по сырью — лог не нужен
        if ($type === ReceptionLog::TYPE_UPDATED && empty($deltas) && abs($rawQuantityDelta) < 0.0001) {
            return;
        }

        $log = ReceptionLog::create([
            'stone_reception_id'    => $reception->id,
            'raw_material_batch_id' => $reception->raw_material_batch_id,
            'cutter_id'             => $reception->cutter_id,
            'receiver_id'           => $reception->receiver_id,
            'type'                  => $type,
            'raw_quantity_delta'    => $rawQuantityDelta,
        ]);

        foreach ($deltas as $productId => $delta) {
            ReceptionLogItem::create([
                'reception_log_id' => $log->id,
                'product_id'       => $productId,
                'quantity_delta'   => $delta,
            ]);
        }
    }
}
