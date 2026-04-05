<?php

namespace App\Services;

use App\Models\Counterparty;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MoySkladService
{
    private $token;
    private $baseUrl = 'https://api.moysklad.ru/api/remap/1.2';

    public function __construct()
    {
        $this->token = config('services.moysklad.token');
        $this->baseUrl = config('services.moysklad.base_url');

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
     * Выполнить GET запрос к API МойСклад
     */
    private function getRequest(string $endpoint, array $query = [])
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
                'Content-Type' => 'application/json',
            ])->get($this->baseUrl . $endpoint, $query);

            if (!$response->successful()) {
                Log::error('Ошибка API МойСклад', [
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'response' => $response->json()
                ]);
                return null;
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('Исключение при запросе к API МойСклад', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Извлечь ID из href
     */
    private function extractIdFromHref(?string $href): ?string
    {
        if (!$href) {
            return null;
        }

        if (preg_match('/([a-f0-9\-]{36})$/', $href, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Синхронизация всех складов
     */
    public function syncStores(): array
    {
        $result = [
            'success' => false,
            'synced' => 0,
            'updated' => 0,
            'errors' => 0,
            'message' => ''
        ];

        if (!$this->hasCredentials()) {
            $result['message'] = 'MoySklad токен не установлен';
            return $result;
        }

        try {
            Log::info('Начинаем синхронизацию складов');

            $data = $this->getRequest('/entity/store');

            if (!$data || !isset($data['rows'])) {
                $result['message'] = 'Не удалось получить склады из API';
                return $result;
            }

            $stores = $data['rows'];

            foreach ($stores as $storeData) {
                try {
                    $storeId = $storeData['id'] ?? null;

                    if (!$storeId) {
                        continue;
                    }

                    $parentId = null;
                    if (isset($storeData['parent']['meta']['href'])) {
                        $parentId = $this->extractIdFromHref($storeData['parent']['meta']['href']);
                        // Проверяем, что parentId не 'store'
                        if ($parentId === 'store') {
                            $parentId = null;
                        }
                    }

                    $ownerId = null;
                    if (isset($storeData['owner']['meta']['href'])) {
                        $ownerId = $this->extractIdFromHref($storeData['owner']['meta']['href']);
                    }

                    // Проверяем существование склада
                    $existing = Store::where('id', $storeId)->first();

                    if ($existing) {
                        $result['updated']++;
                    } else {
                        $result['synced']++;
                    }

                    Store::updateOrCreate(
                        ['id' => $storeId],
                        [
                            'name' => $storeData['name'] ?? '',
                            'code' => $storeData['code'] ?? null,
                            'external_code' => $storeData['externalCode'] ?? null,
                            'description' => $storeData['description'] ?? null,
                            'address' => $storeData['address'] ?? null,
                            'address_full' => isset($storeData['addressFull']) ? json_encode($storeData['addressFull']) : null,
                            'archived' => (bool)($storeData['archived'] ?? false),
                            'shared' => (bool)($storeData['shared'] ?? false),
                            'path_name' => $storeData['pathName'] ?? null,
                            'account_id' => $storeData['accountId'] ?? null,
                            'owner_id' => $ownerId,
                            'parent_id' => $parentId,
                            'attributes' => json_encode([
                                'zones' => $storeData['zones'] ?? [],
                                'slots' => $storeData['slots'] ?? [],
                                'meta' => $storeData['meta'] ?? null,
                            ]),
                        ]
                    );

                } catch (\Exception $e) {
                    $result['errors']++;
                    Log::warning('Ошибка при сохранении склада', [
                        'store_id' => $storeData['id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $result['success'] = true;
            $result['message'] = "Синхронизировано складов: {$result['synced']}, обновлено: {$result['updated']}";

            if ($result['errors'] > 0) {
                $result['message'] .= ", ошибок: {$result['errors']}";
            }

            Log::info($result['message']);

        } catch (\Exception $e) {
            Log::error('Ошибка синхронизации складов', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $result['message'] = 'Ошибка синхронизации складов: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Синхронизация всех групп товаров
     */
    public function syncGroups(): array
    {
        $result = [
            'success' => false,
            'synced' => 0,
            'updated' => 0,
            'deleted' => 0,
            'errors' => 0,
            'message' => ''
        ];

        if (!$this->hasCredentials()) {
            $result['message'] = 'MoySklad токен не установлен';
            Log::error('Попытка синхронизации групп без токена');
            return $result;
        }

        try {
            Log::info('Начинаем синхронизацию групп товаров');

            $offset = 0;
            $limit = 100;
            $totalProcessed = 0;

            // Собираем все ID групп из МойСклад
            $moyskladGroupIds = [];

            do {
                $data = $this->getRequest('/entity/productfolder', [
                    'limit' => $limit,
                    'offset' => $offset
                ]);

                if (!$data || !isset($data['rows'])) {
                    Log::error('Не удалось получить группы товаров из API');
                    break;
                }

                $groups = $data['rows'];

                foreach ($groups as $groupData) {
                    try {
                        // Сохраняем ID для последующего удаления
                        $moyskladGroupIds[] = $groupData['id'];

                        // Извлекаем parent_id если есть
                        $parentId = null;
                        if (isset($groupData['productFolder']['meta']['href'])) {
                            $parentId = $this->extractIdFromHref($groupData['productFolder']['meta']['href']);
                        }

                        // Проверяем существование группы
                        $existingGroup = ProductGroup::where('moysklad_id', $groupData['id'])->first();

                        if ($existingGroup) {
                            $result['updated']++;
                        } else {
                            $result['synced']++;
                        }

                        // Сохраняем группу
                        ProductGroup::updateOrCreate(
                            ['moysklad_id' => $groupData['id']],
                            [
                                'name' => $groupData['name'] ?? '',
                                'path_name' => $groupData['pathName'] ?? '',
                                'code' => $groupData['code'] ?? null,
                                'external_code' => $groupData['externalCode'] ?? null,
                                'parent_id' => $parentId,
                                'attributes' => json_encode($groupData),
                            ]
                        );

                        $totalProcessed++;

                    } catch (\Exception $e) {
                        $result['errors']++;
                        Log::warning('Ошибка при сохранении группы', [
                            'group_id' => $groupData['id'] ?? 'unknown',
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                $offset += $limit;

                // Небольшая задержка чтобы не превысить лимиты API
                if (count($groups) === $limit) {
                    usleep(500000); // 0.5 секунды
                }

            } while (count($groups) === $limit);

            // Удаляем группы, которых нет в МойСклад
            if (!empty($moyskladGroupIds)) {
                $deletedCount = ProductGroup::whereNotIn('moysklad_id', $moyskladGroupIds)->delete();
                $result['deleted'] = $deletedCount;

                if ($deletedCount > 0) {
                    Log::info('Удалены группы, отсутствующие в МойСклад', ['count' => $deletedCount]);

                    // Также нужно обновить group_id у товаров, которые были в удаленных группах
                    // Можно либо установить group_id = null, либо удалить такие товары
                    Product::whereNotNull('group_id')
                        ->whereNotIn('group_id', $moyskladGroupIds)
                        ->update(['group_id' => null, 'group_name' => null]);

                    Log::info('Обновлены товары с удаленными группами');
                }
            }

            // Очищаем кэш дерева групп
            $this->clearGroupsCache();

            $result['success'] = true;
            $result['message'] = "Синхронизация групп завершена. Всего обработано: $totalProcessed, добавлено: {$result['synced']}, обновлено: {$result['updated']}, удалено: {$result['deleted']}";

            if ($result['errors'] > 0) {
                $result['message'] .= ", ошибок: {$result['errors']}";
            }

            Log::info($result['message']);

        } catch (\Exception $e) {
            Log::error('Ошибка синхронизации групп', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $result['message'] = 'Ошибка синхронизации групп: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Синхронизация всех товаров
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
            $result['message'] = 'MoySklad токен не установлен';
            return $result;
        }

        try {
            Log::info('Начинаем синхронизацию товаров');

            // Получаем кэш групп для быстрого доступа
            $groupsCache = ProductGroup::pluck('name', 'moysklad_id')->toArray();

            $offset = 0;
            $limit = 100;
            $totalProcessed = 0;

            do {
                $data = $this->getRequest('/entity/product', [
                    'limit' => $limit,
                    'offset' => $offset,
                    'order' => 'updated,desc',
                    'expand' => 'attributes',  // запрашиваем кастомные атрибуты
                ]);

                if (!$data || !isset($data['rows'])) {
                    Log::error('Не удалось получить товары из API');
                    break;
                }

                $products = $data['rows'];

                foreach ($products as $productData) {
                    try {
                        $this->processProduct($productData, $result, $groupsCache);
                        $totalProcessed++;
                    } catch (\Exception $e) {
                        $result['errors']++;
                        Log::warning('Ошибка при обработке товара', [
                            'product_id' => $productData['id'] ?? 'unknown',
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                $offset += $limit;

                if (count($products) === $limit) {
                    usleep(500000);
                }

            } while (count($products) === $limit);

            $result['success'] = true;
            $result['message'] = "Синхронизация товаров завершена. Всего обработано: $totalProcessed, добавлено: {$result['synced']}, обновлено: {$result['updated']}";

            if ($result['errors'] > 0) {
                $result['message'] .= ", ошибок: {$result['errors']}";
            }

            Log::info($result['message']);

        } catch (\Exception $e) {
            Log::error('Ошибка синхронизации товаров', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $result['message'] = 'Ошибка синхронизации товаров: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Обработка одного товара
     */
    private function processProduct(array $productData, array &$result, array $groupsCache): void
    {
        // Цены
        $price = 0;
        $oldPrice = null;

        if (isset($productData['salePrices']) && count($productData['salePrices']) > 0) {
            $price = $productData['salePrices'][0]['value'] / 100;

            if (isset($productData['salePrices'][1])) {
                $oldPrice = $productData['salePrices'][1]['value'] / 100;
            }
        }

        // Артикул
        $sku = $productData['article'] ?? $productData['code'] ?? null;

        // Группа
        $groupId = null;
        $groupName = null;

        if (isset($productData['productFolder']['meta']['href'])) {
            $groupId = $this->extractIdFromHref($productData['productFolder']['meta']['href']);
            $groupName = $groupsCache[$groupId] ?? null;

            if (!$groupName && $groupId) {
                $group = ProductGroup::where('moysklad_id', $groupId)->first();
                if ($group) {
                    $groupName = $group->name;
                    $groupsCache[$groupId] = $groupName;
                }
            }
        }

        // Статистика
        $existingProduct = Product::where('moysklad_id', $productData['id'])->first();

        if ($existingProduct) {
            $result['updated']++;
        } else {
            $result['synced']++;
        }

        // Извлекаем коэффициент стоимости производства из кастомных атрибутов МойСклад
        $prodCostCoeff = $this->extractAttribute($productData, 'prodCostCoeff');

        // Сохранение
        Product::updateOrCreate(
            ['moysklad_id' => $productData['id']],
            [
                'name' => $productData['name'] ?? 'Без названия',
                'group_id' => $groupId,
                'group_name' => $groupName,
                'sku' => $sku,
                'description' => $productData['description'] ?? null,
                'price' => $price,
                'old_price' => $oldPrice,
                'prod_cost_coeff' => $prodCostCoeff,
                'quantity' => $productData['stock'] ?? 0,
                'is_active' => true,
                'attributes' => json_encode([
                    'code' => $productData['code'] ?? null,
                    'article' => $productData['article'] ?? null,
                    'weight' => $productData['weight'] ?? null,
                    'volume' => $productData['volume'] ?? null,
                    'path_name' => $productData['pathName'] ?? null,
                    'updated' => $productData['updated'] ?? null,
                    'external_code' => $productData['externalCode'] ?? null,
                ]),
            ]
        );
    }

    /**
     * Публичная обёртка для извлечения кастомного атрибута.
     * Используется в контроллере при обновлении одного товара.
     */
    public function extractAttributePublic(array $productData, string $attributeName): mixed
    {
        return $this->extractAttribute($productData, $attributeName);
    }

    /**
     * Извлечь значение кастомного атрибута по имени.
     *
     * В МойСклад атрибуты приходят как массив объектов:
     * [{"id": "...", "name": "prodCostCoeff", "value": 1.5}, ...]
     */
    private function extractAttribute(array $productData, string $attributeName): mixed
    {
        $attributes = $productData['attributes'] ?? [];
        foreach ($attributes as $attr) {
            if (($attr['name'] ?? '') === $attributeName) {
                return $attr['value'] ?? null;
            }
        }
        return null;
    }

    /**
     * Получить товар по ID
     */
    public function fetchProduct(string $id): ?array
    {
        if (!$this->hasCredentials()) {
            Log::error('Попытка получить товар без токена');
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept-Encoding' => 'gzip',
            ])->get($this->baseUrl . '/entity/product/' . $id, [
                'expand' => 'attributes',  // получаем кастомные атрибуты
            ]);

            if (!$response->successful()) {
                Log::error('Ошибка получения товара', [
                    'id' => $id,
                    'status' => $response->status()
                ]);
                return null;
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('Ошибка получения товара', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Синхронизация контрагентов
     */
    public function syncCounterparties(): array
    {
        $result = [
            'success' => false,
            'synced'  => 0,
            'updated' => 0,
            'errors'  => 0,
            'message' => '',
        ];

        if (!$this->hasCredentials()) {
            $result['message'] = 'MoySklad токен не установлен';
            return $result;
        }

        try {
            Log::info('Начинаем синхронизацию контрагентов');

            $offset = 0;
            $limit  = 1000;

            do {
                $data = $this->getRequest('/entity/counterparty', [
                    'limit'  => $limit,
                    'offset' => $offset,
                ]);

                if (!$data || !isset($data['rows'])) {
                    $result['message'] = 'Не удалось получить контрагентов из API';
                    return $result;
                }

                foreach ($data['rows'] as $row) {
                    $moyskladId = $row['id'] ?? null;
                    if (!$moyskladId) {
                        continue;
                    }

                    try {
                        $existing = Counterparty::where('moysklad_id', $moyskladId)->first();

                        if ($existing) {
                            $result['updated']++;
                        } else {
                            $result['synced']++;
                        }

                        Counterparty::updateOrCreate(
                            ['moysklad_id' => $moyskladId],
                            ['name' => $row['name'] ?? '']
                        );
                    } catch (\Exception $e) {
                        $result['errors']++;
                        Log::warning('Ошибка при сохранении контрагента', [
                            'moysklad_id' => $moyskladId,
                            'error'       => $e->getMessage(),
                        ]);
                    }
                }

                $total  = $data['meta']['size'] ?? 0;
                $offset += $limit;
            } while ($offset < $total);

            $result['success'] = true;
            $result['message'] = "Добавлено: {$result['synced']}, обновлено: {$result['updated']}";

            if ($result['errors'] > 0) {
                $result['message'] .= ", ошибок: {$result['errors']}";
            }

            Log::info('Синхронизация контрагентов завершена: ' . $result['message']);

        } catch (\Exception $e) {
            Log::error('Ошибка синхронизации контрагентов', [
                'error' => $e->getMessage(),
            ]);
            $result['message'] = 'Ошибка синхронизации: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Очистка кэша дерева групп
     */
    private function clearGroupsCache(): void
    {
        try {
            cache()->forget('product_groups_tree');
        } catch (\Exception $e) {
            // Игнорируем ошибки кэша
        }
    }
}
