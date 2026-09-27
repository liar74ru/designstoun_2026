<?php

namespace App\Services\Moysklad;

use App\Models\Product;
use App\Models\Store;
use App\Models\ProductStock;
use Illuminate\Support\Facades\Log;

/**
 * Синхронизация остатков из МойСклад → таблица product_stocks.
 *
 * Единственный источник истины для остатков — product_stocks.
 * Поле products.quantity устарело и не используется.
 * Суммарный остаток = SUM(product_stocks.quantity) по product_id.
 */
class StockSyncService extends MoySkladBaseService
{
    private function extractProductIdFromHref(string $href): ?string
    {
        preg_match('/\\/product\\/([a-f0-9-]+)/i', $href, $matches);
        return $matches[1] ?? null;
    }

    /**
     * Применить строки отчёта /report/stock/bystore к product_stocks.
     * Поле products.quantity НЕ обновляется.
     *
     * Товары, склады и текущие остатки берутся тремя выборками на всю пачку строк,
     * в базу пишутся только изменившиеся ячейки — одним upsert на 500 строк. Раньше
     * каждая ячейка «товар × склад» стоила 2–3 запроса: 11 тысяч ячеек — ~30 000
     * запросов и ~20 секунд на синхронизацию.
     *
     * upsert обходит события модели, поэтому available (хук saving в ProductStock)
     * считается здесь.
     *
     * @return int  число изменённых ячеек
     */
    private function applyRows(array $rows, ?string $filterStoreId = null): int
    {
        $rowsByProduct = [];
        foreach ($rows as $row) {
            if ($moyskladId = $this->extractProductIdFromHref($row['meta']['href'] ?? '')) {
                $rowsByProduct[$moyskladId] = $row;
            }
        }

        if ($rowsByProduct === []) {
            return 0;
        }

        $productIds = Product::whereIn('moysklad_id', array_keys($rowsByProduct))->pluck('id', 'moysklad_id');
        $storeIds   = Store::pluck('id')->flip();

        $existing = ProductStock::query()
            ->whereIn('product_id', $productIds->values())
            ->when($filterStoreId, fn ($q) => $q->where('store_id', $filterStoreId))
            ->get(['product_id', 'store_id', 'quantity', 'reserved', 'in_transit'])
            ->keyBy(fn (ProductStock $s) => $s->product_id . '|' . $s->store_id);

        $changes = [];

        foreach ($rowsByProduct as $moyskladId => $row) {
            $productId = $productIds[$moyskladId] ?? null;
            if (!$productId) continue;

            foreach ($row['stockByStore'] ?? [] as $storeStock) {
                $storeId = basename($storeStock['meta']['href'] ?? '');

                if ($filterStoreId && $storeId !== $filterStoreId) continue;
                if (!isset($storeIds[$storeId])) continue;

                $values = [
                    'quantity'   => round((float) ($storeStock['stock']     ?? 0), 3),
                    'reserved'   => round((float) ($storeStock['reserve']   ?? 0), 3),
                    'in_transit' => round((float) ($storeStock['inTransit'] ?? 0), 3),
                ];

                $current = $existing[$productId . '|' . $storeId] ?? null;

                // stockMode=all отдаёт и пустые склады: пустую строку не создаём,
                // но существующую обнуляем — иначе списанный до нуля остаток остаётся старым.
                if (!$current && !array_filter($values)) continue;

                if ($current
                    && round($current->quantity, 3) === $values['quantity']
                    && round($current->reserved, 3) === $values['reserved']
                    && round($current->in_transit, 3) === $values['in_transit']) {
                    continue;
                }

                $changes[] = [
                    'product_id' => $productId,
                    'store_id'   => $storeId,
                    'available'  => $values['quantity'] - $values['reserved'],
                ] + $values;
            }
        }

        foreach (array_chunk($changes, 500) as $chunk) {
            ProductStock::upsert($chunk, ['product_id', 'store_id'], ['quantity', 'reserved', 'in_transit', 'available']);
        }

        return count($changes);
    }

    /**
     * Синхронизировать остатки по складам для всех товаров (или одного склада).
     * Основной публичный метод.
     */
    public function syncAllProductsStocksByStores(?string $storeId = null): array
    {
        $result = ['success' => false, 'message' => '', 'updated' => 0];

        try {
            $offset = 0;
            // Максимум МойСклад для отчётов: весь каталог (~900 товаров) — одна страница
            // вместо девяти, ~3 секунды вместо ~24.
            $limit  = 1000;
            $total  = 0;

            do {
                // stockMode=all — иначе склады с нулевым остатком не приходят и не обнуляются
                $params = ['limit' => $limit, 'offset' => $offset, 'filter' => 'stockMode=all'];
                if ($storeId) $params['store'] = $storeId;

                $data = $this->get('/report/stock/bystore', $params);

                if (!$data) {
                    $result['message'] = 'Ошибка получения данных из МойСклад';
                    return $result;
                }

                $rows = $data['rows'] ?? [];

                $total += $this->applyRows($rows, $storeId);

                $offset += $limit;

            } while (count($rows) === $limit);

            $result['success'] = true;
            $result['updated'] = $total;
            $result['message'] = "Обновлено остатков: {$total}";

        } catch (\Exception $e) {
            Log::error('Ошибка синхронизации остатков', ['error' => $e->getMessage()]);
            $result['message'] = 'Ошибка: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Синхронизировать остатки для одного товара по его moysklad_id.
     */
    public function updateProductStocksByMoyskladId(string $moyskladId): array
    {
        $filter = $this->baseUrl . '/entity/product/' . $moyskladId;

        $data = $this->get('/report/stock/bystore', [
            'filter' => 'product=' . $filter . ';stockMode=all',
            'limit'  => 1,
        ]);

        if (!$data) {
            return ['success' => false, 'message' => 'Ошибка получения данных из МойСклад', 'updated' => 0];
        }

        if (empty($data['rows'])) {
            // Со stockMode=all существующий товар приходит всегда, даже с нулями.
            // Пустой ответ — не повод стирать остатки: ничего не пишем.
            return ['success' => false, 'message' => 'Нет данных об остатках', 'updated' => 0];
        }

        $updated = $this->applyRows([$data['rows'][0]]);

        return [
            'success' => true,
            'message' => "Обновлено записей по складам: {$updated}",
            'updated' => $updated,
        ];
    }

    /**
     * Перечитать из МойСклад остатки конкретных товаров — вызывается после любого
     * документа, который их двигает. Ошибка не должна ронять вызывающий поток — логируем.
     */
    public function refreshProducts(iterable $moyskladIds): void
    {
        foreach (collect($moyskladIds)->filter()->unique() as $moyskladId) {
            try {
                $result = $this->updateProductStocksByMoyskladId($moyskladId);

                if (!$result['success']) {
                    Log::warning('Остатки товара не обновлены из МойСклад', [
                        'moysklad_id' => $moyskladId,
                        'message'     => $result['message'],
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Исключение при обновлении остатков товара из МойСклад', [
                    'moysklad_id' => $moyskladId,
                    'error'       => $e->getMessage(),
                ]);
            }
        }
    }

    // ─── Обёртки для обратной совместимости ─────────────────────────────────

    public function syncAllStocksByStores(): array
    {
        return $this->syncAllProductsStocksByStores();
    }

    public function syncStocksByStore(string $storeId): array
    {
        return $this->syncAllProductsStocksByStores($storeId);
    }
}
