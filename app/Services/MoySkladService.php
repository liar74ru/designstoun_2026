<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Store;
use Illuminate\Support\Facades\Log;
use Evgeek\Moysklad\MoySklad;
use Evgeek\Moysklad\Api\Record\Objects\Entities\Product as MsProduct;
use Evgeek\Moysklad\Api\Record\Collections\Entities\ProductCollection;

class MoySkladService
{
    private $ms;
    private $token;

    public function __construct()
    {
        $this->token = env('MOYSKLAD_TOKEN');

        if (empty($this->token)) {
            Log::warning('MOYSKLAD_TOKEN не установлен в .env файле');
        }

        try {
            $this->ms = new MoySklad([$this->token]);
        } catch (\Exception $e) {
            Log::error('Ошибка инициализации MoySklad клиента', ['error' => $e->getMessage()]);
            $this->ms = null;
        }
    }

    /**
     * Проверка наличия учетных данных
     */
    public function hasCredentials(): bool
    {
        return !empty($this->token) && $this->ms !== null;
    }

    /**
     * Синхронизация всех групп товаров
     */
    public function syncGroups(): array
    {
        $result = ['success' => false, 'synced' => 0, 'message' => ''];

        if (!$this->hasCredentials()) {
            $result['message'] = 'MoySklad клиент не инициализирован. Проверьте MOYSKLAD_TOKEN';
            return $result;
        }

        try {
            $groups = $this->ms->query()->entity()->productfolder()->get();
            $synced = 0;

            foreach ($groups as $group) {
                try {
                    $parentId = null;
                    if (isset($group->productFolder->meta->href)) {
                        $parentId = basename($group->productFolder->meta->href);
                    }

                    ProductGroup::updateOrCreate(
                        ['moysklad_id' => $group->id],
                        [
                            'name' => $group->name ?? '',
                            'path_name' => $group->pathName ?? '',
                            'code' => $group->code ?? null,
                            'external_code' => $group->externalCode ?? null,
                            'parent_id' => $parentId,
                            'attributes' => json_encode($group),
                        ]
                    );
                    $synced++;
                } catch (\Exception $e) {
                    Log::warning('Ошибка при сохранении группы', [
                        'group_id' => $group->id ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $result['success'] = true;
            $result['synced'] = $synced;
            $result['message'] = "Синхронизировано групп: $synced";

        } catch (\Exception $e) {
            Log::error('Ошибка синхронизации групп', ['error' => $e->getMessage()]);
            $result['message'] = 'Ошибка: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Обработка товара (общий код для синхронизации)
     */
    private function processProduct($item, &$result, $groupsCache): void
    {
        try {
            // Цены
            $price = 0;
            $oldPrice = null;

            if (isset($item->salePrices) && count($item->salePrices) > 0) {
                $price = $item->salePrices[0]->value / 100;
                if (isset($item->salePrices[1])) {
                    $oldPrice = $item->salePrices[1]->value / 100;
                }
            }

            // Артикул
            $sku = $item->article ?? $item->code ?? null;

            // Группа
            $groupId = null;
            $groupName = null;

            if (isset($item->productFolder->meta->href)) {
                $groupId = basename($item->productFolder->meta->href);
                $groupName = $groupsCache[$groupId] ?? null;

                if (!$groupName) {
                    $group = ProductGroup::where('moysklad_id', $groupId)->first();
                    if ($group) {
                        $groupName = $group->name;
                        $groupsCache[$groupId] = $groupName;
                    }
                }
            }

            // Статистика
            $existingProduct = Product::where('moysklad_id', $item->id)->first();

            if ($existingProduct) {
                $result['updated']++;
            } else {
                $result['synced']++;
            }

            // Сохранение
            Product::updateOrCreate(
                ['moysklad_id' => $item->id],
                [
                    'name' => $item->name ?? 'Без названия',
                    'group_id' => $groupId,
                    'group_name' => $groupName,
                    'sku' => $sku,
                    'description' => $item->description ?? null,
                    'price' => $price,
                    'old_price' => $oldPrice,
                    'quantity' => $item->stock ?? 0,
                    'is_active' => true,
                    'attributes' => json_encode([
                        'code' => $item->code ?? null,
                        'article' => $item->article ?? null,
                        'weight' => $item->weight ?? null,
                        'volume' => $item->volume ?? null,
                        'path_name' => $item->pathName ?? null,
                        'updated' => $item->updated ?? null,
                        'external_code' => $item->externalCode ?? null,
                    ]),
                ]
            );

        } catch (\Exception $e) {
            $result['errors']++;
            Log::warning('Ошибка при сохранении товара', [
                'moysklad_id' => $item->id ?? 'unknown',
                'name' => $item->name ?? 'unknown',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Синхронизация всех товаров с использованием библиотеки evgeek/moysklad
     */
    public function syncProducts(): array
    {
        $result = [
            'success' => false,
            'synced' => 0,
            'updated' => 0,
            'errors' => 0,
            'message' => ''
        ];

        if (!$this->hasCredentials()) {
            $result['message'] = 'MoySklad клиент не инициализирован. Проверьте MOYSKLAD_TOKEN';
            return $result;
        }

        try {
            $offset = 0;
            $limit = 100;
            $totalProcessed = 0;
            $groupsCache = ProductGroup::pluck('name', 'moysklad_id')->toArray();

            do {
                $response = $this->ms->query()
                    ->entity()
                    ->product()
                    ->order('updated', 'desc')
                    ->limit($limit)
                    ->offset($offset)
                    ->get();

                $products = $response->rows ?? [];

                if (empty($products)) {
                    break;
                }

                foreach ($products as $item) {
                    $this->processProduct($item, $result, $groupsCache);
                    $totalProcessed++;
                }

                $offset += $limit;

                if (count($products) === $limit) {
                    usleep(500000);
                }

            } while (count($products) === $limit);

            $result['success'] = true;
            $result['message'] = "Синхронизация завершена. Всего обработано: $totalProcessed, добавлено: {$result['synced']}, обновлено: {$result['updated']}";

            if ($result['errors'] > 0) {
                $result['message'] .= ", ошибок: {$result['errors']}";
            }

        } catch (\Exception $e) {
            Log::error('Ошибка синхронизации товаров', ['error' => $e->getMessage()]);
            $result['message'] = 'Ошибка: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Получить товар по ID
     */
    public function fetchProduct($id): ?object
    {
        if (!$this->hasCredentials()) {
            Log::error('Попытка получить товар без учетных данных');
            return null;
        }

        try {
            $product = $this->ms->query()->entity()->product()->byId($id)->get();
            return $product;
        } catch (\Exception $e) {
            Log::error('Ошибка получения товара', ['id' => $id, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Поиск товаров
     */
    public function searchProducts(string $query): ?array
    {
        if (!$this->hasCredentials()) {
            Log::error('Попытка поиска товаров без учетных данных');
            return null;
        }

        try {
            $products = $this->ms->query()
                ->entity()
                ->product()
                ->filter('name', '~', $query)
                ->get();
            return $products->rows ?? [];
        } catch (\Exception $e) {
            Log::error('Ошибка поиска товаров', ['query' => $query, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Синхронизация только измененных товаров
     */
    public function syncUpdatedProducts(\DateTime $fromDate = null): array
    {
        $result = [
            'success' => false,
            'synced' => 0,
            'updated' => 0,
            'errors' => 0,
            'message' => ''
        ];

        if (!$this->hasCredentials()) {
            $result['message'] = 'MoySklad клиент не инициализирован. Проверьте MOYSKLAD_TOKEN';
            return $result;
        }

        try {
            $query = $this->ms->query()->entity()->product()->order('updated', 'desc');

            if ($fromDate) {
                $query = $query->filter('updated', '>', $fromDate->format('Y-m-d H:i:s'));
            }

            $response = $query->get();
            $products = $response->rows ?? [];

            $groupsCache = ProductGroup::pluck('name', 'moysklad_id')->toArray();

            foreach ($products as $item) {
                $this->processProduct($item, $result, $groupsCache);
            }

            $result['success'] = true;
            $result['message'] = "Синхронизация изменений завершена. Добавлено: {$result['synced']}, обновлено: {$result['updated']}";

            if ($result['errors'] > 0) {
                $result['message'] .= ", ошибок: {$result['errors']}";
            }

        } catch (\Exception $e) {
            Log::error('Ошибка синхронизации изменений товаров', ['error' => $e->getMessage()]);
            $result['message'] = 'Ошибка: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Синхронизация всех складов
     */
    public function syncStores(): array
    {
        $result = ['success' => false, 'synced' => 0, 'updated' => 0, 'errors' => 0, 'message' => ''];

        if (!$this->hasCredentials()) {
            $result['message'] = 'MoySklad клиент не инициализирован. Проверьте MOYSKLAD_TOKEN';
            Log::error('Попытка синхронизации без учетных данных');
            return $result;
        }

        try {
            // Получаем ответ от API
            $response = $this->ms->query()->entity()->store()->get();

            // Правильное получение массива складов
            $stores = $response->rows ?? $response ?? [];

            // Если это не массив, попробуем получить правильно
            if (!is_array($stores) && is_object($stores)) {
                // Если это итератор или коллекция
                $stores = iterator_to_array($stores);
            }

            Log::info('Получены склады от МойСклада', [
                'count' => count($stores),
                'response_type' => gettype($response),
                'stores_type' => gettype($stores)
            ]);

            if (empty($stores)) {
                $result['success'] = true;
                $result['message'] = "Склады не найдены. Проверьте доступность данных в МойСклада";
                return $result;
            }

            $synced = 0;
            $updated = 0;

            foreach ($stores as $store) {
                try {
                    // Безопасное получение данных
                    $storeId = $store->id ?? null;
                    if (!$storeId) {
                        Log::warning('Склад без ID пропущен');
                        continue;
                    }

                    $parentId = null;
                    if (isset($store->parent) && isset($store->parent->meta) && isset($store->parent->meta->href)) {
                        $parentId = basename($store->parent->meta->href);
                    }

                    $existing = Store::where('id', $storeId)->first();

                    if ($existing) {
                        $updated++;
                    } else {
                        $synced++;
                    }

                    Store::updateOrCreate(
                        ['id' => $storeId],
                        [
                            'name' => $store->name ?? '',
                            'code' => $store->code ?? null,
                            'external_code' => $store->externalCode ?? null,
                            'description' => $store->description ?? null,
                            'address' => $store->address ?? null,
                            'address_full' => (isset($store->addressFull) && is_array($store->addressFull))
                                ? json_encode($store->addressFull)
                                : null,
                            'archived' => (bool)($store->archived ?? false),
                            'shared' => (bool)($store->shared ?? false),
                            'path_name' => $store->pathName ?? null,
                            'account_id' => $store->accountId ?? null,
                            'owner_id' => (isset($store->owner) && isset($store->owner->meta) && isset($store->owner->meta->href))
                                ? basename($store->owner->meta->href)
                                : null,
                            'parent_id' => $parentId,
                            'attributes' => json_encode([
                                'zones' => $store->zones ?? [],
                                'slots' => $store->slots ?? [],
                                'meta' => $store->meta ?? null,
                            ]),
                        ]
                    );

                    Log::info('Склад сохранен', [
                        'store_id' => $storeId,
                        'name' => $store->name ?? 'unknown'
                    ]);

                } catch (\Exception $e) {
                    $result['errors']++;
                    Log::warning('Ошибка при сохранении склада', [
                        'store_id' => $store->id ?? 'unknown',
                        'name' => $store->name ?? 'unknown',
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            $result['success'] = true;
            $result['synced'] = $synced;
            $result['updated'] = $updated;
            $result['message'] = "Синхронизировано складов: $synced, обновлено: $updated";

            if ($result['errors'] > 0) {
                $result['message'] .= ", ошибок: {$result['errors']}";
            }

        } catch (\Exception $e) {
            Log::error('Ошибка синхронизации складов', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $result['message'] = 'Ошибка: ' . $e->getMessage();
        }

        return $result;
    }

    public function fetchStore($id): ?object
    {
        if (!$this->hasCredentials()) {
            Log::error('Попытка получить склад без учетных данных');
            return null;
        }

        try {
            $store = $this->ms->query()->entity()->store()->byId($id)->get();
            return $store;
        } catch (\Exception $e) {
            Log::error('Ошибка получения склада', ['id' => $id, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Поиск складов
     */
    public function searchStores(string $query): ?array
    {
        if (!$this->hasCredentials()) {
            return null;
        }

        try {
            $stores = $this->ms->query()
                ->entity()
                ->store()
                ->filter('name', '~', $query)
                ->get();
            return $stores->rows ?? [];
        } catch (\Exception $e) {
            Log::error('Ошибка поиска складов', ['query' => $query, 'error' => $e->getMessage()]);
            return null;
        }
    }

    public function syncAll(): array
    {
        $startTime = microtime(true);

        $results = [
            'groups' => $this->syncGroups(),
            'stores' => $this->syncStores(),
            'products' => $this->syncProducts(),
        ];

        $executionTime = round(microtime(true) - $startTime, 2);

        return [
            'success' => $results['groups']['success'] && $results['stores']['success'] && $results['products']['success'],
            'data' => $results,
            'execution_time' => $executionTime . 's',
            'message' => "Синхронизация завершена за {$executionTime}s. Группы: {$results['groups']['message']}, Склады: {$results['stores']['message']}, Товары: {$results['products']['message']}"
        ];
    }
}
