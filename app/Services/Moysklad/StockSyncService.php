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

            $values = [
                'quantity'   => (float)($storeStock['stock']     ?? 0),
                'reserved'   => (float)($storeStock['reserve']   ?? 0),
                'in_transit' => (float)($storeStock['inTransit'] ?? 0),
            ];

            // stockMode=all отдаёт и пустые склады: пустую строку не создаём,
            // но существующую обнуляем — иначе списанный до нуля остаток остаётся старым.
            if (!array_filter($values)) {
                $stock = ProductStock::where('product_id', $product->id)->where('store_id', $storeId)->first();
                if (!$stock) continue;
                $stock->fill($values)->save();
            } else {
                ProductStock::updateOrCreate(['product_id' => $product->id, 'store_id' => $storeId], $values);
            }

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
                // stockMode=all — иначе склады с нулевым остатком не приходят и не обнуляются
                $params = ['limit' => $limit, 'offset' => $offset, 'filter' => 'stockMode=all'];
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

        $updated = $this->updateStocksFromRow($data['rows'][0]);

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
