<?php

namespace App\Services\Moysklad;

use App\Models\Setting;
use App\Support\DocumentNaming;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use App\Models\RawMaterialBatch;
use App\Models\Store;
use App\Models\Product;
use App\Models\StoneReception;

class StoneReceptionSyncService extends MoySkladBaseService
{
    private float $processingSum;
    private array $processingStatesCache = [];

    public function __construct()
    {
        parent::__construct();
        $this->processingSum = $this->manualCostPerUnit();
    }

    public function manualCostPerUnit(): float
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
     * Найти href статуса техоперации по его имени.
     * Загружает список статусов из /entity/processing/metadata один раз за запрос.
     */
    private function getProcessingStateHref(string $name): ?string
    {
        if (empty($this->processingStatesCache)) {
            $data = $this->get('/entity/processing/metadata');
            if ($data) {
                foreach ($data['states'] ?? [] as $state) {
                    $this->processingStatesCache[$state['name']] = $state['meta']['href'];
                }
            } else {
                Log::warning('getProcessingStateHref: не удалось загрузить метаданные техопераций');
            }
        }

        return $this->processingStatesCache[$name] ?? null;
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
                        'product_id'   => $product->id,
                        'reception_id' => $reception->id,
                    ]);
                    continue;
                }

                $productMeta = $this->getEntityMeta('product', $product->moysklad_id);
                if (!$productMeta) {
                    Log::warning('Не удалось получить метаданные товара', [
                        'product_id'  => $product->id,
                        'moysklad_id' => $product->moysklad_id,
                    ]);
                    continue;
                }

                $products[] = [
                    'quantity'   => (float) $item->quantity,
                    'assortment' => ['meta' => $productMeta],
                ];

                $totalQuantity += $item->quantity;
            }
        }

        return [
            'products'       => $products,
            'total_quantity' => $totalQuantity,
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
            Log::info('StoneReceptionSyncService: начало создания техоперации', [
                'receptions_count' => count($receptions),
                'store_id'         => $storeId,
            ]);

            if (!$this->hasCredentials()) {
                throw new \Exception('MoySklad токен не установлен');
            }

            $organizationMeta = $this->getOrganizationMeta();
            if (!$organizationMeta) {
                throw new \Exception('Не удалось получить данные организации');
            }

            $storeMeta = $this->getEntityMeta('store', $storeId);
            if (!$storeMeta) {
                throw new \Exception('Не удалось получить данные склада');
            }

            $productsGrouped  = [];
            $materialsGrouped = [];
            $totalQuantity    = 0;
            $totalRawQuantity = 0;

            foreach ($receptions as $reception) {
                if (!isset($reception['items']) || !is_array($reception['items'])) {
                    Log::warning('Приемка без items', ['reception_id' => $reception['id'] ?? 'unknown']);
                    continue;
                }

                foreach ($reception['items'] as $item) {
                    if (!isset($item['product']) || !isset($item['product']['moysklad_id'])) {
                        Log::warning('Товар без moysklad_id', ['item' => $item]);
                        continue;
                    }

                    $moyskladId = $item['product']['moysklad_id'];
                    $quantity   = (float) $item['quantity'];

                    if (isset($productsGrouped[$moyskladId])) {
                        $productsGrouped[$moyskladId]['quantity'] += $quantity;
                        Log::info('Суммируем продукт', [
                            'moysklad_id'  => $moyskladId,
                            'new_quantity' => $productsGrouped[$moyskladId]['quantity'],
                        ]);
                    } else {
                        $productMeta = $this->getEntityMeta('product', $moyskladId);
                        if (!$productMeta) {
                            Log::warning('Не удалось получить метаданные товара', ['moysklad_id' => $moyskladId]);
                            continue;
                        }

                        $productsGrouped[$moyskladId] = [
                            'quantity'   => $quantity,
                            'assortment' => ['meta' => $productMeta],
                        ];
                    }

                    $totalQuantity += $quantity;
                }

                if (isset($reception['raw_material_batch']) && isset($reception['raw_material_batch']['product'])) {
                    $rawProduct = $reception['raw_material_batch']['product'];

                    if (isset($rawProduct['moysklad_id'])) {
                        $rawMoyskladId = $rawProduct['moysklad_id'];
                        $rawQuantity   = (float) $reception['raw_quantity_used'];

                        if (isset($materialsGrouped[$rawMoyskladId])) {
                            $materialsGrouped[$rawMoyskladId]['quantity'] += $rawQuantity;
                            Log::info('Суммируем сырье', [
                                'moysklad_id'  => $rawMoyskladId,
                                'new_quantity' => $materialsGrouped[$rawMoyskladId]['quantity'],
                            ]);
                        } else {
                            $rawProductMeta = $this->getEntityMeta('product', $rawMoyskladId);
                            if ($rawProductMeta) {
                                $materialsGrouped[$rawMoyskladId] = [
                                    'quantity'   => $rawQuantity,
                                    'assortment' => ['meta' => $rawProductMeta],
                                ];
                            } else {
                                Log::warning('Не удалось получить метаданные сырья', ['moysklad_id' => $rawMoyskladId]);
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

            $products       = array_values($productsGrouped);
            $materials      = array_values($materialsGrouped);
            $processingSum  = $this->calcProcessingSum($totalQuantity * $this->processingSum, $totalQuantity);

            $processingData = [
                'organization'  => ['meta' => $organizationMeta],
                'productsStore' => ['meta' => $storeMeta],
                'materialsStore'=> ['meta' => $storeMeta],
                'processingSum' => $processingSum,
                'products'      => $products,
                'materials'     => $materials,
                'name'          => $name ?? DocumentNaming::weeklyName('ТО', 1),
                'quantity'      => $totalQuantity,
            ];

            Log::info('Отправка запроса в МойСклад', [
                'products_count'    => count($products),
                'products_details'  => array_map(fn($p) => ['quantity' => $p['quantity']], $products),
                'materials_count'   => count($materials),
                'materials_details' => array_map(fn($m) => ['quantity' => $m['quantity']], $materials),
                'total_quantity'    => $totalQuantity,
                'total_raw_quantity'=> $totalRawQuantity,
                'processing_sum'    => $processingSum,
            ]);

            $response = $this->post('/entity/processing', $processingData);

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

            $result['success']       = true;
            $result['processing_id'] = $processingResponse['id'] ?? null;
            $result['message']       = 'Техоперация успешно создана';

            Log::info('Техоперация создана', [
                'processing_id'      => $result['processing_id'],
                'products_count'     => count($products),
                'materials_count'    => count($materials),
                'total_quantity'     => $totalQuantity,
                'total_raw_quantity' => $totalRawQuantity,
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('Исключение при создании техоперации', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $result['message'] = 'Ошибка: ' . $e->getMessage();
            return $result;
        }
    }

    /**
     * Создать техоперацию для приёмки (1 приёмка = 1 техоперация).
     *
     * @param  StoneReception  $reception
     * @return array ['success', 'processing_id', 'processing_name', 'code', 'message']
     */
    public function createProcessingForReception(StoneReception $reception, ?string $customName = null): array
    {
        $reception->loadMissing('rawMaterialBatch.product');
        $batch = $reception->rawMaterialBatch;

        return $this->createProcessingForBatch(
            $batch,
            (float) $reception->raw_quantity_used,
            $reception,
            $customName
        );
    }

    /**
     * @deprecated Используйте createProcessingForReception()
     *
     * Создать техоперацию для партии сырья на основе первой приёмки.
     *
     * @return array ['success', 'processing_id', 'processing_name', 'code', 'message']
     */
    public function createProcessingForBatch(
        RawMaterialBatch $batch,
        float $materialQuantity,
        StoneReception $reception,
        ?string $customName = null
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

            $storeMeta = $this->getEntityMeta('store', $reception->store_id);
            if (!$storeMeta) {
                throw new \Exception('Не удалось получить данные склада');
            }

            $rawProduct = $batch->product;
            if (!$rawProduct || !$rawProduct->moysklad_id) {
                throw new \Exception('Товар партии не синхронизирован с МойСклад (нет moysklad_id)');
            }
            $rawProductMeta = $this->getEntityMeta('product', $rawProduct->moysklad_id);
            if (!$rawProductMeta) {
                throw new \Exception('Не удалось получить метаданные сырья из МойСклад');
            }

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
                    $productMeta = $this->getEntityMeta('product', $moyskladId);
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

            $productsGrouped = array_filter($productsGrouped, fn($p) => $p['quantity'] > 0);

            if (empty($productsGrouped)) {
                throw new \Exception('Нет продуктов с moysklad_id для создания техоперации');
            }

            $receptionDate = $reception->created_at ?? now();
            $weekCount = \App\Models\StoneReception::whereBetween('created_at', [
                $receptionDate->copy()->startOfWeek(Carbon::FRIDAY),
                $receptionDate->copy()->endOfWeek(Carbon::THURSDAY),
            ])->whereNotNull('raw_material_batch_id')
              ->distinct('raw_material_batch_id')
              ->count();

            $name = $customName ?? DocumentNaming::weeklyName('ТО', $weekCount + 1, $receptionDate);

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
                'moment'         => $receptionDate->format('Y-m-d H:i:s'),
            ];

            $batchName = $batch->moysklad_processing_name ?? ($batch->batch_number ? "партия №{$batch->batch_number}" : null);
            if ($batchName) {
                $processingData['description'] = $batchName;
            }

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
                $response = $this->post('/entity/processing', $processingData);

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
                $errors   = $response->json()['errors'] ?? [];
                $errorMsg = $errors[0]['error'] ?? $errors[0]['title'] ?? 'Неизвестная ошибка';
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
     */
    private function fetchExistingProductPositionIds(string $processingId): array
    {
        $data = $this->get('/entity/processing/' . $processingId, [
            'expand' => 'products.assortment',
            'limit'  => 100,
        ]);

        if (!$data) {
            return [];
        }

        $positions = $data['products']['rows'] ?? [];
        $map = [];
        foreach ($positions as $pos) {
            $assortmentHref = $pos['assortment']['meta']['href'] ?? '';
            $productId = basename(parse_url($assortmentHref, PHP_URL_PATH));
            if ($productId && isset($pos['id'])) {
                $map[$productId] = $pos['id'];
            }
        }
        return $map;
    }

    /**
     * Обновить продукты (и опционально количество материала) в существующей техоперации.
     *
     * @param  string             $processingId       UUID техоперации в МойСклад
     * @param  \Illuminate\Support\Collection  $allItems  StoneReceptionItem со всех приёмок партии
     * @param  string             $storeId            UUID склада
     * @param  float|null         $materialQuantity   Новое кол-во сырья (null = не менять материал)
     * @param  string|null        $materialMoyskladId moysklad_id товара-сырья
     * @return array ['success', 'code', 'message']
     */
    public function updateProcessingProducts(
        string $processingId,
        \Illuminate\Support\Collection $allItems,
        string $storeId,
        ?float $materialQuantity = null,
        ?string $materialMoyskladId = null,
        ?string $description = null
    ): array {
        $result = ['success' => false, 'code' => '', 'message' => ''];

        try {
            if (!$this->hasCredentials()) {
                throw new \Exception('MoySklad токен не установлен');
            }

            $storeMeta = $this->getEntityMeta('store', $storeId);
            if (!$storeMeta) {
                throw new \Exception('Не удалось получить данные склада');
            }

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
                    $productMeta = $this->getEntityMeta('product', $moyskladId);
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
                'products'  => $products,
                'quantity'  => $totalQuantity,
            ];

            if ($description !== null) {
                $payload['description'] = $description;
            }

            if ($materialQuantity !== null && $materialQuantity > 0 && $materialMoyskladId) {
                $rawProductMeta = $this->getEntityMeta('product', $materialMoyskladId);
                if ($rawProductMeta) {
                    $payload['materials'] = [[
                        'quantity'   => $materialQuantity,
                        'assortment' => ['meta' => $rawProductMeta],
                    ]];
                }
            }

            $response = $this->put('/entity/processing/' . $processingId, $payload);

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
                        'href'      => $stateMetaHref,
                        'type'      => 'state',
                        'mediaType' => 'application/json',
                    ],
                ],
            ];

            $response = $this->put('/entity/processing/' . $processingId, $payload);

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

            $response = $this->put('/entity/processing/' . $processingId, $payload);

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
     * Синхронизирует приёмку с МойСклад: создаёт техоперацию (первая синхронизация)
     * или обновляет продукты/материал (повторная).
     */
    public function syncReception(StoneReception $reception, ?string $customName = null): void
    {
        $batch = $reception->rawMaterialBatch;
        if (!$batch) {
            return;
        }

        try {
            if (!$reception->hasMoySkladProcessing()) {
                $reception->loadMissing('items.product', 'rawMaterialBatch.product');
                $result = $this->createProcessingForReception($reception, $customName);

                if ($result['success']) {
                    $reception->markSynced($result['processing_id'], $result['processing_name']);
                } else {
                    $reception->markSyncError($result['message']);
                    Log::warning('syncReception: не удалось создать техоперацию', [
                        'reception_id' => $reception->id,
                        'message'      => $result['message'],
                    ]);
                }
            } else {
                $reception->loadMissing('items.product', 'rawMaterialBatch.product');
                $batchName = $batch->moysklad_processing_name ?? ($batch->batch_number ? "партия №{$batch->batch_number}" : null);
                $result = $this->updateProcessingProducts(
                    $reception->moysklad_processing_id,
                    $reception->items,
                    $reception->store_id ?? '',
                    (float) $reception->raw_quantity_used,
                    $batch->product->moysklad_id ?? '',
                    $batchName
                );

                if ($result['success']) {
                    $reception->markSynced($reception->moysklad_processing_id);
                } else {
                    $reception->markSyncError($result['message']);
                    Log::warning('syncReception: не удалось обновить техоперацию', [
                        'reception_id'  => $reception->id,
                        'processing_id' => $reception->moysklad_processing_id,
                        'message'       => $result['message'],
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('syncReception: исключение', [
                'reception_id' => $reception->id,
                'error'        => $e->getMessage(),
            ]);
            $reception->markSyncError('Ошибка: ' . $e->getMessage());
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

        return $this->get('/entity/processing/' . $processingId);
    }
}
