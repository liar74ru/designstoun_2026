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
        'prod_cost_coeff',
        'quantity',
        'is_active',
        'attributes',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'old_price' => 'decimal:2',
        'prod_cost_coeff' => 'decimal:4',
        'is_active' => 'boolean',
        'attributes' => 'array',
    ];

    /**
     * Фиксированная ставка оплаты за единицу (рублей).
     * Вынесена в константу — если ставка изменится, меняем только здесь.
     */
    const PIECE_RATE = 390.0;

    /**
     * Рассчитать стоимость производства для пильщика за указанное количество.
     *
     * Формула: количество * коэффициент * ставка
     * Пример: 5 штук * 1.5 * 390 = 2925 руб
     */
    public function calculateWorkerPay(float $quantity): float
    {
        $coeff = $this->prod_cost_coeff ?? 0;
        return $quantity * (float)$coeff * self::PIECE_RATE;
    }

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
    /**
     * Остатки товара по складам
     */
    public function stocks()
    {
        return $this->hasMany(ProductStock::class, 'product_id');
    }

    /**
     * Общее количество товара на всех складах
     */
    public function getTotalQuantityAttribute()
    {
        return $this->stocks()->sum('quantity');
    }
}
