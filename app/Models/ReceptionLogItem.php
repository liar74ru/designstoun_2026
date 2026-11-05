<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
     *
     * @return BelongsTo<ReceptionLog, $this>
     */
    public function receptionLog(): BelongsTo
    {
        return $this->belongsTo(ReceptionLog::class);
    }

    /**
     * Мастер, с которого списан минус, если это не приёмщик записи
     *
     * @return BelongsTo<Worker, $this>
     */
    public function chargedWorker(): BelongsTo
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
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
