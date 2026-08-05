<?php

namespace App\Services\Moysklad;

use App\Models\Workshop;
use App\Models\WorkshopItem;
use App\Models\WorkshopLog;
use App\Models\Setting;
use App\Models\Worker;
use App\Services\Moysklad\Concerns\HandlesProcessingSync;
use App\Support\DocumentNaming;
use Illuminate\Support\Facades\Log;

class WorkshopSyncService extends MoySkladBaseService
{
    use HandlesProcessingSync;

    public function __construct(
        private StockSyncService $stockSyncService,
    ) {
        parent::__construct();
    }

    /**
     * Создать техоперацию для операции цеха.
     *
     * Materials = сырьё (role=raw) + упаковка (role=package);
     * Products  = продукт на выходе (role=product).
     * processingSum = ручные затраты (₽/ед × 100) или авто-fallback по зарплате сырья.
     *
     * @return array ['success', 'processing_id', 'processing_name', 'code', 'message']
     */
    public function createProcessingForWorkshop(Workshop $workshop, ?string $customName = null, ?string $description = null): array
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

            [$materialsStoreMeta, $productsStoreMeta] = $this->getStoreMetas($workshop);

            $workshop->loadMissing('items.product');

            $rawGrouped     = $this->groupItemsByMoysklad($workshop->items->where('role', WorkshopItem::ROLE_RAW));
            $packageGrouped = $this->groupItemsByMoysklad($workshop->items->where('role', WorkshopItem::ROLE_PACKAGE));
            $productGrouped = $this->groupItemsByMoysklad($workshop->items->where('role', WorkshopItem::ROLE_PRODUCT));

            if (empty($productGrouped)) {
                throw new \Exception('Нет продуктов на выходе с moysklad_id для создания техоперации');
            }

            // Materials = сырьё + упаковка; Products = продукт на выходе.
            $materials     = array_values($this->mergeGrouped($rawGrouped, $packageGrouped));
            $products      = array_values($productGrouped);
            $totalQuantity = array_sum(array_column($productGrouped, 'quantity'));

            $workshopDate = $workshop->created_at ?? now();
            $weekPrefix = DocumentNaming::weekPrefix('ЦЕХ', $workshopDate);
            $sequence = DocumentNaming::nextSequence(
                Workshop::where('moysklad_processing_name', 'like', $weekPrefix . '%')
                    ->pluck('moysklad_processing_name'),
                $weekPrefix
            );

            $name = $customName ?? DocumentNaming::weeklyName('ЦЕХ', $sequence, $workshopDate);

            $processingData = [
                'organization'   => ['meta' => $organizationMeta],
                'productsStore'  => ['meta' => $productsStoreMeta],
                'materialsStore' => ['meta' => $materialsStoreMeta],
                'processingSum'  => $this->computeProcessingSum($workshop, $totalQuantity),
                'products'       => $products,
                'materials'      => $materials,
                'name'           => $name,
                'quantity'       => $totalQuantity,
                'moment'         => $workshopDate->format('Y-m-d H:i:s'),
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
                Log::error('createProcessingForWorkshop: ошибка API', [
                    'status'       => $response->status(),
                    'response'     => $response->json(),
                    'workshop_id' => $workshop->id,
                ]);
                return $result;
            }

            $data = $response->json();
            $result['success']         = true;
            $result['processing_id']   = $data['id'] ?? null;
            $result['processing_name'] = $data['name'] ?? $name;
            $result['message']         = 'Техоперация создана: ' . ($data['name'] ?? $name);

            Log::info('createProcessingForWorkshop: успешно', [
                'processing_id'   => $result['processing_id'],
                'processing_name' => $result['processing_name'],
                'workshop_id'    => $workshop->id,
            ]);

            return $result;

        } catch (\Exception $e) {
            $result['code']    = 'exception';
            $result['message'] = 'Ошибка: ' . $e->getMessage();
            Log::error('createProcessingForWorkshop: исключение', [
                'workshop_id' => $workshop->id,
                'error'        => $e->getMessage(),
            ]);
            return $result;
        }
    }

    /**
     * Обновить техоперацию цеха: продукты, материалы (сырьё + упаковка), processingSum, description.
     *
     * @param  string      $processingId
     * @param  Workshop   $workshop
     * @param  string|null $description
     * @return array ['success', 'code', 'message']
     */
    public function updateProcessingProducts(
        string $processingId,
        Workshop $workshop,
        ?string $description = null
    ): array {
        $result = ['success' => false, 'code' => '', 'message' => ''];

        try {
            if (!$this->hasCredentials()) {
                throw new \Exception('MoySklad токен не установлен');
            }

            $workshop->loadMissing('items.product');

            [$materialsStoreMeta, $productsStoreMeta] = $this->getStoreMetas($workshop);

            $rawGrouped     = $this->groupItemsByMoysklad($workshop->items->where('role', WorkshopItem::ROLE_RAW));
            $packageGrouped = $this->groupItemsByMoysklad($workshop->items->where('role', WorkshopItem::ROLE_PACKAGE));
            $productGrouped = $this->groupItemsByMoysklad($workshop->items->where('role', WorkshopItem::ROLE_PRODUCT));

            if (empty($productGrouped)) {
                throw new \Exception('Нет продуктов на выходе с moysklad_id для обновления техоперации');
            }

            $totalQuantity = array_sum(array_column($productGrouped, 'quantity'));

            $existingProductIds  = $this->fetchExistingPositionIds($processingId, 'products');
            $existingMaterialIds = $this->fetchExistingPositionIds($processingId, 'materials');

            $products = [];
            foreach ($productGrouped as $moyskladId => $product) {
                $entry = $product;
                if (isset($existingProductIds[$moyskladId])) {
                    $entry['id'] = $existingProductIds[$moyskladId];
                }
                $products[] = $entry;
            }

            // Materials = сырьё + упаковка.
            $materials = [];
            foreach ($this->mergeGrouped($rawGrouped, $packageGrouped) as $moyskladId => $product) {
                $entry = $product;
                if (isset($existingMaterialIds[$moyskladId])) {
                    $entry['id'] = $existingMaterialIds[$moyskladId];
                }
                $materials[] = $entry;
            }

            $payload = [
                'productsStore'  => ['meta' => $productsStoreMeta],
                'materialsStore' => ['meta' => $materialsStoreMeta],
                'processingSum'  => $this->computeProcessingSum($workshop, $totalQuantity),
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
                Log::error('WorkshopSyncService::updateProcessingProducts: ошибка API', [
                    'processing_id' => $processingId,
                    'status'        => $response->status(),
                    'response'      => $response->json(),
                ]);
                return $result;
            }

            $result['success'] = true;
            $result['message'] = 'Техоперация обновлена';
            Log::info('WorkshopSyncService::updateProcessingProducts: успешно', [
                'processing_id' => $processingId,
            ]);
            return $result;

        } catch (\Exception $e) {
            $result['code']    = 'exception';
            $result['message'] = 'Ошибка: ' . $e->getMessage();
            Log::error('WorkshopSyncService::updateProcessingProducts: исключение', [
                'processing_id' => $processingId,
                'error'         => $e->getMessage(),
            ]);
            return $result;
        }
    }

    /**
     * Синхронизировать операцию цеха с МойСклад: создать техоперацию или обновить.
     */
    public function syncWorkshop(Workshop $workshop, ?string $customName = null): void
    {
        try {
            $workshop->loadMissing('items.product');
            $description = $this->buildWorkshopDescription($workshop);

            if (!$workshop->hasMoySkladProcessing()) {
                $result = $this->createProcessingForWorkshop($workshop, $customName, $description ?: null);

                if ($result['success']) {
                    $workshop->markSynced($result['processing_id'], $result['processing_name']);
                    $this->refreshAffectedStocks($workshop);
                } else {
                    $workshop->markSyncError($result['message']);
                    Log::warning('syncWorkshop: не удалось создать техоперацию', [
                        'workshop_id' => $workshop->id,
                        'message'      => $result['message'],
                    ]);
                }
            } else {
                $result = $this->updateProcessingProducts(
                    $workshop->moysklad_processing_id,
                    $workshop,
                    $description ?: null
                );

                if ($result['success']) {
                    $workshop->markSynced($workshop->moysklad_processing_id);
                    $this->refreshAffectedStocks($workshop);
                } else {
                    $workshop->markSyncError($result['message']);
                    Log::warning('syncWorkshop: не удалось обновить техоперацию', [
                        'workshop_id'  => $workshop->id,
                        'processing_id' => $workshop->moysklad_processing_id,
                        'message'       => $result['message'],
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('syncWorkshop: исключение', [
                'workshop_id' => $workshop->id,
                'error'        => $e->getMessage(),
            ]);
            $workshop->markSyncError('Ошибка: ' . $e->getMessage());
        }
    }

    /**
     * Меты складов техоперации: [materialsStore (склад сырья), productsStore (склад продукта)].
     * Для legacy-записей без product_store_id — фолбэк на store_id.
     *
     * @throws \Exception
     */
    private function getStoreMetas(Workshop $workshop): array
    {
        $materialsStoreMeta = $this->getEntityMeta('store', $workshop->store_id ?? '');
        if (!$materialsStoreMeta) {
            throw new \Exception('Не удалось получить данные склада сырья');
        }

        $productStoreId = $workshop->product_store_id ?: $workshop->store_id;
        if ($productStoreId === $workshop->store_id) {
            return [$materialsStoreMeta, $materialsStoreMeta];
        }

        $productsStoreMeta = $this->getEntityMeta('store', $productStoreId);
        if (!$productsStoreMeta) {
            throw new \Exception('Не удалось получить данные склада продукта');
        }

        return [$materialsStoreMeta, $productsStoreMeta];
    }

    /**
     * Сгруппировать строки по moysklad_id товара для позиций техоперации.
     *
     * @param  iterable  $items  строки WorkshopItem одной роли
     * @return array  [moysklad_id => ['quantity' => float, 'assortment' => ['meta' => ...]]]
     */
    private function groupItemsByMoysklad($items): array
    {
        $grouped = [];
        foreach ($items as $item) {
            $product = $item->product;
            if (!$product || !$product->moysklad_id) {
                Log::warning('WorkshopSyncService: товар без moysklad_id', [
                    'product_id' => $product->id ?? null,
                ]);
                continue;
            }
            $moyskladId = $product->moysklad_id;
            if (isset($grouped[$moyskladId])) {
                $grouped[$moyskladId]['quantity'] += (float) $item->quantity;
            } else {
                $meta = $this->getEntityMeta('product', $moyskladId);
                if (!$meta) {
                    Log::warning('WorkshopSyncService: не удалось получить мета товара', [
                        'moysklad_id' => $moyskladId,
                    ]);
                    continue;
                }
                $grouped[$moyskladId] = [
                    'quantity'   => (float) $item->quantity,
                    'assortment' => ['meta' => $meta],
                ];
            }
        }

        return array_filter($grouped, fn($p) => $p['quantity'] > 0);
    }

    /**
     * Объединить группы позиций по moysklad_id, суммируя количества.
     */
    private function mergeGrouped(array ...$groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            foreach ($group as $moyskladId => $position) {
                if (isset($out[$moyskladId])) {
                    $out[$moyskladId]['quantity'] += $position['quantity'];
                } else {
                    $out[$moyskladId] = $position;
                }
            }
        }

        return $out;
    }

    /**
     * processingSum (копейки/ед): ручные затраты (₽/ед × 100) либо авто-fallback
     * по зафиксированной зарплате продукта, поделённой на количество продукта.
     */
    private function computeProcessingSum(Workshop $workshop, float $totalQuantity): int
    {
        if ($workshop->manual_processing_sum !== null) {
            return (int) round((float) $workshop->manual_processing_sum * 100);
        }

        $workerSalaryTotal = 0;
        foreach ($workshop->items->where('role', WorkshopItem::ROLE_PRODUCT) as $item) {
            $workerSalaryTotal += $item->effectiveProdCost() * (float) $item->quantity;
        }

        return $this->calcProcessingSum($workerSalaryTotal, $totalQuantity);
    }

    /**
     * Подтянуть из МойСклад актуальные остатки товаров, затронутых операцией цеха
     * (сырьё, тара, продукт). Вызывается после успешной синхронизации;
     * ошибка подтяжки не роняет поток — логируем.
     */
    private function refreshAffectedStocks(Workshop $workshop): void
    {
        try {
            $workshop->loadMissing('items.product');

            $ids = collect();
            foreach ($workshop->items as $item) {
                $ids->push($item->product?->moysklad_id);
            }

            foreach ($ids->filter()->unique() as $moyskladId) {
                $this->stockSyncService->updateProductStocksByMoyskladId($moyskladId);
            }
        } catch (\Exception $e) {
            Log::warning('WorkshopSyncService::refreshAffectedStocks: не удалось обновить остатки', [
                'workshop_id' => $workshop->id,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    private function buildWorkshopDescription(Workshop $workshop): string
    {
        $header = 'Цех';

        $logs = WorkshopLog::where('workshop_id', $workshop->id)
            ->orderBy('created_at')
            ->with('items.product', 'packer', 'receiver')
            ->get();

        if ($logs->isEmpty()) {
            return $header;
        }

        $packerIds   = $logs->pluck('packer_id')->filter()->unique();
        $packers     = Worker::whereIn('id', $packerIds)->pluck('name', 'id');
        $receiverIds = $logs->pluck('receiver_id')->filter()->unique();
        $receivers   = Worker::whereIn('id', $receiverIds)->pluck('name', 'id');

        $roleLabels = [
            WorkshopItem::ROLE_RAW     => 'Сырьё',
            WorkshopItem::ROLE_PACKAGE => 'Упаковка',
            WorkshopItem::ROLE_PRODUCT => 'Продукт',
        ];

        $blocks = $logs->map(function (WorkshopLog $log) use ($packers, $receivers, $roleLabels) {
            $date         = $log->created_at->format('d.m.Y');
            $packerName   = $packers[$log->packer_id]   ?? '—';
            $receiverName = $receivers[$log->receiver_id] ?? '—';
            $lines        = ["___", "{$date} #{$log->id} работник: {$packerName} / приёмщик: {$receiverName}"];

            foreach ($log->items as $item) {
                $productName = $item->product?->name ?? "Товар #{$item->product_id}";
                $roleLabel   = $roleLabels[$item->role] ?? '';
                $prefix      = $roleLabel ? "[{$roleLabel}] " : '';
                $delta       = (float) $item->quantity_delta;
                $sign        = $delta >= 0 ? '+' : '';
                $lines[]     = "{$prefix}{$productName}: {$sign}" . number_format($delta, 3, '.', '');
            }

            return implode("\n", $lines);
        });

        return trim($header . "\n" . $blocks->join("\n") . "\n___", "\n");
    }

    public function getProcessing(string $processingId)
    {
        if (!$this->hasCredentials()) {
            return null;
        }
        return $this->get('/entity/processing/' . $processingId);
    }
}
