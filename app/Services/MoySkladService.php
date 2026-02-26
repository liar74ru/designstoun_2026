<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MoySkladService
{
    private $login;
    private $password;
    private $baseUrl = 'https://api.moysklad.ru/api/remap/1.2';

    public function __construct()
    {
        $this->login = env('MOYSKLAD_LOGIN');
        $this->password = env('MOYSKLAD_PASSWORD');
    }

    /**
     * Проверка наличия учетных данных
     */
    public function hasCredentials(): bool
    {
        return !empty($this->login) && !empty($this->password);
    }

    /**
     * Выполнить GET запрос к API
     */
    private function get($endpoint, $params = [])
    {
        return Http::withBasicAuth($this->login, $this->password)
            ->withHeaders(['Accept-Encoding' => 'gzip'])
            ->get($this->baseUrl . $endpoint, $params);
    }

    /**
     * Синхронизация всех групп товаров
     */
    public function syncGroups(): array
    {
        $result = ['success' => false, 'synced' => 0, 'message' => ''];

        try {
            $response = $this->get('/entity/productfolder', ['limit' => 1000]);

            if (!$response->successful()) {
                $result['message'] = 'Ошибка загрузки групп: ' . $response->status();
                return $result;
            }

            $data = $response->json();
            $groups = $data['rows'] ?? [];
            $synced = 0;

            foreach ($groups as $group) {
                try {
                    $parentId = null;
                    if (isset($group['productFolder']['meta']['href'])) {
                        $parentId = basename($group['productFolder']['meta']['href']);
                    }

                    ProductGroup::updateOrCreate(
                        ['moysklad_id' => $group['id']],
                        [
                            'name' => $group['name'] ?? '',
                            'path_name' => $group['pathName'] ?? '',
                            'code' => $group['code'] ?? null,
                            'external_code' => $group['externalCode'] ?? null,
                            'parent_id' => $parentId,
                            'attributes' => json_encode($group),
                        ]
                    );
                    $synced++;
                } catch (\Exception $e) {
                    Log::warning('Ошибка при сохранении группы', [
                        'group_id' => $group['id'] ?? 'unknown',
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

        try {
            $response = $this->get('/entity/product', [
                'limit' => 1000,
                'order' => 'updated,desc',
            ]);

            if (!$response->successful()) {
                $result['message'] = 'Ошибка загрузки товаров: ' . $response->status();
                return $result;
            }

            $data = $response->json();
            $products = $data['rows'] ?? [];

            foreach ($products as $item) {
                try {
                    $price = 0;
                    $oldPrice = null;

                    if (isset($item['salePrices']) && count($item['salePrices']) > 0) {
                        $price = $item['salePrices'][0]['value'] / 100;

                        if (isset($item['salePrices'][1])) {
                            $oldPrice = $item['salePrices'][1]['value'] / 100;
                        }
                    }

                    $sku = $item['article'] ?? $item['code'] ?? null;

                    // Определяем группу товара
                    $groupId = null;
                    $groupName = null;
                    if (isset($item['productFolder']['meta']['href'])) {
                        $groupId = basename($item['productFolder']['meta']['href']);
                        $group = ProductGroup::where('moysklad_id', $groupId)->first();
                        if ($group) {
                            $groupName = $group->name;
                        }
                    }

                    $existingProduct = Product::where('moysklad_id', $item['id'])->first();

                    if ($existingProduct) {
                        $result['updated']++;
                    } else {
                        $result['synced']++;
                    }

                    Product::updateOrCreate(
                        ['moysklad_id' => $item['id']],
                        [
                            'name' => $item['name'] ?? 'Без названия',
                            'group_id' => $groupId,
                            'group_name' => $groupName,
                            'sku' => $sku,
                            'description' => $item['description'] ?? null,
                            'price' => $price,
                            'old_price' => $oldPrice,
                            'quantity' => $item['stock'] ?? 0,
                            'is_active' => true,
                            'attributes' => json_encode([
                                'code' => $item['code'] ?? null,
                                'article' => $item['article'] ?? null,
                                'weight' => $item['weight'] ?? null,
                                'volume' => $item['volume'] ?? null,
                                'path_name' => $item['pathName'] ?? null,
                                'updated' => $item['updated'] ?? null,
                            ]),
                        ]
                    );

                } catch (\Exception $e) {
                    $result['errors']++;
                    Log::warning('Ошибка при сохранении товара', [
                        'moysklad_id' => $item['id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $result['success'] = true;
            $result['message'] = "Синхронизация завершена. Добавлено: {$result['synced']}, обновлено: {$result['updated']}";

            if ($result['errors'] > 0) {
                $result['message'] .= ", ошибок: {$result['errors']} (проверьте логи)";
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
    public function fetchProduct($id): ?array
    {
        try {
            $response = $this->get("/entity/product/{$id}");

            if (!$response->successful()) {
                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('Ошибка получения товара', ['id' => $id, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
