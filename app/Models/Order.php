<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'moysklad_id',
        'name',
        'state_moysklad_id',
        'state_name',
        'counterparty_id',
        'agent_name',
        'moment',
        'attributes',
    ];

    protected $casts = [
        'moment'     => 'datetime',
        'attributes' => 'array',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'order_department');
    }

    public const STATE_COLORS = [
        'Проект'              => '#94a3b8',
        'Новый'               => '#94a3b8',
        'Новая'               => '#94a3b8',
        'Изменено'            => '#6b7280',
        'Посчитанно'          => '#6b7280',
        'В процессе'          => '#f97316',
        'Собран'              => '#06b6d4',
        'В процессе отгрузки' => '#ec4899',
        'Отправлен'           => '#2563eb',
        'Под Реализацию'      => '#a855f7',
        'Завершён'            => '#16a34a',
        'Завершена'           => '#16a34a',
        'Сорван'              => '#dc2626',
    ];

    public static function stateColor(?string $name): string
    {
        if (!$name) {
            return '#6c757d';
        }
        return self::STATE_COLORS[$name] ?? '#6c757d';
    }
}
