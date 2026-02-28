<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductStock extends Model
{
    use SoftDeletes;

    protected $table = 'product_stocks';

    protected $fillable = [
        'product_id',
        'store_id',
        'quantity',
        'reserved',
        'in_transit',
        'available',
        'notes'
    ];

    protected $casts = [
        'quantity' => 'float',
        'reserved' => 'float',
        'in_transit' => 'float',
        'available' => 'float',
    ];

    /**
     * Связь с товаром
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Связь со складом
     */
    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * Автоматический расчет доступного количества
     */
    protected static function booted()
    {
        static::saving(function ($stock) {
            $stock->available = $stock->quantity - $stock->reserved;
        });
    }
}
