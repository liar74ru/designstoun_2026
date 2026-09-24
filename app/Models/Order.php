<?php

namespace App\Models;

use App\Support\BadgeColor;
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

    /** Поправки к остаткам, уточнённые мастером в рамках этой заявки */
    public function stockCorrections(): HasMany
    {
        return $this->hasMany(OrderStockCorrection::class);
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'order_department');
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
