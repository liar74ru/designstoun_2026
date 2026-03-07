<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReceptionLogItem extends Model
{
    protected $fillable = [
        'reception_log_id',
        'product_id',
        'quantity_delta',
    ];

    protected $casts = [
        'quantity_delta' => 'decimal:3',
    ];

    /**
     * Запись лога
     */
    public function receptionLog()
    {
        return $this->belongsTo(ReceptionLog::class);
    }

    /**
     * Продукт
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
