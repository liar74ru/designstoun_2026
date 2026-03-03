<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StoneReception extends Model
{
    protected $table = 'stone_receptions';

    protected $fillable = [
        'receiver_id',
        'cutter_id',
        'store_id',
        'raw_material_batch_id',
        'raw_quantity_used',
        'notes'
    ];

    protected $casts = [
        'raw_quantity_used' => 'decimal:3',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Приемщик
     */
    public function receiver()
    {
        return $this->belongsTo(Worker::class, 'receiver_id');
    }

    /**
     * Пильщик
     */
    public function cutter()
    {
        return $this->belongsTo(Worker::class, 'cutter_id');
    }

    /**
     * Склад
     */
    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Партия сырья
     */
    public function rawMaterialBatch()
    {
        return $this->belongsTo(RawMaterialBatch::class);
    }

    /**
     * Позиции приемки (продукты)
     */
    public function items()
    {
        return $this->hasMany(StoneReceptionItem::class);
    }

    /**
     * Получить общее количество продукции
     */
    public function getTotalQuantityAttribute()
    {
        return $this->items->sum('quantity');
    }

    /**
     * Получить список продуктов через позиции
     */
    public function products()
    {
        return $this->belongsToMany(Product::class, 'stone_reception_items')
            ->withPivot('quantity')
            ->withTimestamps();
    }

    /**
     * Получить последние приемки
     */
    public static function getLastReceptions($limit = 10)
    {
        return self::with([
            'receiver',
            'cutter',
            'store',
            'items.product',
            'rawMaterialBatch.product'
        ])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Скопировать данные из другой приемки
     */
    public function copyFrom(StoneReception $other)
    {
        $this->receiver_id = $other->receiver_id;
        $this->cutter_id = $other->cutter_id;
        $this->store_id = $other->store_id;
        $this->raw_material_batch_id = $other->raw_material_batch_id;
        $this->raw_quantity_used = $other->raw_quantity_used;
        $this->notes = $other->notes;

        return $this;
    }

    /**
     * Обновить остатки на складе и в партии сырья
     */
    public function updateStocks()
    {
        DB::transaction(function () {
            // Добавляем готовую продукцию на склад
            foreach ($this->items as $item) {
                $stock = ProductStock::firstOrCreate([
                    'product_id' => $item->product_id,
                    'store_id' => $this->store_id
                ]);

                $stock->quantity += $item->quantity;
                $stock->save();
            }

            // Списываем сырье из партии
            if ($this->rawMaterialBatch) {
                $batch = $this->rawMaterialBatch;
                $batch->remaining_quantity -= $this->raw_quantity_used;

                // Если сырье закончилось, меняем статус партии
                if ($batch->remaining_quantity <= 0) {
                    $batch->status = 'used';
                    $batch->remaining_quantity = 0;
                }
                $batch->save();

                // Создаем запись о списании сырья
                RawMaterialMovement::create([
                    'batch_id' => $batch->id,
                    'from_store_id' => $this->store_id,
                    'to_store_id' => null,
                    'from_worker_id' => $this->cutter_id,
                    'to_worker_id' => null,
                    'moved_by' => $this->receiver_id,
                    'movement_type' => 'use',
                    'quantity' => $this->raw_quantity_used,
                ]);
            }
        });
    }

    protected static function booted()
    {
        static::created(function ($reception) {
            $reception->updateStocks();
        });

        static::deleted(function ($reception) {
            // Уменьшаем количество готовой продукции при удалении
            foreach ($reception->items as $item) {
                $stock = ProductStock::where([
                    'product_id' => $item->product_id,
                    'store_id' => $reception->store_id
                ])->first();

                if ($stock) {
                    $stock->quantity -= $item->quantity;
                    $stock->save();
                }
            }

            // Возвращаем сырье обратно в партию
            if ($reception->rawMaterialBatch) {
                $batch = $reception->rawMaterialBatch;
                $batch->remaining_quantity += $reception->raw_quantity_used;

                if ($batch->remaining_quantity > 0) {
                    $batch->status = 'active';
                }
                $batch->save();

                // Создаем запись о возврате сырья
                RawMaterialMovement::create([
                    'batch_id' => $batch->id,
                    'from_store_id' => null,
                    'to_store_id' => $reception->store_id,
                    'from_worker_id' => null,
                    'to_worker_id' => $reception->cutter_id,
                    'moved_by' => $reception->receiver_id,
                    'movement_type' => 'return_to_store',
                    'quantity' => $reception->raw_quantity_used,
                ]);
            }
        });
    }
}
