<?php
// app/Services/MoySkladProcessingService.php

namespace App\Services;

use App\Models\Setting;
use App\Support\DocumentNaming;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\RawMaterialBatch;
use App\Models\Store;
use App\Models\Product;
use App\Models\StoneReception;

class MoySkladProcessingService
{
    private $token;
    private $baseUrl = 'https://api.moysklad.ru/api/remap/1.2';
    private $organizationMeta;
    private float $processingSum;
    private array $processingStatesCache = [];

    public function __construct()
    {
        $this->token = config('services.moysklad.token');
        $this->baseUrl = config('services.moysklad.base_url');
        $this->processingSum = $this->manualCostPerUnit();

        if (empty($this->token)) {
            Log::warning('MOYSKLAD_TOKEN не установлен в .env файле');
        }
    }

    private function manualCostPerUnit(): float
    {
        $keys = [
            'BLADE_WEAR', 'RECEPTION_COST', 'PACKAGING_COST', 'WASTE_REMOVAL',
            'ELECTRICITY', 'PPE_COST', 'FORKLIFT_COST', 'MACHINE_COST',
            'RENT_COST', 'OTHER_COSTS',
        ];
        return array_sum(array_map(
            fn ($key) => (float) \App\Models\Setting::get($key, 0),
            $keys
        ));
    }

    /**
     * processingSum для МойСклад — стоимость за единицу объёма в копейках.
     * МойСклад хранит и умножает это значение на quantity самостоятельно.
     *
     * @param float $totalRubles  Итоговая стоимость производства в рублях (зарплата + накладные)
     * @param float $totalQty     Суммарное количество продукции
     */
    private function calcProcessingSum(float $totalRubles, float $totalQty): int
    {
        if ($totalQty <= 0) {
            return 0;
        }
        return (int) round($totalRubles * 100 / $totalQty);
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
     * Найти href статуса техоперации по его имени.
     * Загружает список статусов из /entity/processing/metadata один раз за запрос.
     */
    private function getProcessingStateHref(string $name): ?string
    {
        if (empty($this->processingStatesCache)) {
            try {
                $response = Http::withHeaders([
                    'Authorization'   => 'Bearer ' . $this->token,
                    'Accept-Encoding' => 'gzip',
                ])->get($this->baseUrl . '/entity/processing/metadata');

                if ($response->successful()) {
                    foreach ($response->json()['states'] ?? [] as $state) {
                        $this->processingStatesCache[$state['name']] = $state['meta']['href'];
                    }
                } else {
                    Log::warning('getProcessingStateHref: не удалось загрузить метаданные техопераций', [
                        'status' => $response->status(),
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('getProcessingStateHref: исключение', ['error' => $e->getMessage()]);
            }
        }

        return $this->processingStatesCache[$name] ?? null;
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
     * @param array       $receptions Массив приемок (как массивы, не объекты)
     * @param string      $storeId    ID склада
     * @param string|null $name       Имя документа; если не передано — генерируется автоматически
     * @return array ['success', 'processing_id', 'code', 'message']
     */
    public function createProcessing(array $receptions, string $storeId, ?string $name = null): array
    {
        $result = [
            'success'       => false,
            'processing_id' => null,
            'code'          => '',
            'message'       => '',
            'receptions'    => $receptions,
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

            $processingSum = $this->calcProcessingSum($totalQuantity * $this->processingSum, $totalQuantity);

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
                'products'      => $products,
                'materials'     => $materials,
                'name'          => $name ?? DocumentNaming::weeklyName('ТО', 1),
                'quantity'      => $totalQuantity
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
                $errors   = $response->json()['errors'] ?? [];
                $errorMsg = $errors[0]['error'] ?? $errors[0]['title'] ?? 'Неизвестная ошибка';

                Log::error('Ошибка API МойСклад', [
                    'status'       => $response->status(),
                    'response'     => $response->json(),
                    'request_data' => $processingData,
                ]);

                $result['code']    = DocumentNaming::isDuplicateName($errors) ? 'duplicate_name' : 'api_error';
                $result['message'] = 'Ошибка МойСклад: ' . $errorMsg;
                return $result;
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
     * Создать техоперацию для партии сырья на основе первой приёмки.
     *
     * Количество материала = весь доступный остаток партии на момент создания приёмки
     * (до списания, т.е. remaining_quantity + raw_quantity_used этой приёмки).
     *
     * @param  RawMaterialBatch  $batch
     * @param  float             $materialQuantity  Количество сырья (до вычета raw_quantity_used)
     * @param  StoneReception    $reception         Первая приёмка (уже сохранена в БД с items)
     * @return array ['success', 'processing_id', 'processing_name', 'code', 'message']
     */
    public function createProcessingForBatch(
        RawMaterialBatch $batch,
        float $materialQuantity,
        StoneReception $reception
    ): array {
        $result = [
            'success'         => false,
            'processing_id'   => null,
            'processing_name' => null,
            'code'            => '',
            'message'         => '',
        ];

        try {
            if (!$this->hasCredentials()) {
                throw new \Exception('MoySklad токен не установлен');
            }

            $organizationMeta = $this->getOrganizationMeta();
            if (!$organizationMeta) {
                throw new \Exception('Не удалось получить данные организации');
            }

            $storeMeta = $this->getStoreMeta($reception->store_id);
            if (!$storeMeta) {
                throw new \Exception('Не удалось получить данные склада');
            }

            // Сырьё: товар партии
            $rawProduct = $batch->product;
            if (!$rawProduct || !$rawProduct->moysklad_id) {
                throw new \Exception('Товар партии не синхронизирован с МойСклад (нет moysklad_id)');
            }
            $rawProductMeta = $this->getProductMeta($rawProduct->moysklad_id);
            if (!$rawProductMeta) {
                throw new \Exception('Не удалось получить метаданные сырья из МойСклад');
            }

            // Готовая продукция: items первой приёмки
            $reception->loadMissing('items.product');
            $productsGrouped = [];
            $totalQuantity   = 0;

            foreach ($reception->items as $item) {
                $product = $item->product;
                if (!$product || !$product->moysklad_id) {
                    Log::warning('createProcessingForBatch: товар без moysklad_id', [
                        'product_id' => $product->id ?? null,
                    ]);
                    continue;
                }
                $moyskladId = $product->moysklad_id;
                if (isset($productsGrouped[$moyskladId])) {
                    $productsGrouped[$moyskladId]['quantity'] += (float) $item->quantity;
                } else {
                    $productMeta = $this->getProductMeta($moyskladId);
                    if (!$productMeta) {
                        Log::warning('createProcessingForBatch: не удалось получить мета товара', [
                            'moysklad_id' => $moyskladId,
                        ]);
                        continue;
                    }
                    $productsGrouped[$moyskladId] = [
                        'quantity'   => (float) $item->quantity,
                        'assortment' => ['meta' => $productMeta],
                    ];
                }
                $totalQuantity += (float) $item->quantity;
            }

            // Исключаем позиции с нулевым количеством (МойСклад их отклоняет)
            $productsGrouped = array_filter($productsGrouped, fn($p) => $p['quantity'] > 0);

            if (empty($productsGrouped)) {
                throw new \Exception('Нет продуктов с moysklad_id для создания техоперации');
            }

            // Определяем имя документа (с retry при коллизии ниже)
            $weekCount = \App\Models\StoneReception::whereBetween('created_at', [
                now()->startOfWeek(Carbon::FRIDAY),
                now()->endOfWeek(Carbon::THURSDAY),
            ])->whereNotNull('raw_material_batch_id')
              ->distinct('raw_material_batch_id')
              ->count();

            $name = DocumentNaming::weeklyName('ТО', $weekCount + 1);

            // Зарплата пильщика по позициям первой приёмки
            $reception->loadMissing('items.product');
            $workerSalaryTotal = 0;
            foreach ($reception->items as $item) {
                $workerSalaryTotal += $item->effectiveProdCost() * (float) $item->quantity;
            }
            $totalProcessingSum = $this->calcProcessingSum(
                $workerSalaryTotal + $this->processingSum * $totalQuantity,
                $totalQuantity
            );

            $processingData = [
                'organization'   => ['meta' => $organizationMeta],
                'productsStore'  => ['meta' => $storeMeta],
                'materialsStore' => ['meta' => $storeMeta],
                'processingSum'  => $totalProcessingSum,
                'products'       => array_values($productsGrouped),
                'materials'      => [[
                    'quantity'   => $materialQuantity,
                    'assortment' => ['meta' => $rawProductMeta],
                ]],
                'name'           => $name,
                'quantity'       => $totalQuantity,
            ];

            $inWorkName = Setting::get('MOYSKLAD_IN_WORK_STATE', '');
            if ($inWorkName) {
                $inWorkHref = $this->getProcessingStateHref($inWorkName);
                if ($inWorkHref) {
                    $processingData['state'] = ['meta' => ['href' => $inWorkHref, 'type' => 'state', 'mediaType' => 'application/json']];
                }
            }

            $response = null;
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $processingData['name'] = $name;
                $response = Http::withHeaders([
                    'Authorization'   => 'Bearer ' . $this->token,
                    'Accept-Encoding' => 'gzip',
                    'Content-Type'    => 'application/json',
                ])->post($this->baseUrl . '/entity/processing', $processingData);

                if ($response->successful()) {
                    break;
                }

                $errors = $response->json()['errors'] ?? [];
                if (!DocumentNaming::isDuplicateName($errors)) {
                    break;
                }

                $name = DocumentNaming::nextSuffix($name);
            }

            if (!$response->successful()) {
                $errors    = $response->json()['errors'] ?? [];
                $errorMsg  = $errors[0]['error'] ?? $errors[0]['title'] ?? 'Неизвестная ошибка';
                $result['code']    = 'api_error';
                $result['message'] = 'Ошибка МойСклад: ' . $errorMsg;
                Log::error('createProcessingForBatch: ошибка API', [
                    'status'   => $response->status(),
                    'response' => $response->json(),
                    'batch_id' => $batch->id,
                ]);
                return $result;
            }

            $data = $response->json();
            $result['success']         = true;
            $result['processing_id']   = $data['id'] ?? null;
            $result['processing_name'] = $data['name'] ?? $name;
            $result['message']         = 'Техоперация создана: ' . ($data['name'] ?? $name);

            Log::info('createProcessingForBatch: успешно', [
                'processing_id'   => $result['processing_id'],
                'processing_name' => $result['processing_name'],
                'batch_id'        => $batch->id,
            ]);

            return $result;

        } catch (\Exception $e) {
            $result['code']    = 'exception';
            $result['message'] = 'Ошибка: ' . $e->getMessage();
            Log::error('createProcessingForBatch: исключение', [
                'batch_id' => $batch->id,
                'error'    => $e->getMessage(),
            ]);
            return $result;
        }
    }

    /**
     * Получить id существующих позиций продуктов техоперации из МойСклад.
     * Возвращает map: moysklad_product_uuid → position_id.
     * Нужно чтобы при PUT передавать id и МойСклад обновлял, а не добавлял дубли.
     */
    private function fetchExistingProductPositionIds(string $processingId): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization'   => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
            ])->get($this->baseUrl . '/entity/processing/' . $processingId, [
                'expand' => 'products.assortment',
                'limit'  => 100,
            ]);

            if (!$response->successful()) {
                return [];
            }

            $positions = $response->json()['products']['rows'] ?? [];
            $map = [];
            foreach ($positions as $pos) {
                $assortmentHref = $pos['assortment']['meta']['href'] ?? '';
                // извлекаем UUID продукта из конца href
                $productId = basename(parse_url($assortmentHref, PHP_URL_PATH));
                if ($productId && isset($pos['id'])) {
                    $map[$productId] = $pos['id'];
                }
            }
            return $map;
        } catch (\Exception $e) {
            Log::warning('fetchExistingProductPositionIds: не удалось получить позиции', [
                'processing_id' => $processingId,
                'error'         => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Обновить продукты (и опционально количество материала) в существующей техоперации.
     *
     * @param  string             $processingId       UUID техоперации в МойСклад
     * @param  \Illuminate\Support\Collection  $allItems  StoneReceptionItem со всех приёмок партии
     * @param  string             $storeId            UUID склада
     * @param  float|null         $materialQuantity   Новое кол-во сырья (null = не менять материал)
     * @param  string|null        $materialMoyskladId moysklad_id товара-сырья (нужен если $materialQuantity задан)
     * @return array ['success', 'code', 'message']
     */
    public function updateProcessingProducts(
        string $processingId,
        \Illuminate\Support\Collection $allItems,
        string $storeId,
        ?float $materialQuantity = null,
        ?string $materialMoyskladId = null
    ): array {
        $result = ['success' => false, 'code' => '', 'message' => ''];

        try {
            if (!$this->hasCredentials()) {
                throw new \Exception('MoySklad токен не установлен');
            }

            $storeMeta = $this->getStoreMeta($storeId);
            if (!$storeMeta) {
                throw new \Exception('Не удалось получить данные склада');
            }

            // Агрегируем продукты из всех приёмок партии
            $productsGrouped = [];
            $totalQuantity   = 0;

            foreach ($allItems as $item) {
                $product = $item->product;
                if (!$product || !$product->moysklad_id) {
                    continue;
                }
                $moyskladId = $product->moysklad_id;
                if (isset($productsGrouped[$moyskladId])) {
                    $productsGrouped[$moyskladId]['quantity'] += (float) $item->quantity;
                } else {
                    $productMeta = $this->getProductMeta($moyskladId);
                    if (!$productMeta) {
                        continue;
                    }
                    $productsGrouped[$moyskladId] = [
                        'quantity'   => (float) $item->quantity,
                        'assortment' => ['meta' => $productMeta],
                    ];
                }
                $totalQuantity += (float) $item->quantity;
            }

            // Убираем позиции с нулевым суммарным количеством (МойСклад не принимает quantity=0)
            $productsGrouped = array_filter($productsGrouped, fn($p) => $p['quantity'] > 0);
            $totalQuantity   = array_sum(array_column($productsGrouped, 'quantity'));

            if (empty($productsGrouped)) {
                throw new \Exception('Нет продуктов с moysklad_id для обновления техоперации');
            }

            $workerSalaryTotal = 0;
            foreach ($allItems as $item) {
                if (!$item->product || !$item->product->moysklad_id) {
                    continue;
                }
                $workerSalaryTotal += $item->effectiveProdCost() * (float) $item->quantity;
            }

            // Получаем существующие позиции с их id — без id МойСклад добавляет дубли вместо замены
            $existingPositionIds = $this->fetchExistingProductPositionIds($processingId);

            $products = [];
            foreach ($productsGrouped as $moyskladId => $product) {
                $entry = $product;
                if (isset($existingPositionIds[$moyskladId])) {
                    $entry['id'] = $existingPositionIds[$moyskladId];
                }
                $products[] = $entry;
            }

            $payload = [
                'productsStore'  => ['meta' => $storeMeta],
                'materialsStore' => ['meta' => $storeMeta],
                'processingSum'  => $this->calcProcessingSum(
                    $workerSalaryTotal + $totalQuantity * $this->processingSum,
                    $totalQuantity
                ),
                'products'       => $products,
                'quantity'       => $totalQuantity,
            ];

            // Всегда передаём материал явно, чтобы МойСклад не пересчитывал его пропорционально
            if ($materialQuantity !== null && $materialQuantity > 0 && $materialMoyskladId) {
                $rawProductMeta = $this->getProductMeta($materialMoyskladId);
                if ($rawProductMeta) {
                    $payload['materials'] = [[
                        'quantity'   => $materialQuantity,
                        'assortment' => ['meta' => $rawProductMeta],
                    ]];
                }
            }

            $response = Http::withHeaders([
                'Authorization'   => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
                'Content-Type'    => 'application/json',
            ])->put($this->baseUrl . '/entity/processing/' . $processingId, $payload);

            if (!$response->successful()) {
                $errors   = $response->json()['errors'] ?? [];
                $errorMsg = $errors[0]['error'] ?? $errors[0]['title'] ?? 'Неизвестная ошибка';
                $result['code']    = 'api_error';
                $result['message'] = 'Ошибка МойСклад: ' . $errorMsg;
                Log::error('updateProcessingProducts: ошибка API', [
                    'processing_id' => $processingId,
                    'status'        => $response->status(),
                    'response'      => $response->json(),
                ]);
                return $result;
            }

            $result['success'] = true;
            $result['message'] = 'Техоперация обновлена';
            Log::info('updateProcessingProducts: успешно', ['processing_id' => $processingId]);
            return $result;

        } catch (\Exception $e) {
            $result['code']    = 'exception';
            $result['message'] = 'Ошибка: ' . $e->getMessage();
            Log::error('updateProcessingProducts: исключение', [
                'processing_id' => $processingId,
                'error'         => $e->getMessage(),
            ]);
            return $result;
        }
    }

    /**
     * Перевести техоперацию в завершённый статус.
     * Имя статуса берётся из конфига MOYSKLAD_PROCESSING_DONE_STATE_NAME,
     * href разрешается автоматически через /entity/processing/metadata.
     *
     * @param  string  $processingId  UUID техоперации в МойСклад
     * @return array ['success', 'code', 'message']
     */
    public function completeProcessing(string $processingId): array
    {
        $result = ['success' => false, 'code' => '', 'message' => ''];

        try {
            if (!$this->hasCredentials()) {
                throw new \Exception('MoySklad токен не установлен');
            }

            $stateName = Setting::get('MOYSKLAD_DONE_STATE', '');
            if (empty($stateName)) {
                throw new \Exception('Не задано имя завершающего статуса (MOYSKLAD_DONE_STATE)');
            }

            $stateMetaHref = $this->getProcessingStateHref($stateName);
            if (empty($stateMetaHref)) {
                throw new \Exception("Статус «{$stateName}» не найден в МойСклад");
            }

            $payload = [
                'state' => [
                    'meta' => [
                        'href' => $stateMetaHref,
                        'type' => 'state',
                        'mediaType' => 'application/json',
                    ],
                ],
            ];

            $response = Http::withHeaders([
                'Authorization'   => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
                'Content-Type'    => 'application/json',
            ])->put($this->baseUrl . '/entity/processing/' . $processingId, $payload);

            if (!$response->successful()) {
                $errors   = $response->json()['errors'] ?? [];
                $errorMsg = $errors[0]['error'] ?? $errors[0]['title'] ?? 'Неизвестная ошибка';
                $result['code']    = 'api_error';
                $result['message'] = 'Ошибка МойСклад: ' . $errorMsg;
                Log::error('completeProcessing: ошибка API', [
                    'processing_id' => $processingId,
                    'status'        => $response->status(),
                    'response'      => $response->json(),
                ]);
                return $result;
            }

            $result['success'] = true;
            $result['message'] = 'Статус техоперации обновлён';
            Log::info('completeProcessing: успешно', ['processing_id' => $processingId]);
            return $result;

        } catch (\Exception $e) {
            $result['code']    = 'exception';
            $result['message'] = 'Ошибка: ' . $e->getMessage();
            Log::error('completeProcessing: исключение', [
                'processing_id' => $processingId,
                'error'         => $e->getMessage(),
            ]);
            return $result;
        }
    }

    /**
     * Вернуть техоперацию в статус «В работе».
     *
     * @param  string  $processingId  UUID техоперации в МойСклад
     * @return array ['success', 'code', 'message']
     */
    public function reactivateProcessing(string $processingId): array
    {
        $result = ['success' => false, 'code' => '', 'message' => ''];

        try {
            if (!$this->hasCredentials()) {
                throw new \Exception('MoySklad токен не установлен');
            }

            $stateName = Setting::get('MOYSKLAD_IN_WORK_STATE', '');
            if (empty($stateName)) {
                throw new \Exception('Не задано имя статуса «В работе» (MOYSKLAD_IN_WORK_STATE)');
            }

            $stateMetaHref = $this->getProcessingStateHref($stateName);
            if (empty($stateMetaHref)) {
                throw new \Exception("Статус «{$stateName}» не найден в МойСклад");
            }

            $payload = [
                'state' => [
                    'meta' => [
                        'href'      => $stateMetaHref,
                        'type'      => 'state',
                        'mediaType' => 'application/json',
                    ],
                ],
            ];

            $response = Http::withHeaders([
                'Authorization'   => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
                'Content-Type'    => 'application/json',
            ])->put($this->baseUrl . '/entity/processing/' . $processingId, $payload);

            if (!$response->successful()) {
                $errors   = $response->json()['errors'] ?? [];
                $errorMsg = $errors[0]['error'] ?? $errors[0]['title'] ?? 'Неизвестная ошибка';
                $result['code']    = 'api_error';
                $result['message'] = 'Ошибка МойСклад: ' . $errorMsg;
                Log::error('reactivateProcessing: ошибка API', [
                    'processing_id' => $processingId,
                    'status'        => $response->status(),
                    'response'      => $response->json(),
                ]);
                return $result;
            }

            $result['success'] = true;
            $result['message'] = 'Статус техоперации возвращён в «В работе»';
            Log::info('reactivateProcessing: успешно', ['processing_id' => $processingId]);
            return $result;

        } catch (\Exception $e) {
            $result['code']    = 'exception';
            $result['message'] = 'Ошибка: ' . $e->getMessage();
            Log::error('reactivateProcessing: исключение', [
                'processing_id' => $processingId,
                'error'         => $e->getMessage(),
            ]);
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
