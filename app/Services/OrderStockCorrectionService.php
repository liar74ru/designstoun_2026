<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderStockCorrection;
use App\Models\ProductStock;
use App\Models\User;

class OrderStockCorrectionService
{
    /**
     * Уточнить фактический остаток товара на складе в рамках заявки.
     *
     * Хранится поправка к остатку МойСклад, а не само число: тогда дальнейшие движения
     * по товару (приёмка, цех, отгрузка) переносятся на уточнённое значение сами.
     * Дельта всегда считается от сырого product_stocks.quantity, иначе повторное
     * уточнение той же позиции задвоит поправку.
     *
     * Факт, совпавший с остатком МойСклад, снимает поправку.
     */
    public function set(
        Order $order,
        int $productId,
        string $storeId,
        float $fact,
        ?string $note,
        ?User $user,
    ): ?OrderStockCorrection {
        $base  = (float) ($this->baseQuantity($productId, $storeId));
        $delta = round($fact - $base, 3);

        $keys = [
            'order_id'   => $order->id,
            'product_id' => $productId,
            'store_id'   => $storeId,
        ];

        if ($delta === 0.0) {
            OrderStockCorrection::where($keys)->delete();

            return null;
        }

        return OrderStockCorrection::updateOrCreate($keys, [
            'delta'             => $delta,
            'moysklad_quantity' => $base,
            'note'              => $note,
            'user_id'           => $user?->id,
        ]);
    }

    public function reset(Order $order, int $productId, string $storeId): void
    {
        OrderStockCorrection::where([
            'order_id'   => $order->id,
            'product_id' => $productId,
            'store_id'   => $storeId,
        ])->delete();
    }

    /**
     * Остаток из кэша МойСклад — база, от которой считается поправка.
     */
    private function baseQuantity(int $productId, string $storeId): float
    {
        return (float) ProductStock::query()
            ->where('product_id', $productId)
            ->where('store_id', $storeId)
            ->value('quantity');
    }
}
