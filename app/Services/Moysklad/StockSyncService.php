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
     * Обновить product_stocks из одной строки API /report/stock/bystore.
     * Поле products.quantity НЕ обновляется.
     */
    private function updateStocksFromRow(array $row, ?string $filterStoreId = null): int
    {
        $moyskladId = $this->extractProductIdFromHref($row['meta']['href'] ?? '');
        if (!$moyskladId) return 0;

        $product = Product::where('moysklad_id', $moyskladId)->first();
        if (!$product) return 0;

        $updated = 0;

        foreach ($row['stockByStore'] ?? [] as $storeStock) {
            $storeId = basename($storeStock['meta']['href'] ?? '');

            if ($filterStoreId && $storeId !== $filterStoreId) continue;
            if (!Store::find($storeId)) continue;

            ProductStock::updateOrCreate(
                ['product_id' => $product->id, 'store_id' => $storeId],
                [
                    'quantity'   => (float)($storeStock['stock']     ?? 0),
                    'reserved'   => (float)($storeStock['reserve']   ?? 0),
                    'in_transit' => (float)($storeStock['inTransit'] ?? 0),
                ]
            );

            $updated++;
        }

        return $updated;
    }

    /**
     * Синхронизировать остатки по складам для всех товаров (или одного склада).
     * Основной публичный метод.
     */
    public function syncAllProductsStocksByStores(?string $storeId = null): array
    {
        $result = ['success' => false, 'message' => '', 'updated' => 0, 'errors' => 0];

        try {
            $offset = 0;
            $limit  = 100;
            $total  = 0;

            do {
                $params = ['limit' => $limit, 'offset' => $offset];
                if ($storeId) $params['store'] = $storeId;

                $data = $this->get('/report/stock/bystore', $params);

                if (!$data) {
                    $result['message'] = 'Ошибка получения данных из МойСклад';
                    return $result;
                }

                $rows = $data['rows'] ?? [];

                foreach ($rows as $row) {
                    try {
                        $total += $this->updateStocksFromRow($row, $storeId);
                    } catch (\Exception $e) {
                        Log::error('Ошибка обработки строки остатков', ['error' => $e->getMessage()]);
                        $result['errors']++;
                    }
                }

                $offset += $limit;

            } while (count($rows) === $limit);

            $result['success'] = true;
            $result['updated'] = $total;
            $result['message'] = "Обновлено остатков: {$total}"
                . ($result['errors'] ? ", ошибок: {$result['errors']}" : '');

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
            'filter' => 'product=' . $filter,
            'limit'  => 1,
        ]);

        if (!$data || empty($data['rows'])) {
            return ['success' => false, 'message' => 'Нет данных об остатках', 'updated' => 0];
        }

        $updated = $this->updateStocksFromRow($data['rows'][0]);

        return [
            'success' => true,
            'message' => "Обновлено записей по складам: {$updated}",
            'updated' => $updated,
        ];
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
