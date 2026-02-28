<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoneReception extends Model
{
    protected $table = 'stone_receptions';

    protected $fillable = [
        'receiver_id',
        'cutter_id',
        'product_id',
        'store_id',
        'quantity',
        'notes',
        'raw_material_batch_id',
        'raw_quantity_used'
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
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
     * Продукт
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Склад
     */
    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Получить последние 10 приемок
     */
    public static function getLastReceptions($limit = 10)
    {
        return self::with(['receiver', 'cutter', 'product', 'store'])
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
        $this->product_id = $other->product_id;
        $this->store_id = $other->store_id;
        $this->quantity = $other->quantity;
        $this->notes = $other->notes;

        return $this;
    }

    protected static function booted()
    {
        static::created(function ($reception) {
            // Найти или создать запись в product_stocks для этого склада
            $stock = ProductStock::firstOrCreate([
                'product_id' => $reception->product_id,
                'store_id' => $reception->store_id
            ]);

            // Увеличить количество
            $stock->quantity += $reception->quantity;
            $stock->save();
        });

        static::updated(function ($reception) {
            // Логика обновления остатка при изменении
            // Сложнее - нужно учитывать разницу
        });

        static::deleted(function ($reception) {
            // Уменьшить количество при удалении
            $stock = ProductStock::where([
                'product_id' => $reception->product_id,
                'store_id' => $reception->store_id
            ])->first();

            if ($stock) {
                $stock->quantity -= $reception->quantity;
                $stock->save();
            }
        });
    }

    public function rawMaterialBatch()
    {
        return $this->belongsTo(RawMaterialBatch::class);
    }
}
