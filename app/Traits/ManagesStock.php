<?php

namespace App\Traits;

use App\Models\ProductStock;

trait ManagesStock
{
    /**
     * Изменяет количество товара на складе.
     *
     * @param int $productId ID товара
     * @param string $storeId UUID склада
     * @param float $change Величина изменения (может быть отрицательной)
     * @return void
     */
    protected function adjustStock($productId, $storeId, $change)
    {
        $stock = ProductStock::firstOrCreate([
            'product_id' => $productId,
            'store_id' => $storeId,
        ]);

        $stock->quantity += $change;
        $stock->save();
    }
}
