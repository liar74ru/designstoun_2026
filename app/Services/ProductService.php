<?php

namespace App\Services;

use App\Models\Product;
use App\Services\Moysklad\MoySkladService;

// рефакторинг v2 от 26.04.2026 — controller → service
class ProductService
{
    public function __construct(private readonly MoySkladService $moySkladService) {}

    public function refreshFromMoysklad(string $moyskladId): array
    {
        $item = $this->moySkladService->fetchProduct($moyskladId);

        if (!$item) {
            return ['success' => false, 'message' => 'Не удалось обновить товар'];
        }

        $price    = 0;
        $oldPrice = null;

        if (!empty($item['salePrices'])) {
            $price = $item['salePrices'][0]['value'] / 100;
            if (isset($item['salePrices'][1])) {
                $oldPrice = $item['salePrices'][1]['value'] / 100;
            }
        }

        $product = Product::updateOrCreate(
            ['moysklad_id' => $item['id']],
            [
                'name'            => $item['name'] ?? '',
                'sku'             => $item['article'] ?? $item['code'] ?? '',
                'description'     => $item['description'] ?? '',
                'price'           => $price,
                'old_price'       => $oldPrice,
                'prod_cost_coeff' => $this->moySkladService->extractAttributePublic($item, 'prodCostCoeff'),
                'attributes'      => json_encode([
                    'code'      => $item['code'] ?? null,
                    'article'   => $item['article'] ?? null,
                    'weight'    => $item['weight'] ?? null,
                    'volume'    => $item['volume'] ?? null,
                    'path_name' => $item['pathName'] ?? null,
                ]),
            ]
        );

        return ['success' => true, 'product' => $product];
    }
}
