<?php

namespace App\Models;

use App\Support\BadgeColor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Статус заказа покупателя из МойСклад. Справочник-зеркало: имя, цвет и порядок
 * приходят из метаданных, локально хранится только флаг «используется».
 */
class OrderState extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'color',
        'state_type',
        'position',
        'is_enabled',
        'is_production',
        'archived',
    ];

    protected $casts = [
        'color'         => 'integer',
        'position'      => 'integer',
        'is_enabled'    => 'boolean',
        'is_production' => 'boolean',
        'archived'      => 'boolean',
    ];

    /**
     * Карта цветов у Order кэшируется — сбрасываем её при любой правке статуса.
     * Массовые update() событий не поднимают, там кэш чистится явно
     * (OrderStateSyncService::sync, OrderStateController::update).
     */
    protected static function booted(): void
    {
        static::saved(fn () => Order::forgetStateCache());
        static::deleted(fn () => Order::forgetStateCache());
    }

    /** Статусы, отмеченные админом как используемые. */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /** Статусы, в которых заявка считается производящейся. */
    public function scopeProduction(Builder $query): Builder
    {
        return $query->where('is_production', true);
    }

    /** Цвет плашки; у статуса без цвета — нейтральный серый. */
    public function getHexColorAttribute(): string
    {
        return BadgeColor::fromMoysklad($this->color) ?? BadgeColor::FALLBACK;
    }
}
