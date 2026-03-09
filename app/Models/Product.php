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
        'is_active',
        'attributes',
    ];

    protected $casts = [
        'price'           => 'decimal:2',
        'old_price'       => 'decimal:2',
        'prod_cost_coeff' => 'decimal:4',
        'is_active'       => 'boolean',
        'attributes'      => 'array',
    ];

    const PIECE_RATE = 390.0;

    // ─── Связи ───────────────────────────────────────────────────────────────

    public function group()
    {
        return $this->belongsTo(ProductGroup::class, 'group_id', 'moysklad_id');
    }

    /**
     * Остатки по складам. Единственный источник истины для количества.
     */
    public function stocks()
    {
        return $this->hasMany(ProductStock::class, 'product_id');
    }

    // ─── Аксессоры ───────────────────────────────────────────────────────────

    /**
     * Суммарный остаток по всем складам.
     * Используй ->withSum('stocks', 'quantity') для оптимизации в списках.
     */
    public function getTotalQuantityAttribute(): float
    {
        // Если уже загружена агрегация через withSum — берём её
        if (array_key_exists('stocks_sum_quantity', $this->attributes)) {
            return (float) $this->attributes['stocks_sum_quantity'];
        }
        // Иначе считаем из загруженной коллекции или делаем запрос
        return (float) $this->stocks->sum('quantity');
    }

    public function getInStockAttribute(): bool
    {
        return $this->total_quantity > 0;
    }

    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->price, 2, ',', ' ') . ' ₽';
    }

    public function getHasDiscountAttribute(): bool
    {
        return $this->old_price && $this->old_price > $this->price;
    }

    public function getDiscountPercentAttribute(): ?int
    {
        if (!$this->has_discount) return null;
        return round((($this->old_price - $this->price) / $this->old_price) * 100);
    }

    // ─── Бизнес-логика ───────────────────────────────────────────────────────

    public function prodCost()
    {
//        if ((float)($this->prod_cost_coeff ?? 0) === 0.0) {
//            return 0.0;
//        }

        // ОКРУГЛВНИЗ((PIECE_RATE + PIECE_RATE*17% * coeff) / 10; 0) * 10
        $coeff   = (float)$this->prod_cost_coeff ?? 0;
        $perUnit = self::PIECE_RATE + (self::PIECE_RATE * 0.17) * $coeff;
        $rounded = floor($perUnit / 10) * 10;

        return $rounded;
    }
    public function calculateWorkerPay(float $quantity): float
    {
        return $quantity * $this->prodCost();
    }
}
