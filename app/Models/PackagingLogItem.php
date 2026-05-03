<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackagingLogItem extends Model
{
    protected $fillable = [
        'packaging_log_id',
        'product_id',
        'quantity_delta',
    ];

    protected $casts = [
        'quantity_delta' => 'decimal:3',
    ];

    public function packagingLog()
    {
        return $this->belongsTo(PackagingLog::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
