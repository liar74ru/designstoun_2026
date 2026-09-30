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
use Illuminate\Support\Str;

/**
 * Заказ в производство: заявка покупателя из МойСклад (kind = customer) или внутренний
 * заказ одного отдела другому (kind = internal, создан в программе). Ссылки строятся по uuid:
 * у заявки покупателя он равен moysklad_id, у внутреннего заказа moysklad_id пуст.
 */
class Order extends Model
{
    public const KIND_CUSTOMER = 'customer';
    public const KIND_INTERNAL = 'internal';

    protected $fillable = [
        'uuid',
        'kind',
        'customer_department_id',
        'parent_uuid',
        'parent_order_name',
        'created_by_user_id',
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

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->uuid ??= $order->moysklad_id ?? (string) Str::uuid();
        });
    }

    public function isInternal(): bool
    {
        return $this->kind === self::KIND_INTERNAL;
    }

    public function scopeCustomer(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_CUSTOMER);
    }

    public function scopeInternal(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_INTERNAL);
    }

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

    /** Отделы-исполнители. У внутреннего заказа — кому заказан полуфабрикат. */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'order_department');
    }

    /** Отдел-заказчик внутреннего заказа */
    public function customerDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'customer_department_id');
    }

    /** Заявка-основание внутреннего заказа; null — её уже нет среди активных. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'parent_uuid', 'uuid');
    }

    /** Внутренние заказы, размещённые под эту заявку */
    public function internalChildren(): HasMany
    {
        return $this->hasMany(Order::class, 'parent_uuid', 'uuid');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Кому делаем: контрагент заявки или отдел-заказчик внутреннего заказа. */
    public function getClientLabelAttribute(): ?string
    {
        if ($this->isInternal()) {
            return $this->customerDepartment?->name;
        }

        return $this->counterparty?->name ?? $this->agent_name;
    }

    /**
     * Очередь заявок: срочные сверху, дальше по ключу приоритета (App\Support\OrderPriority).
     * Этот же порядок делит изготовленное между заявками с общим товаром.
     */
    public function scopePrioritized(Builder $query): Builder
    {
        return $query->orderByDesc('is_urgent')->orderBy('priority_key')->orderBy('id');
    }

    /**
     * Порядок списка заявок: очередь prioritized(), но заявки в статусах «в конец списка»
     * (OrderState::is_list_bottom) — после всех остальных, даже срочные. Только для показа:
     * раздача остатка между заявками идёт по prioritized().
     */
    public function scopeListOrdered(Builder $query): Builder
    {
        $bottom = OrderState::listBottomIds();

        if ($bottom !== []) {
            $placeholders = implode(', ', array_fill(0, count($bottom), '?'));
            $query->orderByRaw("CASE WHEN state_moysklad_id IN ({$placeholders}) THEN 1 ELSE 0 END", $bottom);
        }

        return $query->prioritized();
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
