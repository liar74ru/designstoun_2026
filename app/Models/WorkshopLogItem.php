<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkshopLogItem extends Model
{
    protected $fillable = [
        'workshop_log_id',
        'product_id',
        'role',
        'quantity_delta',
    ];

    protected $casts = [
        'quantity_delta' => 'decimal:3',
    ];

    public function workshopLog()
    {
        return $this->belongsTo(WorkshopLog::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
