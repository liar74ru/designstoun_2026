<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackagingItem extends Model
{
    protected $table = 'packaging_items';

    protected $fillable = [
        'packaging_id',
        'product_id',
        'quantity',
        'effective_cost_coeff',
        'is_undercut',
        'is_small_tile',
        'worker_cost_per_m2',
        'master_cost_per_m2',
    ];

    protected $casts = [
        'quantity'             => 'decimal:3',
        'effective_cost_coeff' => 'decimal:4',
        'is_undercut'          => 'boolean',
        'is_small_tile'        => 'boolean',
        'worker_cost_per_m2'   => 'decimal:2',
        'master_cost_per_m2'   => 'decimal:2',
    ];

    public function packaging()
    {
        return $this->belongsTo(Packaging::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function getBaseCoeffAttribute(): float
    {
        $eff = (float) $this->effective_cost_coeff;
        return $this->is_undercut ? $eff + (float) Setting::get('UNDERCUT_PENALTY', 1.5) : $eff;
    }

    /**
     * Зарплата упаковщика за единицу продукции, зафиксированная при создании позиции.
     */
    public function effectiveProdCost(): float
    {
        return (float) $this->worker_cost_per_m2;
    }

    /**
     * Зарплата упаковщика за 1 м² = PACKAGING_PROD_COST × коэф продукта (04-XX)
     *                             + PACKAGING_COST      × коэф тары    (07-03-XX).
     */
    public static function computePackerCost(?float $productCoeff, ?float $packageCoeff): float
    {
        return (float) Setting::get('PACKAGING_PROD_COST', 0) * (float) ($productCoeff ?? 0)
             + (float) Setting::get('PACKAGING_COST', 0)      * (float) ($packageCoeff ?? 0);
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
