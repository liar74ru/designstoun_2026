<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'moysklad_id',
        'group_id',
        'group_name',
        'name',
        'sku',
        'description',
        'price',
        'old_price',
        'quantity',
        'is_active',
        'attributes',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'old_price' => 'decimal:2',
        'is_active' => 'boolean',
        'attributes' => 'array',
    ];

    /**
     * Получить группу товара
     */
    public function group()
    {
        return $this->belongsTo(ProductGroup::class, 'group_id', 'moysklad_id');
    }

    /**
     * Получить отформатированную цену
     */
    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->price, 2, ',', ' ') . ' ₽';
    }

    /**
     * Проверить наличие на складе
     */
    public function getInStockAttribute(): bool
    {
        return $this->quantity > 0;
    }

    /**
     * Проверить, есть ли скидка
     */
    public function getHasDiscountAttribute(): bool
    {
        return $this->old_price && $this->old_price > $this->price;
    }

    /**
     * Получить размер скидки в процентах
     */
    public function getDiscountPercentAttribute(): ?int
    {
        if (!$this->has_discount) {
            return null;
        }

        return round((($this->old_price - $this->price) / $this->old_price) * 100);
    }
}
