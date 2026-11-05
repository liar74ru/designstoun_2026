<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /** @return BelongsTo<WorkshopLog, $this> */
    public function workshopLog(): BelongsTo
    {
        return $this->belongsTo(WorkshopLog::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
