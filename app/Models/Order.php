<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'moysklad_id',
        'name',
        'description',
        'sum',
        'shipped_sum',
        'payed_sum',
        'state',
        'state_name',
        'agent_id',
        'agent_name',
        'organization_id',
        'organization_name',
        'moment',
        'delivery_planned_at',
        'positions',
        'attributes',
        'is_active',
    ];

    protected $casts = [
        'sum' => 'decimal:2',
        'shipped_sum' => 'decimal:2',
        'payed_sum' => 'decimal:2',
        'moment' => 'datetime',
        'delivery_planned_at' => 'datetime',
        'positions' => 'array',
        'attributes' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Получить отформатированную сумму
     */
    public function getFormattedSumAttribute(): string
    {
        return number_format($this->sum, 2, ',', ' ') . ' ₽';
    }

    /**
     * Проверить, полностью ли оплачен заказ
     */
    public function getIsPaidAttribute(): bool
    {
        return $this->payed_sum >= $this->sum;
    }

    /**
     * Проверить, полностью ли отгружен заказ
     */
    public function getIsShippedAttribute(): bool
    {
        return $this->shipped_sum >= $this->sum;
    }

    /**
     * Получить статус на русском
     */
    public function getStatusTextAttribute(): string
    {
        return match($this->state) {
            'shipped' => 'Отгружен',
            'paid' => 'Оплачен',
            'new' => 'Новый',
            'processing' => 'В обработке',
            'completed' => 'Выполнен',
            'canceled' => 'Отменен',
            default => $this->state_name ?? $this->state ?? 'Неизвестно',
        };
    }
}
