<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Store;
use App\Models\ProductStock;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StockSyncService
{
    private $token;
    private $baseUrl = 'https://api.moysklad.ru/api/remap/1.2';

    public function __construct()
    {
        $this->token = env('MOYSKLAD_TOKEN');
    }

    /**
     * Базовый метод для запросов к API
     */
    private function makeRequest($endpoint, $params = [])
    {
        if (empty($this->token)) {
            return null;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept-Encoding' => 'gzip',
        ])->get($this->baseUrl . $endpoint, $params);

        return $response->successful() ? $response->json() : null;
    }

    /**
     * Извлечь ID товара из href
     */
    private function extractProductIdFromHref($href)
    {
        preg_match('/\/product\/([a-f0-9-]+)/i', $href, $matches);
        return $matches[1] ?? null;
    }

    /**
     * Обновить остатки товара из данных API
     */
    private function updateProductStocksFromRow($row, $storeId = null)
    {
        if (!isset($row['meta']['href'])) {
            return 0;
        }

        $moyskladId = $this->extractProductIdFromHref($row['meta']['href']);
        if (!$moyskladId) {
            return 0;
        }

        $product = Product::where('moysklad_id', $moyskladId)->first();
        if (!$product) {
            return 0;
        }

        // Обновляем общее количество
        if (isset($row['stock'])) {
            $product->quantity = (float)$row['stock'];
            $product->save();
        }

        $updated = 0;
        $stockByStore = $row['stockByStore'] ?? [];

        foreach ($stockByStore as $storeStock) {
            if (!isset($storeStock['meta']['href'])) continue;

            $currentStoreId = basename($storeStock['meta']['href']);

            // Если указан конкретный склад, фильтруем
            if ($storeId && $currentStoreId != $storeId) continue;

            if (!Store::find($currentStoreId)) continue;

            ProductStock::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'store_id' => $currentStoreId
                ],
                [
                    'quantity'   => (float)($storeStock['stock'] ?? 0),
                    'reserved'   => (float)($storeStock['reserve'] ?? 0),
                    'in_transit' => (float)($storeStock['inTransit'] ?? 0),
                ]
            );

            $updated++;
        }

        return $updated;
    }

    /**
     * Синхронизировать остатки (общие)
     */
    public function syncAllStocks()
    {
        $result = ['success' => false, 'message' => '', 'updated' => 0, 'errors' => 0];

        try {
            $offset = 0;
            $limit = 100;
            $totalUpdated = 0;

            do {
                $data = $this->makeRequest('/report/stock/all', [
                    'limit' => $limit,
                    'offset' => $offset
                ]);

                if (!$data) {
                    $result['message'] = 'Ошибка получения данных из МойСклад';
                    return $result;
                }

                $rows = $data['rows'] ?? [];

                foreach ($rows as $row) {
                    $code = $row['code'] ?? null;

                    if (!$code) {
                        $result['errors']++;
                        continue;
                    }

                    $product = Product::where('code', $code)->first();

                    if (!$product) {
                        $result['errors']++;
                        continue;
                    }

                    $product->quantity = (float)($row['stock'] ?? 0);
                    $product->save();

                    $totalUpdated++;
                }

                $offset += $limit;

            } while (count($rows) === $limit);

            $result['success'] = true;
            $result['updated'] = $totalUpdated;
            $result['message'] = "Обновлено товаров: {$totalUpdated}, ошибок: {$result['errors']}";

        } catch (\Exception $e) {
            Log::error('Ошибка синхронизации остатков', ['error' => $e->getMessage()]);
            $result['message'] = 'Ошибка: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Обновить остатки для конкретного товара
     */
    public function updateProductStocksByMoyskladId($moyskladId)
    {
        $filter = 'product=https://api.moysklad.ru/api/remap/1.2/entity/product/' . $moyskladId;

        $data = $this->makeRequest('/report/stock/bystore', [
            'filter' => $filter,
            'limit' => 1
        ]);

        if (!$data || empty($data['rows'])) {
            return ['success' => false, 'message' => 'Нет данных об остатках', 'updated' => 0];
        }

        $updated = $this->updateProductStocksFromRow($data['rows'][0]);

        return [
            'success' => true,
            'message' => "Обновлено остатков: {$updated}",
            'updated' => $updated
        ];
    }

    /**
     * Синхронизировать остатки по складам для ВСЕХ товаров
     */
    public function syncAllProductsStocksByStores($storeId = null)
    {
        $result = ['success' => false, 'message' => '', 'updated' => 0, 'errors' => 0];

        try {
            $offset = 0;
            $limit = 100;
            $totalUpdated = 0;

            do {
                $params = ['limit' => $limit, 'offset' => $offset];

                if ($storeId) {
                    $params['store'] = $storeId;
                }

                $data = $this->makeRequest('/report/stock/bystore', $params);

                if (!$data) {
                    $result['message'] = 'Ошибка получения данных из МойСклад';
                    return $result;
                }

                $rows = $data['rows'] ?? [];

                foreach ($rows as $row) {
                    try {
                        $updated = $this->updateProductStocksFromRow($row, $storeId);
                        $totalUpdated += $updated;

                    } catch (\Exception $e) {
                        Log::error('Ошибка при обработке строки', [
                            'error' => $e->getMessage(),
                            'row' => $row
                        ]);
                        $result['errors']++;
                    }
                }

                $offset += $limit;

            } while (count($rows) === $limit);

            $result['success'] = true;
            $result['updated'] = $totalUpdated;
            $result['message'] = "Обновлено остатков: {$totalUpdated}, ошибок: {$result['errors']}";

        } catch (\Exception $e) {
            Log::error('Ошибка синхронизации остатков', ['error' => $e->getMessage()]);
            $result['message'] = 'Ошибка: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Синхронизировать остатки по всем складам (обертка для обратной совместимости)
     */
    public function syncAllStocksByStores()
    {
        return $this->syncAllProductsStocksByStores();
    }

    /**
     * Синхронизировать остатки по конкретному складу (обертка)
     */
    public function syncStocksByStore($storeId)
    {
        return $this->syncAllProductsStocksByStores($storeId);
    }
}
