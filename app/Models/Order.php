<?php

namespace App\Models;

use App\Casts\PreciseFloat;
use App\Support\BadgeColor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

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
        'delivery_planned_at',
        'is_urgent',
        'priority_key',
        'priority_manual',
        'production_started_at',
        'production_ended_at',
        'positions_changed_at',
        'position_changes',
        'state_before_change',
        'attributes',
        'sync_hash',
    ];

    protected $casts = [
        'moment'                => 'datetime',
        'delivery_planned_at'   => 'datetime',
        'is_urgent'             => 'boolean',
        'priority_key'          => PreciseFloat::class,
        'priority_manual'       => 'boolean',
        'production_started_at' => 'datetime',
        'production_ended_at'   => 'datetime',
        'positions_changed_at'  => 'datetime',
        'position_changes'      => 'array',
        'attributes'            => 'array',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    /** Настройки позиций: склады комплектации, снимок остатка, поправки мастера */
    public function positionSettings(): HasMany
    {
        return $this->hasMany(OrderPositionSetting::class);
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'order_department');
    }

    /**
     * Очередь заявок: срочные сверху, дальше по ключу приоритета (App\Support\OrderPriority).
     * Этот же порядок делит изготовленное между заявками с общим товаром.
     */
    public function scopePrioritized(Builder $query): Builder
    {
        return $query->orderByDesc('is_urgent')->orderBy('priority_key')->orderBy('id');
    }

    private const STATE_CACHE_KEY = 'order_states.colors';

    /**
     * Цвет плашки статуса — из справочника OrderState (приходит из МойСклад).
     * Ищем по id статуса, для исторических заявок без него — по имени.
     */
    public function getStateColorAttribute(): string
    {
        $maps = self::stateColorMaps();

        return $maps['byId'][$this->state_moysklad_id ?? '']
            ?? $maps['byName'][$this->state_name ?? '']
            ?? BadgeColor::FALLBACK;
    }

    /** Цвет текста, читаемый на плашке статуса. */
    public function getStateTextColorAttribute(): string
    {
        return BadgeColor::textFor($this->state_color);
    }

    /**
     * Карты «id → цвет» и «имя → цвет». Справочник меняется только при
     * синхронизации, поэтому держим в кэше.
     *
     * @return array{byId: array<string, string>, byName: array<string, string>}
     */
    private static function stateColorMaps(): array
    {
        return Cache::rememberForever(self::STATE_CACHE_KEY, function () {
            $byId = [];
            $byName = [];

            foreach (OrderState::all() as $state) {
                $byId[$state->id] = $state->hex_color;
                $byName[$state->name] = $state->hex_color;
            }

            return ['byId' => $byId, 'byName' => $byName];
        });
    }

    public static function forgetStateCache(): void
    {
        Cache::forget(self::STATE_CACHE_KEY);
    }
}
