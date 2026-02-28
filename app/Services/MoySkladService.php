<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductGroup;
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

        // Инициализация клиента MoySklad с токеном авторизации
        $this->ms = new MoySklad([$this->token]);
    }

    /**
     * Проверка наличия учетных данных
     */
    public function hasCredentials(): bool
    {
        return !empty($this->token);
    }

    /**
     * Синхронизация всех групп товаров
     */
    public function syncGroups(): array
    {
        $result = ['success' => false, 'synced' => 0, 'message' => ''];

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

        try {
            // ==================== НАСТРОЙКИ ПАГИНАЦИИ ====================
            $offset = 0;
            $limit = 100;
            $totalProcessed = 0;

            // Кэш групп для быстрого доступа
            $groupsCache = ProductGroup::pluck('name', 'moysklad_id')->toArray();

            // ==================== ОСНОВНОЙ ЦИКЛ СИНХРОНИЗАЦИИ ====================
            do {
                // ИСПРАВЛЕНО: добавляем query() перед entity()
                $response = $this->ms->query()
                    ->entity()
                    ->product()
                    ->order('updated', 'desc')
                    ->limit($limit)
                    ->offset($offset)
                    ->get();

                // Получаем массив товаров из ответа
                $products = $response->rows ?? [];

                // Если товары закончились, выходим из цикла
                if (empty($products)) {
                    break;
                }

                // ==================== ОБРАБОТКА ТОВАРОВ ====================
                foreach ($products as $item) {
                    try {
                        // ----- ШАГ 1: Извлекаем цены товара -----
                        $price = 0;
                        $oldPrice = null;

                        if (isset($item->salePrices) && count($item->salePrices) > 0) {
                            $price = $item->salePrices[0]->value / 100;

                            if (isset($item->salePrices[1])) {
                                $oldPrice = $item->salePrices[1]->value / 100;
                            }
                        }

                        // ----- ШАГ 2: Определяем артикул товара -----
                        $sku = $item->article ?? $item->code ?? null;

                        // ----- ШАГ 3: Определяем группу товара -----
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

                        // ----- ШАГ 4: Статистика -----
                        $existingProduct = Product::where('moysklad_id', $item->id)->first();

                        if ($existingProduct) {
                            $result['updated']++;
                        } else {
                            $result['synced']++;
                        }

                        // ----- ШАГ 5: Сохраняем товар -----
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

                        $totalProcessed++;

                    } catch (\Exception $e) {
                        $result['errors']++;

                        Log::warning('Ошибка при сохранении товара', [
                            'moysklad_id' => $item->id ?? 'unknown',
                            'name' => $item->name ?? 'unknown',
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                // ----- ШАГ 6: Подготовка к следующей порции -----
                $offset += $limit;

                // Небольшая пауза между запросами
                if (count($products) === $limit) {
                    usleep(500000); // 0.5 секунды
                }

            } while (count($products) === $limit);

            $result['success'] = true;
            $result['message'] = "Синхронизация завершена. Всего обработано: $totalProcessed, добавлено: {$result['synced']}, обновлено: {$result['updated']}";

            if ($result['errors'] > 0) {
                $result['message'] .= ", ошибок: {$result['errors']} (проверьте логи)";
            }

        } catch (\Exception $e) {
            Log::error('Ошибка синхронизации товаров', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $result['message'] = 'Ошибка: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Получить товар по ID
     */
    public function fetchProduct($id): ?object
    {
        try {
            // ИСПРАВЛЕНО: добавляем query() перед entity()
            $product = $this->ms->query()->entity()->product()->byId($id)->get();
            return $product;
        } catch (\Exception $e) {
            Log::error('Ошибка получения товара', ['id' => $id, 'error' => $e->getMessage()]);
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

        try {
            // ИСПРАВЛЕНО: добавляем query() перед entity()
            $query = $this->ms->query()->entity()->product()->order('updated', 'desc');

            if ($fromDate) {
                $query = $query->filter('updated', '>', $fromDate->format('Y-m-d H:i:s'));
            }

            $response = $query->get();
            $products = $response->rows ?? [];

            $groupsCache = ProductGroup::pluck('name', 'moysklad_id')->toArray();

            foreach ($products as $item) {
                try {
                    $price = 0;
                    $oldPrice = null;

                    if (isset($item->salePrices) && count($item->salePrices) > 0) {
                        $price = $item->salePrices[0]->value / 100;

                        if (isset($item->salePrices[1])) {
                            $oldPrice = $item->salePrices[1]->value / 100;
                        }
                    }

                    $sku = $item->article ?? $item->code ?? null;

                    $groupId = null;
                    $groupName = null;

                    if (isset($item->productFolder->meta->href)) {
                        $groupId = basename($item->productFolder->meta->href);
                        $groupName = $groupsCache[$groupId] ?? null;
                    }

                    $existingProduct = Product::where('moysklad_id', $item->id)->first();

                    if ($existingProduct) {
                        $result['updated']++;
                    } else {
                        $result['synced']++;
                    }

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
                        'error' => $e->getMessage()
                    ]);
                }
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
}
