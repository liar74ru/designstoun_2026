<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierOrder extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'supplier_orders';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    const STATUS_NEW   = 'new';

    const STATUS_SENT  = 'sent';

    const STATUS_ERROR = 'error';

    protected $fillable = [
        'id',
        'moysklad_id',
        'supply_moysklad_id',
        'number',
        'store_id',
        'counterparty_id',
        'receiver_id',
        'created_by_user_id',
        'department_id',
        'status',
        'note',
        'sync_error',
        'created_at',
        'updated_at',
    ];

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return BelongsTo<Counterparty, $this> */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    /** @return BelongsTo<Worker, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'receiver_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return HasMany<SupplierOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SupplierOrderItem::class);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_NEW   => 'Новый',
            self::STATUS_SENT  => 'Синхронизирована',
            self::STATUS_ERROR => 'Ошибка',
            default            => $this->status,
        };
    }

    /**
     * Можно редактировать/удалять пока не создана приёмка в МойСклад.
     * Оба статуса — new и error — означают что приёмки ещё нет.
     */
    public function isNew(): bool
    {
        return in_array($this->status, [self::STATUS_NEW, self::STATUS_ERROR]);
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_NEW   => 'bg-success',
            self::STATUS_SENT  => 'bg-primary',
            self::STATUS_ERROR => 'bg-danger',
            default            => 'bg-secondary',
        };
    }
}
