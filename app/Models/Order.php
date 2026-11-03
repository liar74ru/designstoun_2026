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
        // final_state_id — финальный статус, поставленный отделом «для себя»
        // (OrderDepartmentStateService).
        return $this->belongsToMany(Department::class, 'order_department')
            ->withPivot('final_state_id', 'finalized_at');
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
     *
     * @param  array<int, int>|null  $viewerDepartmentIds  отделы смотрящего (null — админ)
     */
    public function scopeListOrdered(Builder $query, ?array $viewerDepartmentIds = null): Builder
    {
        $bottom = self::listBottomSql($viewerDepartmentIds);

        if ($bottom !== null) {
            $query->orderByRaw("CASE WHEN {$bottom[0]} THEN 1 ELSE 0 END", $bottom[1]);
        }

        return $query->prioritized();
    }

    /**
     * Заявки «в конце списка» (true) или остальные (false) — та же граница, что у listOrdered().
     *
     * @param  array<int, int>|null  $viewerDepartmentIds
     */
    public function scopeInListBottom(Builder $query, bool $bottom, ?array $viewerDepartmentIds = null): Builder
    {
        $sql = self::listBottomSql($viewerDepartmentIds);

        if ($sql === null) {
            return $bottom ? $query->whereRaw('1 = 0') : $query;
        }

        // CASE, а не NOT (...): у заявки без статуса IN даёт NULL, и NOT её бы потерял.
        return $query->whereRaw("CASE WHEN {$sql[0]} THEN 1 ELSE 0 END = " . ($bottom ? 1 : 0), $sql[1]);
    }

    /**
     * Условие «заявка в конце списка»: общий статус с флагом «Вниз» либо все отделы смотрящего
     * на этой заявке поставили себе такой финальный статус (OrderDepartmentStateService) —
     * для них работа закончена, хотя другие отделы ещё делают свою часть.
     * Сравнение только со связанными значениями: final_state_id — строка, id статуса — uuid.
     *
     * @param  array<int, int>|null  $viewerDepartmentIds
     * @return array{0: string, 1: array<int, mixed>}|null  null — статусов «Вниз» нет
     */
    private static function listBottomSql(?array $viewerDepartmentIds): ?array
    {
        $bottom = OrderState::listBottomIds();

        if ($bottom === []) {
            return null;
        }

        $states = implode(', ', array_fill(0, count($bottom), '?'));
        $sql    = "orders.state_moysklad_id IN ({$states})";
        $params = $bottom;

        if ($viewerDepartmentIds) {
            $depts = implode(', ', array_fill(0, count($viewerDepartmentIds), '?'));
            $sql  .= " OR (EXISTS (SELECT 1 FROM order_department od WHERE od.order_id = orders.id"
                . " AND od.department_id IN ({$depts}) AND od.final_state_id IN ({$states}))"
                . " AND NOT EXISTS (SELECT 1 FROM order_department od WHERE od.order_id = orders.id"
                . " AND od.department_id IN ({$depts}) AND od.final_state_id IS NULL))";
            $params = array_merge($params, $viewerDepartmentIds, $bottom, $viewerDepartmentIds);
        }

        return ["({$sql})", $params];
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
