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
        'archived',
    ];

    protected $casts = [
        'color'      => 'integer',
        'position'   => 'integer',
        'is_enabled' => 'boolean',
        'archived'   => 'boolean',
    ];

    /** Статусы, отмеченные админом как используемые. */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /** Цвет плашки; у статуса без цвета — нейтральный серый. */
    public function getHexColorAttribute(): string
    {
        return BadgeColor::fromMoysklad($this->color) ?? BadgeColor::FALLBACK;
    }
}
