<?php
// app/Services/MoySkladProcessingService.php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Store;
use App\Models\Product;
use App\Models\StoneReception;

class MoySkladProcessingService
{
    private $token;
    private $baseUrl = 'https://api.moysklad.ru/api/remap/1.2';
    private $organizationMeta;
    private $processingSum = 1100; // Затраты на производство за единицу

    public function __construct()
    {
        $this->token = env('MOYSKLAD_TOKEN');

        if (empty($this->token)) {
            Log::warning('MOYSKLAD_TOKEN не установлен в .env файле');
        }
    }

    /**
     * Проверка наличия учетных данных
     */
    public function hasCredentials(): bool
    {
        return !empty($this->token);
    }

    /**
     * Получить метаданные организации
     */
    public function getOrganizationMeta()
    {
        if ($this->organizationMeta) {
            return $this->organizationMeta;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
            ])->get($this->baseUrl . '/entity/organization');

            if (!$response->successful()) {
                Log::error('Ошибка получения данных организации', [
                    'status' => $response->status()
                ]);
                return null;
            }

            $data = $response->json();
            $rows = $data['rows'] ?? [];

            if (empty($rows)) {
                Log::error('Организации не найдены');
                return null;
            }

            $this->organizationMeta = $rows[0]['meta'];
            return $this->organizationMeta;

        } catch (\Exception $e) {
            Log::error('Ошибка получения метаданных организации', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Получить метаданные склада по ID
     */
    private function getStoreMeta($storeId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
            ])->get($this->baseUrl . '/entity/store/' . $storeId);

            if (!$response->successful()) {
                Log::error('Ошибка получения данных склада', [
                    'store_id' => $storeId,
                    'status' => $response->status()
                ]);
                return null;
            }

            $data = $response->json();
            return $data['meta'] ?? null;

        } catch (\Exception $e) {
            Log::error('Ошибка получения метаданных склада', [
                'store_id' => $storeId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Получить метаданные товара
     */
    private function getProductMeta($moyskladId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
            ])->get($this->baseUrl . '/entity/product/' . $moyskladId);

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();
            return $data['meta'] ?? null;

        } catch (\Exception $e) {
            Log::error('Ошибка получения метаданных товара', [
                'product_id' => $moyskladId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Подготовить продукты для техоперации
     */
    private function prepareProducts(array $receptions): array
    {
        $products = [];
        $totalQuantity = 0;

        foreach ($receptions as $reception) {
            foreach ($reception->items as $item) {
                $product = $item->product;

                if (!$product->moysklad_id) {
                    Log::warning('Товар не синхронизирован с МойСклад', [
                        'product_id' => $product->id,
                        'reception_id' => $reception->id
                    ]);
                    continue;
                }

                $productMeta = $this->getProductMeta($product->moysklad_id);
                if (!$productMeta) {
                    Log::warning('Не удалось получить метаданные товара', [
                        'product_id' => $product->id,
                        'moysklad_id' => $product->moysklad_id
                    ]);
                    continue;
                }

                $products[] = [
                    'quantity' => (float)$item->quantity,
                    'assortment' => [
                        'meta' => $productMeta
                    ]
                ];

                $totalQuantity += $item->quantity;
            }
        }

        return [
            'products' => $products,
            'total_quantity' => $totalQuantity
        ];
    }

    /**
     * Создать техоперацию в МойСклад
     *
     * @param array $receptions Массив приемок (как массивы, не объекты)
     * @param string $storeId ID склада
     * @return array
     */
    public function createProcessing(array $receptions, string $storeId): array
    {
        $result = [
            'success' => false,
            'processing_id' => null,
            'message' => '',
            'receptions' => $receptions
        ];

        try {
            Log::info('MoySkladProcessingService: начало создания техоперации', [
                'receptions_count' => count($receptions),
                'store_id' => $storeId
            ]);

            if (!$this->hasCredentials()) {
                throw new \Exception('MoySklad токен не установлен');
            }

            // Получаем метаданные организации
            $organizationMeta = $this->getOrganizationMeta();
            if (!$organizationMeta) {
                throw new \Exception('Не удалось получить данные организации');
            }

            // Получаем метаданные склада
            $storeMeta = $this->getStoreMeta($storeId);
            if (!$storeMeta) {
                throw new \Exception('Не удалось получить данные склада');
            }

            // Подготавливаем и группируем продукты (готовая продукция)
            $productsGrouped = [];
            $materialsGrouped = [];
            $totalQuantity = 0;
            $totalRawQuantity = 0;

            foreach ($receptions as $reception) {
                // Проверяем, что есть items и это массив
                if (!isset($reception['items']) || !is_array($reception['items'])) {
                    Log::warning('Приемка без items', ['reception_id' => $reception['id'] ?? 'unknown']);
                    continue;
                }

                // Группируем готовую продукцию
                foreach ($reception['items'] as $item) {
                    if (!isset($item['product']) || !isset($item['product']['moysklad_id'])) {
                        Log::warning('Товар без moysklad_id', ['item' => $item]);
                        continue;
                    }

                    $moyskladId = $item['product']['moysklad_id'];
                    $quantity = (float)$item['quantity'];

                    // Если продукт уже есть в массиве, суммируем количество
                    if (isset($productsGrouped[$moyskladId])) {
                        $productsGrouped[$moyskladId]['quantity'] += $quantity;
                        Log::info('Суммируем продукт', [
                            'moysklad_id' => $moyskladId,
                            'new_quantity' => $productsGrouped[$moyskladId]['quantity']
                        ]);
                    } else {
                        // Получаем метаданные товара из МойСклад
                        $productMeta = $this->getProductMeta($moyskladId);
                        if (!$productMeta) {
                            Log::warning('Не удалось получить метаданные товара', [
                                'moysklad_id' => $moyskladId
                            ]);
                            continue;
                        }

                        $productsGrouped[$moyskladId] = [
                            'quantity' => $quantity,
                            'assortment' => [
                                'meta' => $productMeta
                            ]
                        ];
                    }

                    $totalQuantity += $quantity;
                }

                // Группируем сырье (материалы)
                if (isset($reception['raw_material_batch']) && isset($reception['raw_material_batch']['product'])) {
                    $rawProduct = $reception['raw_material_batch']['product'];

                    if (isset($rawProduct['moysklad_id'])) {
                        $rawMoyskladId = $rawProduct['moysklad_id'];
                        $rawQuantity = (float)$reception['raw_quantity_used'];

                        // Если сырье уже есть в массиве, суммируем количество
                        if (isset($materialsGrouped[$rawMoyskladId])) {
                            $materialsGrouped[$rawMoyskladId]['quantity'] += $rawQuantity;
                            Log::info('Суммируем сырье', [
                                'moysklad_id' => $rawMoyskladId,
                                'new_quantity' => $materialsGrouped[$rawMoyskladId]['quantity']
                            ]);
                        } else {
                            $rawProductMeta = $this->getProductMeta($rawMoyskladId);
                            if ($rawProductMeta) {
                                $materialsGrouped[$rawMoyskladId] = [
                                    'quantity' => $rawQuantity,
                                    'assortment' => [
                                        'meta' => $rawProductMeta
                                    ]
                                ];
                            } else {
                                Log::warning('Не удалось получить метаданные сырья', [
                                    'moysklad_id' => $rawMoyskladId
                                ]);
                            }
                        }

                        $totalRawQuantity += $rawQuantity;
                    }
                }
            }

            if (empty($productsGrouped)) {
                throw new \Exception('Нет продуктов для отправки');
            }

            if (empty($materialsGrouped)) {
                throw new \Exception('Нет материалов (сырья) для отправки');
            }

            // Преобразуем сгруппированные массивы в простые списки
            $products = array_values($productsGrouped);
            $materials = array_values($materialsGrouped);

            // Рассчитываем processingSum = общее количество продукции * 1100
            $processingSum = $totalQuantity * 1100;

            // Формируем данные для запроса
            $processingData = [
                'organization' => [
                    'meta' => $organizationMeta
                ],
                'productsStore' => [
                    'meta' => $storeMeta
                ],
                'materialsStore' => [
                    'meta' => $storeMeta
                ],
                'processingSum' => $processingSum,
                'products' => $products,
                'materials' => $materials,
                'name' => 'Техоперация от ' . now()->format('d.m.Y H:i'),
                'quantity' => $totalQuantity
            ];

            Log::info('Отправка запроса в МойСклад', [
                'products_count' => count($products),
                'products_details' => array_map(function($p) {
                    return ['quantity' => $p['quantity']];
                }, $products),
                'materials_count' => count($materials),
                'materials_details' => array_map(function($m) {
                    return ['quantity' => $m['quantity']];
                }, $materials),
                'total_quantity' => $totalQuantity,
                'total_raw_quantity' => $totalRawQuantity,
                'processing_sum' => $processingSum
            ]);

            // Отправляем запрос
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
                'Content-Type' => 'application/json'
            ])->post($this->baseUrl . '/entity/processing', $processingData);

            if (!$response->successful()) {
                $errorResponse = $response->json();
                $errorMessage = $errorResponse['errors'][0]['error'] ??
                    $errorResponse['errors'][0]['title'] ??
                    'Неизвестная ошибка';

                Log::error('Ошибка API МойСклад', [
                    'status' => $response->status(),
                    'response' => $errorResponse,
                    'request_data' => $processingData
                ]);

                throw new \Exception('Ошибка API МойСклад: ' . $errorMessage);
            }

            $processingResponse = $response->json();

            $result['success'] = true;
            $result['processing_id'] = $processingResponse['id'] ?? null;
            $result['message'] = 'Техоперация успешно создана';

            Log::info('Техоперация создана', [
                'processing_id' => $result['processing_id'],
                'products_count' => count($products),
                'materials_count' => count($materials),
                'total_quantity' => $totalQuantity,
                'total_raw_quantity' => $totalRawQuantity
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('Исключение при создании техоперации', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $result['message'] = 'Ошибка: ' . $e->getMessage();
            return $result;
        }
    }

    /**
     * Получить информацию о техоперации
     */
    public function getProcessing($processingId)
    {
        if (!$this->hasCredentials()) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
            ])->get($this->baseUrl . '/entity/processing/' . $processingId);

            if (!$response->successful()) {
                return null;
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('Ошибка получения информации о техоперации', [
                'processing_id' => $processingId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
