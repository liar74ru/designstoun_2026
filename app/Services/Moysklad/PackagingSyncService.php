<?php

namespace App\Services\Moysklad;

use App\Models\Packaging;
use App\Models\PackagingLog;
use App\Models\Setting;
use App\Models\Worker;
use App\Services\Moysklad\Concerns\HandlesProcessingSync;
use App\Support\DocumentNaming;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PackagingSyncService extends MoySkladBaseService
{
    use HandlesProcessingSync;

    /**
     * Создать техоперацию для упаковки.
     *
     * @return array ['success', 'processing_id', 'processing_name', 'code', 'message']
     */
    public function createProcessingForPackaging(Packaging $packaging, ?string $customName = null, ?string $description = null): array
    {
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

            $storeMeta = $this->getEntityMeta('store', $packaging->store_id);
            if (!$storeMeta) {
                throw new \Exception('Не удалось получить данные склада');
            }

            $packageProduct = $packaging->packageProduct;
            if (!$packageProduct || !$packageProduct->moysklad_id) {
                throw new \Exception('Тара упаковки не синхронизирована с МойСклад (нет moysklad_id)');
            }
            $packageMeta = $this->getEntityMeta('product', $packageProduct->moysklad_id);
            if (!$packageMeta) {
                throw new \Exception('Не удалось получить метаданные тары из МойСклад');
            }

            $packaging->loadMissing('items.product');
            $productsGrouped = [];
            $totalQuantity   = 0;

            foreach ($packaging->items as $item) {
                $product = $item->product;
                if (!$product || !$product->moysklad_id) {
                    Log::warning('createProcessingForPackaging: товар без moysklad_id', [
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
                        Log::warning('createProcessingForPackaging: не удалось получить мета товара', [
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
                throw new \Exception('Нет упакованных продуктов с moysklad_id для создания техоперации');
            }

            // Materials = упакованные продукты + тара
            $materials = array_values($productsGrouped);
            $materials[] = [
                'quantity'   => (float) $packaging->package_quantity,
                'assortment' => ['meta' => $packageMeta],
            ];

            $packagingDate = $packaging->created_at ?? now();
            $weekCount = Packaging::whereBetween('created_at', [
                $packagingDate->copy()->startOfWeek(Carbon::FRIDAY),
                $packagingDate->copy()->endOfWeek(Carbon::THURSDAY),
            ])->count();

            $name = $customName ?? DocumentNaming::weeklyName('УПАК', $weekCount + 1, $packagingDate);

            $workerSalaryTotal = 0;
            foreach ($packaging->items as $item) {
                $workerSalaryTotal += $item->effectiveProdCost() * (float) $item->quantity;
            }
            $totalProcessingSum = $this->calcProcessingSum($workerSalaryTotal, $totalQuantity);

            $processingData = [
                'organization'   => ['meta' => $organizationMeta],
                'productsStore'  => ['meta' => $storeMeta],
                'materialsStore' => ['meta' => $storeMeta],
                'processingSum'  => $totalProcessingSum,
                'products'       => array_values($productsGrouped),
                'materials'      => $materials,
                'name'           => $name,
                'quantity'       => $totalQuantity,
                'moment'         => $packagingDate->format('Y-m-d H:i:s'),
            ];

            if ($description !== null) {
                $processingData['description'] = $description;
            }

            $inWorkName = Setting::get('MOYSKLAD_IN_WORK_STATE', '');
            if ($inWorkName) {
                $inWorkHref = $this->getProcessingStateHref($inWorkName);
                if ($inWorkHref) {
                    $processingData['state'] = [
                        'meta' => ['href' => $inWorkHref, 'type' => 'state', 'mediaType' => 'application/json'],
                    ];
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
                Log::error('createProcessingForPackaging: ошибка API', [
                    'status'       => $response->status(),
                    'response'     => $response->json(),
                    'packaging_id' => $packaging->id,
                ]);
                return $result;
            }

            $data = $response->json();
            $result['success']         = true;
            $result['processing_id']   = $data['id'] ?? null;
            $result['processing_name'] = $data['name'] ?? $name;
            $result['message']         = 'Техоперация создана: ' . ($data['name'] ?? $name);

            Log::info('createProcessingForPackaging: успешно', [
                'processing_id'   => $result['processing_id'],
                'processing_name' => $result['processing_name'],
                'packaging_id'    => $packaging->id,
            ]);

            return $result;

        } catch (\Exception $e) {
            $result['code']    = 'exception';
            $result['message'] = 'Ошибка: ' . $e->getMessage();
            Log::error('createProcessingForPackaging: исключение', [
                'packaging_id' => $packaging->id,
                'error'        => $e->getMessage(),
            ]);
            return $result;
        }
    }

    /**
     * Обновить техоперацию упаковки: продукты, материалы (продукты + тара), processingSum, description.
     *
     * @param  string                            $processingId
     * @param  \Illuminate\Support\Collection    $items                 Коллекция PackagingItem
     * @param  string                            $storeId
     * @param  string|null                       $packageMoyskladId     UUID товара-тары в МойСклад
     * @param  float                             $packageQuantity       Количество тары
     * @param  string|null                       $description
     * @return array ['success', 'code', 'message']
     */
    public function updateProcessingProducts(
        string $processingId,
        \Illuminate\Support\Collection $items,
        string $storeId,
        ?string $packageMoyskladId,
        float $packageQuantity,
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

            foreach ($items as $item) {
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
                throw new \Exception('Нет упакованных продуктов с moysklad_id для обновления техоперации');
            }

            $workerSalaryTotal = 0;
            foreach ($items as $item) {
                if (!$item->product || !$item->product->moysklad_id) {
                    continue;
                }
                $workerSalaryTotal += $item->effectiveProdCost() * (float) $item->quantity;
            }

            $existingProductIds  = $this->fetchExistingPositionIds($processingId, 'products');
            $existingMaterialIds = $this->fetchExistingPositionIds($processingId, 'materials');

            $products = [];
            foreach ($productsGrouped as $moyskladId => $product) {
                $entry = $product;
                if (isset($existingProductIds[$moyskladId])) {
                    $entry['id'] = $existingProductIds[$moyskladId];
                }
                $products[] = $entry;
            }

            // Materials = упакованные продукты + тара
            $materials = [];
            foreach ($productsGrouped as $moyskladId => $product) {
                $entry = $product;
                if (isset($existingMaterialIds[$moyskladId])) {
                    $entry['id'] = $existingMaterialIds[$moyskladId];
                }
                $materials[] = $entry;
            }

            if ($packageMoyskladId && $packageQuantity > 0) {
                $packageMeta = $this->getEntityMeta('product', $packageMoyskladId);
                if ($packageMeta) {
                    $entry = [
                        'quantity'   => $packageQuantity,
                        'assortment' => ['meta' => $packageMeta],
                    ];
                    if (isset($existingMaterialIds[$packageMoyskladId])) {
                        $entry['id'] = $existingMaterialIds[$packageMoyskladId];
                    }
                    $materials[] = $entry;
                }
            }

            $payload = [
                'productsStore'  => ['meta' => $storeMeta],
                'materialsStore' => ['meta' => $storeMeta],
                'processingSum'  => $this->calcProcessingSum($workerSalaryTotal, $totalQuantity),
                'products'       => $products,
                'materials'      => $materials,
                'quantity'       => $totalQuantity,
            ];

            if ($description !== null) {
                $payload['description'] = $description;
            }

            $response = $this->put('/entity/processing/' . $processingId, $payload);

            if (!$response->successful()) {
                $result['code']    = 'api_error';
                $result['message'] = 'Ошибка МойСклад: ' . $this->extractApiError($response->json());
                Log::error('PackagingSyncService::updateProcessingProducts: ошибка API', [
                    'processing_id' => $processingId,
                    'status'        => $response->status(),
                    'response'      => $response->json(),
                ]);
                return $result;
            }

            $result['success'] = true;
            $result['message'] = 'Техоперация обновлена';
            Log::info('PackagingSyncService::updateProcessingProducts: успешно', [
                'processing_id' => $processingId,
            ]);
            return $result;

        } catch (\Exception $e) {
            $result['code']    = 'exception';
            $result['message'] = 'Ошибка: ' . $e->getMessage();
            Log::error('PackagingSyncService::updateProcessingProducts: исключение', [
                'processing_id' => $processingId,
                'error'         => $e->getMessage(),
            ]);
            return $result;
        }
    }

    /**
     * Синхронизировать упаковку с МойСклад: создать техоперацию или обновить.
     */
    public function syncPackaging(Packaging $packaging, ?string $customName = null): void
    {
        try {
            $packaging->loadMissing('items.product', 'packageProduct');
            $description = $this->buildPackagingDescription($packaging);

            if (!$packaging->hasMoySkladProcessing()) {
                $result = $this->createProcessingForPackaging($packaging, $customName, $description ?: null);

                if ($result['success']) {
                    $packaging->markSynced($result['processing_id'], $result['processing_name']);
                } else {
                    $packaging->markSyncError($result['message']);
                    Log::warning('syncPackaging: не удалось создать техоперацию', [
                        'packaging_id' => $packaging->id,
                        'message'      => $result['message'],
                    ]);
                }
            } else {
                $result = $this->updateProcessingProducts(
                    $packaging->moysklad_processing_id,
                    $packaging->items,
                    $packaging->store_id ?? '',
                    $packaging->packageProduct?->moysklad_id,
                    (float) $packaging->package_quantity,
                    $description ?: null
                );

                if ($result['success']) {
                    $packaging->markSynced($packaging->moysklad_processing_id);
                } else {
                    $packaging->markSyncError($result['message']);
                    Log::warning('syncPackaging: не удалось обновить техоперацию', [
                        'packaging_id'  => $packaging->id,
                        'processing_id' => $packaging->moysklad_processing_id,
                        'message'       => $result['message'],
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('syncPackaging: исключение', [
                'packaging_id' => $packaging->id,
                'error'        => $e->getMessage(),
            ]);
            $packaging->markSyncError('Ошибка: ' . $e->getMessage());
        }
    }

    private function buildPackagingDescription(Packaging $packaging): string
    {
        $packageName = $packaging->packageProduct?->name
            ? 'Упаковка: ' . $packaging->packageProduct->name
            : 'Упаковка';

        $logs = PackagingLog::where('packaging_id', $packaging->id)
            ->orderBy('created_at')
            ->with('items.product', 'packer', 'receiver')
            ->get();

        if ($logs->isEmpty()) {
            return $packageName;
        }

        $packerIds   = $logs->pluck('packer_id')->filter()->unique();
        $packers     = Worker::whereIn('id', $packerIds)->pluck('name', 'id');
        $receiverIds = $logs->pluck('receiver_id')->filter()->unique();
        $receivers   = Worker::whereIn('id', $receiverIds)->pluck('name', 'id');

        $blocks = $logs->map(function (PackagingLog $log) use ($packers, $receivers) {
            $date         = $log->created_at->format('d.m.Y');
            $packerName   = $packers[$log->packer_id]   ?? '—';
            $receiverName = $receivers[$log->receiver_id] ?? '—';
            $lines        = ["___", "{$date} #{$log->id} упаковщик: {$packerName} / приёмщик: {$receiverName}"];

            $packageDelta = (float) $log->package_quantity_delta;
            if (abs($packageDelta) > 0.0001) {
                $sign = $packageDelta >= 0 ? '+' : '';
                $lines[] = "Тара: {$sign}" . number_format($packageDelta, 3, '.', '') . ' шт';
            }

            foreach ($log->items as $item) {
                $productName = $item->product?->name ?? "Товар #{$item->product_id}";
                $delta       = (float) $item->quantity_delta;
                $sign        = $delta >= 0 ? '+' : '';
                $lines[]     = "{$productName}: {$sign}" . number_format($delta, 3, '.', '');
            }

            return implode("\n", $lines);
        });

        return trim($packageName . "\n" . $blocks->join("\n") . "\n___", "\n");
    }

    public function getProcessing(string $processingId)
    {
        if (!$this->hasCredentials()) {
            return null;
        }
        return $this->get('/entity/processing/' . $processingId);
    }
}
