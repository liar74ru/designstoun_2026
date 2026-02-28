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
     * Синхронизировать все остатки
     */
    public function syncAllStocks()
    {
        $result = [
            'success' => false,
            'message' => '',
            'updated' => 0,
            'errors' => 0
        ];

        if (empty($this->token)) {
            $result['message'] = 'Токен не найден';
            return $result;
        }

        try {
            $offset = 0;
            $limit = 100;
            $totalUpdated = 0;

            do {
                // Запрашиваем отчет по остаткам
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->token,
                    'Accept-Encoding' => 'gzip',
                ])->get($this->baseUrl . '/report/stock/all', [
                    'limit' => $limit,
                    'offset' => $offset
                ]);

                if (!$response->successful()) {
                    $result['message'] = 'Ошибка получения данных из МойСклад';
                    return $result;
                }

                $data = $response->json();
                $rows = $data['rows'] ?? [];

                $notFound = [];
                $notFoundCount = 0;

                foreach ($rows as $row) {
                    try {
                        // Берем code из ответа
                        $code = $row['code'] ?? null;

                        if (!$code) {
                            Log::warning('Товар без code пропущен', ['name' => $row['name'] ?? '']);
                            $result['errors']++;
                            continue;
                        }

                        // Ищем товар в нашей БД по sku (code)
                        $product = Product::where('code', $code)->first();

                        if (!$product) {
                            Log::debug('Товар не найден в локальной БД', ['code' => $code, 'name' => $row['name'] ?? '']);
                            $result['errors']++;
                            continue;
                        }

                        // Обновляем общее количество в товаре
                        $product->quantity = $row['stock'] ?? 0;
                        $product->save();

                        // Здесь можно добавить обновление остатков по складам,
                        // но в этом отчете нет разбивки по складам

                        $totalUpdated++;

                    } catch (\Exception $e) {
                        Log::error('Ошибка при обработке строки отчета', [
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
            $result['message'] = "Обновлено товаров: {$totalUpdated}, ошибок: {$result['errors']}";

        } catch (\Exception $e) {
            Log::error('Ошибка синхронизации остатков', ['error' => $e->getMessage()]);
            $result['message'] = 'Ошибка: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Обновить остатки для конкретного товара по его moysklad_id
     */
    public function updateProductStocksByMoyskladId($moyskladId)
    {
        if (empty($this->token)) {
            return ['success' => false, 'message' => 'Токен не найден'];
        }

        $product = Product::where('moysklad_id', $moyskladId)->first();
        if (!$product) {
            return ['success' => false, 'message' => 'Товар не найден в локальной базе'];
        }

        $filter = 'product=https://api.moysklad.ru/api/remap/1.2/entity/product/' . $moyskladId;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept-Encoding' => 'gzip',
        ])->get($this->baseUrl . '/report/stock/bystore', [
            'filter' => $filter,
            'limit' => 1
        ]);

        if (!$response->successful()) {
            return ['success' => false, 'message' => 'Ошибка получения данных из МойСклад'];
        }

        $data = $response->json();
        $rows = $data['rows'] ?? [];

        if (empty($rows)) {
            return ['success' => true, 'message' => 'Нет данных об остатках', 'updated' => 0];
        }

        $row = $rows[0];
        $stockByStore = $row['stockByStore'] ?? [];

        // Обновляем общее количество товара
        if (isset($row['stock'])) {
            $product->quantity = (float)$row['stock'];
            $product->save();
        }

        $updated = 0;
        foreach ($stockByStore as $storeStock) {
            if (!isset($storeStock['meta']['href'])) continue;

            $storeId = basename($storeStock['meta']['href']);

            if (!Store::find($storeId)) continue;

            ProductStock::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'store_id' => $storeId
                ],
                [
                    'quantity'   => (float)($storeStock['stock'] ?? 0),
                    'reserved'   => (float)($storeStock['reserve'] ?? 0),
                    'in_transit' => (float)($storeStock['inTransit'] ?? 0),
                ]
            );

            $updated++;
        }

        return [
            'success' => true,
            'message' => "Обновлен товар {$product->name}, остатков по складам: {$updated}",
            'updated' => $updated
        ];
    }

    /**
     * Синхронизировать остатки по ВСЕМ складам
     */
    public function syncAllStocksByStores()
    {
        $result = [
            'success' => false,
            'message' => '',
            'updated' => 0,
            'errors' => 0
        ];

        // Получаем все склады из БД
        $stores = Store::all();

        foreach ($stores as $store) {
            $storeResult = $this->syncStocksByStore($store->id);
            $result['updated'] += $storeResult['updated'];
            $result['errors'] += $storeResult['errors'];
        }

        $result['success'] = true;
        $result['message'] = "Обновлено остатков по всем складам: {$result['updated']}, ошибок: {$result['errors']}";

        return $result;
    }

    /**
     * Синхронизировать остатки по конкретному складу
     */
    public function syncStocksByStore($storeId)
    {
        $result = [
            'success' => false,
            'message' => '',
            'updated' => 0,
            'errors' => 0
        ];

        if (empty($this->token)) {
            $result['message'] = 'Токен не найден';
            return $result;
        }

        try {
            $offset = 0;
            $limit = 100;
            $totalUpdated = 0;

            do {
                // Запрашиваем отчет по остаткам с фильтром по складу
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->token,
                    'Accept-Encoding' => 'gzip',
                ])->get($this->baseUrl . '/report/stock/bystore', [
                    'limit' => $limit,
                    'offset' => $offset,
                    'store' => $storeId
                ]);

                if (!$response->successful()) {
                    $result['message'] = 'Ошибка получения данных из МойСклад';
                    return $result;
                }

                $data = $response->json();
                $rows = $data['rows'] ?? [];

                foreach ($rows as $row) {
                    try {
                        // Получаем ID товара из meta.href
                        if (!isset($row['meta']['href'])) continue;

                        $href = $row['meta']['href'];
                        // Извлекаем UUID из URL
                        // https://api.moysklad.ru/api/remap/1.2/entity/product/c02e3a5c-007e-11e6-9464-e4de00000006?expand=supplier
                        preg_match('/\/product\/([a-f0-9-]+)/i', $href, $matches);

                        if (!isset($matches[1])) {
                            Log::warning('Не удалось извлечь ID из href', ['href' => $href]);
                            $result['errors']++;
                            continue;
                        }

                        $moyskladId = $matches[1];

                        // Ищем товар в БД по moysklad_id (STRING)
                        $product = Product::where('moysklad_id', $moyskladId)->first();

                        if (!$product) {
                            Log::debug('Товар не найден в локальной БД', [
                                'moysklad_id' => $moyskladId,
                                'name' => $row['name'] ?? ''
                            ]);
                            $result['errors']++;
                            continue;
                        }

                        // Обновляем или создаем запись в product_stocks
                        ProductStock::updateOrCreate(
                            [
                                'product_id' => $product->id,
                                'store_id' => $storeId
                            ],
                            [
                                'quantity' => (float)($row['stock'] ?? 0),
                                'reserved' => (float)($row['reserve'] ?? 0),
                                'in_transit' => (float)($row['inTransit'] ?? 0),
                            ]
                        );

                        $totalUpdated++;

                    } catch (\Exception $e) {
                        Log::error('Ошибка при обработке остатков по складу', [
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
            Log::error('Ошибка синхронизации остатков по складу', ['error' => $e->getMessage()]);
            $result['message'] = 'Ошибка: ' . $e->getMessage();
        }

        return $result;
    }
}
