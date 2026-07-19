<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function preset()
    {
        return $this->belongsTo(WorkshopPreset::class, 'workshop_preset_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
