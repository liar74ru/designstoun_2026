<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkshopPresetItem extends Model
{
    protected $fillable = [
        'workshop_preset_id',
        'product_id',
        'role',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    /** @return BelongsTo<WorkshopPreset, $this> */
    public function preset(): BelongsTo
    {
        return $this->belongsTo(WorkshopPreset::class, 'workshop_preset_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
