<?php

namespace Tests\Helpers;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\RawMaterialBatch;
use App\Models\Store;
use App\Models\StoneReception;
use App\Models\User;
use App\Models\Worker;

/**
 * Вспомогательные фабрики для тестов цепочки Партия → Приёмка → МойСклад.
 * Использует только create() — без Factory::class, т.к. фабрик для Worker/Batch нет.
 */
class ReceptionTestHelper
{
    /**
     * Создать авторизованного пользователя-администратора
     */
    public static function adminUser(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /**
     * Создать работника с позицией
     */
    public static function worker(string $name = 'Тестов Тест', string $position = 'Приёмщик'): Worker
    {
        return Worker::create(['name' => $name, 'position' => $position]);
    }

    /**
     * Создать пильщика
     */
    public static function cutter(string $name = 'Пильщиков Пётр'): Worker
    {
        return self::worker($name, 'Пильщик');
    }

    /**
     * Создать склад
     */
    public static function store(string $name = 'Тестовый склад'): Store
    {
        return Store::factory()->create(['name' => $name]);
    }

    /**
     * Создать продукт (готовая продукция)
     */
    public static function product(array $attrs = []): Product
    {
        return Product::factory()->create(array_merge(['name' => 'Тест продукт'], $attrs));
    }

    /**
     * Создать остаток продукта на складе
     */
    public static function stock(Product $product, Store $store, float $qty): ProductStock
    {
        return ProductStock::create([
            'product_id' => $product->id,
            'store_id'   => $store->id,
            'quantity'   => $qty,
        ]);
    }

    /**
     * Создать партию сырья с нужным остатком.
     * По умолчанию статус 'in_work' (бывший 'active').
     * ВАЖНО: не вызывает booted-хуки StoneReception — создаёт напрямую.
     */
    public static function batch(
        Product $product,
        Store   $store,
        Worker  $worker,
        float   $qty = 100.0,
        array   $attrs = []
    ): RawMaterialBatch {
        return RawMaterialBatch::create(array_merge([
            'product_id'         => $product->id,
            'initial_quantity'   => $qty,
            'remaining_quantity' => $qty,
            'current_store_id'   => $store->id,
            'current_worker_id'  => $worker->id,
            'status'             => RawMaterialBatch::STATUS_IN_WORK,
            'batch_number'       => 'TEST-01',
        ], $attrs));
    }

    /**
     * Создать партию со статусом 'new' (ещё без действий)
     */
    public static function newBatch(
        Product $product,
        Store   $store,
        Worker  $worker,
        float   $qty = 100.0,
        array   $attrs = []
    ): RawMaterialBatch {
        return self::batch($product, $store, $worker, $qty, array_merge([
            'status' => RawMaterialBatch::STATUS_NEW,
        ], $attrs));
    }

    /**
     * Создать приёмку напрямую (без booted-хуков) для тестов,
     * которым нужна уже готовая приёмка в БД.
     * raw_quantity_used уже списан из партии вручную.
     */
    public static function reception(
        RawMaterialBatch $batch,
        Worker           $receiver,
        Worker           $cutter,
        Store            $store,
        float            $rawQtyUsed = 5.0,
        array            $attrs = []
    ): StoneReception {
        // Уменьшаем партию вручную (booted не помогает — он вызывает updateStocks дважды в тестах)
        $batch->remaining_quantity = (float) $batch->remaining_quantity - $rawQtyUsed;
        $batch->save();

        return StoneReception::withoutEvents(function () use ($batch, $receiver, $cutter, $store, $rawQtyUsed, $attrs) {
            return StoneReception::create(array_merge([
                'receiver_id'          => $receiver->id,
                'cutter_id'            => $cutter->id,
                'store_id'             => $store->id,
                'raw_material_batch_id'=> $batch->id,
                'raw_quantity_used'    => $rawQtyUsed,
                'status'               => 'active',
            ], $attrs));
        });
    }

    /**
     * Данные формы для POST /stone-receptions
     */
    public static function receptionPostData(
        Worker           $receiver,
        Worker           $cutter,
        Store            $store,
        RawMaterialBatch $batch,
        float            $rawQty = 5.0,
        array            $products = []
    ): array {
        return [
            'receiver_id'           => $receiver->id,
            'cutter_id'             => $cutter->id,
            'store_id'              => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used'     => $rawQty,
            'products'              => $products ?: [
                ['product_id' => Product::factory()->create()->id, 'quantity' => 1.5],
            ],
        ];
    }
}
