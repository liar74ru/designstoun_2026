<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReceptionLogItem extends Model
{
    protected $fillable = [
        'reception_log_id',
        'product_id',
        'quantity_delta',
        'charged_worker_id',
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
     * Мастер, с которого списан минус, если это не приёмщик записи
     */
    public function chargedWorker()
    {
        return $this->belongsTo(Worker::class, 'charged_worker_id');
    }

    /**
     * Кому идёт зарплата мастера за эту строку
     */
    public function masterWorkerId(): ?int
    {
        return $this->charged_worker_id ?? $this->receptionLog?->receiver_id;
    }

    /**
     * Продукт
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
