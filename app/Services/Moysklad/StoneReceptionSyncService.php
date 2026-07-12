<?php

namespace App\Services\Moysklad;

use App\Models\Setting;
use App\Services\Moysklad\Concerns\HandlesProcessingSync;
use App\Support\DocumentNaming;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use App\Models\RawMaterialBatch;
use App\Models\ReceptionLog;
use App\Models\Store;
use App\Models\Product;
use App\Models\StoneReception;
use App\Models\Worker;

class StoneReceptionSyncService extends MoySkladBaseService
{
    use HandlesProcessingSync;

    private float $processingSum;

    public function __construct(
        private StockSyncService $stockSyncService,
    ) {
        parent::__construct();
        $this->processingSum = $this->manualCostPerUnit();
    }

    public function manualCostPerUnit(): float
    {
        $keys = [
            'BLADE_WEAR', 'RECEPTION_COST', 'WASTE_REMOVAL',
            'ELECTRICITY', 'PPE_COST', 'FORKLIFT_COST', 'MACHINE_COST',
            'RENT_COST', 'OTHER_COSTS',
        ];
        return array_sum(array_map(
            fn ($key) => (float) \App\Models\Setting::get($key, 0),
            $keys
        ));
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
                $body   = $response->json();
                $errors = $body['errors'] ?? [];

                Log::error('Ошибка API МойСклад', [
                    'status'       => $response->status(),
                    'response'     => $body,
                    'request_data' => $processingData,
                ]);

                $result['code']    = DocumentNaming::isDuplicateName($errors) ? 'duplicate_name' : 'api_error';
                $result['message'] = 'Ошибка МойСклад: ' . $this->extractApiError($body);
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
    public function createProcessingForReception(StoneReception $reception, ?string $customName = null, ?string $description = null): array
    {
        $reception->loadMissing('rawMaterialBatch.product');
        $batch = $reception->rawMaterialBatch;

        return $this->createProcessingForBatch(
            $batch,
            (float) $reception->raw_quantity_used,
            $reception,
            $customName,
            $description
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
        ?string $customName = null,
        ?string $description = null
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
            $masterSalaryTotal = 0;
            foreach ($reception->items as $item) {
                $workerSalaryTotal += $item->effectiveProdCost() * (float) $item->quantity;
                $masterSalaryTotal += (float) ($item->master_cost_per_m2 ?? 0) * (float) $item->quantity;
            }
            $totalProcessingSum = $this->calcProcessingSum(
                $workerSalaryTotal + $masterSalaryTotal + $this->processingSum * $totalQuantity,
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

            if ($description !== null) {
                $processingData['description'] = $description;
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
                $result['code']    = 'api_error';
                $result['message'] = 'Ошибка МойСклад: ' . $this->extractApiError($response->json());
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
            $masterSalaryTotal = 0;
            foreach ($allItems as $item) {
                if (!$item->product || !$item->product->moysklad_id) {
                    continue;
                }
                $workerSalaryTotal += $item->effectiveProdCost() * (float) $item->quantity;
                $masterSalaryTotal += (float) ($item->master_cost_per_m2 ?? 0) * (float) $item->quantity;
            }

            $existingPositionIds = $this->fetchExistingPositionIds($processingId, 'products');

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
                    $workerSalaryTotal + $masterSalaryTotal + $totalQuantity * $this->processingSum,
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
                $result['code']    = 'api_error';
                $result['message'] = 'Ошибка МойСклад: ' . $this->extractApiError($response->json());
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
            $reception->loadMissing('items.product', 'rawMaterialBatch.product');
            $description = $this->buildReceptionDescription($reception, $batch);

            if (!$reception->hasMoySkladProcessing()) {
                $result = $this->createProcessingForReception($reception, $customName, $description ?: null);

                if ($result['success']) {
                    $reception->markSynced($result['processing_id'], $result['processing_name']);
                    $this->refreshAffectedStocks($reception);
                } else {
                    $reception->markSyncError($result['message']);
                    Log::warning('syncReception: не удалось создать техоперацию', [
                        'reception_id' => $reception->id,
                        'message'      => $result['message'],
                    ]);
                }
            } else {
                $result = $this->updateProcessingProducts(
                    $reception->moysklad_processing_id,
                    $reception->items,
                    $reception->store_id ?? '',
                    (float) $reception->raw_quantity_used,
                    $batch->product->moysklad_id ?? '',
                    $description ?: null
                );

                if ($result['success']) {
                    $reception->markSynced($reception->moysklad_processing_id);
                    $this->refreshAffectedStocks($reception);
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
     * Подтянуть из МойСклад актуальные остатки товаров, затронутых приёмкой:
     * сырьё партии + вся готовая продукция. Вызывается только после успешной
     * техоперации. Ошибка подтяжки не должна ронять поток приёмки — логируем.
     */
    private function refreshAffectedStocks(StoneReception $reception): void
    {
        try {
            $ids = collect();
            $ids->push($reception->rawMaterialBatch?->product?->moysklad_id);
            foreach ($reception->items as $item) {
                $ids->push($item->product?->moysklad_id);
            }

            foreach ($ids->filter()->unique() as $moyskladId) {
                $this->stockSyncService->updateProductStocksByMoyskladId($moyskladId);
            }
        } catch (\Exception $e) {
            Log::warning('refreshAffectedStocks: не удалось обновить остатки', [
                'reception_id' => $reception->id,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    private function buildReceptionDescription(StoneReception $reception, RawMaterialBatch $batch): string
    {
        $batchName = $batch->moysklad_processing_name
            ?? ($batch->batch_number ? "партия №{$batch->batch_number}" : '');

        $logs = ReceptionLog::where('stone_reception_id', $reception->id)
            ->orderBy('created_at')
            ->with('items.product')
            ->get();

        if ($logs->isEmpty()) {
            return $batchName;
        }

        $undercutMap = $reception->items->keyBy('product_id')->map(fn($i) => (bool) $i->is_undercut);
        $edgingMap   = $reception->items->keyBy('product_id')->map(fn($i) => (bool) $i->is_edging);
        $receiverIds = $logs->pluck('receiver_id')->filter()->unique();
        $receivers   = Worker::whereIn('id', $receiverIds)->pluck('name', 'id');

        $blocks = $logs->map(function (ReceptionLog $log) use ($receivers, $undercutMap, $edgingMap) {
            $date         = $log->created_at->format('d.m.Y');
            $receiverName = $receivers[$log->receiver_id] ?? '—';
            $lines        = ["___", "{$date} #{$log->id} {$receiverName}"];

            foreach ($log->items as $item) {
                $productName = $item->product?->name ?? "Товар #{$item->product_id}";
                $delta       = (float) $item->quantity_delta;
                $sign        = $delta >= 0 ? '+' : '';
                $tags        = [];
                if ($undercutMap[$item->product_id] ?? false) $tags[] = 'подкол';
                if ($edgingMap[$item->product_id]   ?? false) $tags[] = 'торцовка';
                $suffix      = $tags ? ' (' . implode(', ', $tags) . ')' : '';
                $lines[]     = "{$productName}: {$sign}" . number_format($delta, 3, '.', '') . $suffix;
            }

            return implode("\n", $lines);
        });

        return trim($batchName . "\n" . $blocks->join("\n") . "\n___", "\n");
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
