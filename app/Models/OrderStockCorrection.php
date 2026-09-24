<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStockCorrection extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'store_id',
        'delta',
        'moysklad_quantity',
        'note',
        'user_id',
    ];

    protected $casts = [
        'delta'             => 'decimal:3',
        'moysklad_quantity' => 'decimal:3',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
