<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkshopItem extends Model
{
    protected $table = 'workshop_items';

    const ROLE_RAW     = 'raw';      // сырьё (вход)
    const ROLE_PACKAGE = 'package';  // упаковка/тара
    const ROLE_PRODUCT = 'product';  // продукт (выход)

    protected $fillable = [
        'workshop_id',
        'product_id',
        'role',
        'quantity',
        'effective_cost_coeff',
        'is_undercut',
        'is_edging',
        'is_small_tile',
        'worker_cost_per_m2',
        'master_cost_per_m2',
    ];

    protected $casts = [
        'quantity'             => 'decimal:3',
        'effective_cost_coeff' => 'decimal:4',
        'is_undercut'          => 'boolean',
        'is_edging'            => 'boolean',
        'is_small_tile'        => 'boolean',
        'worker_cost_per_m2'   => 'decimal:2',
        'master_cost_per_m2'   => 'decimal:2',
    ];

    public function workshop()
    {
        return $this->belongsTo(Workshop::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeRaw($query)
    {
        return $query->where('role', self::ROLE_RAW);
    }

    public function scopePackage($query)
    {
        return $query->where('role', self::ROLE_PACKAGE);
    }

    public function scopeProduct($query)
    {
        return $query->where('role', self::ROLE_PRODUCT);
    }

    public function getBaseCoeffAttribute(): float
    {
        if ($this->is_edging) {
            return (float) ($this->product?->prod_cost_coeff ?? 0);
        }
        $eff = (float) $this->effective_cost_coeff;
        return $this->is_undercut ? $eff + (float) Setting::get('UNDERCUT_PENALTY', 1.5) : $eff;
    }

    /**
     * Зарплата работника за единицу продукции, зафиксированная при создании позиции.
     * Фолбэк на prodCost по коэффициенту — как в приёмке (StoneReceptionItem).
     */
    public function effectiveProdCost(): float
    {
        return $this->worker_cost_per_m2 !== null
            ? (float) $this->worker_cost_per_m2
            : $this->product->prodCost((float) $this->effective_cost_coeff);
    }

    public function calculateWorkerPay(): float
    {
        return (float) $this->quantity * $this->effectiveProdCost();
    }

    public function calculateMasterPay(): float
    {
        return (float) $this->quantity * (float) ($this->master_cost_per_m2 ?? 0);
    }
}
