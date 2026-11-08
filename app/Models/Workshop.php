<?php

namespace App\Models;

use App\Models\Concerns\HasEffectiveDepartment;
use App\Models\Concerns\HasMoyskladSync;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workshop extends Model
{
    use HasEffectiveDepartment;
    use HasMoyskladSync;

    protected $table = 'workshops';

    const STATUS_ACTIVE    = 'active';

    const STATUS_COMPLETED = 'completed';

    const STATUS_ERROR     = 'error';

    protected $fillable = [
        'packer_id',
        'receiver_id',
        'store_id',
        'product_store_id',
        'department_id',
        'manual_processing_sum',
        'notes',
        'status',
        'moysklad_processing_id',
        'moysklad_processing_name',
        'moysklad_sync_status',
        'moysklad_sync_error',
        'synced_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'manual_processing_sum' => 'decimal:2',
        'created_at'            => 'datetime',
        'updated_at'            => 'datetime',
        'synced_at'             => 'datetime',
    ];

    /** @return BelongsTo<Worker, $this> */
    public function packer(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'packer_id');
    }

    /** @return BelongsTo<Worker, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'receiver_id');
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return BelongsTo<Store, $this> */
    public function productStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'product_store_id');
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Цепочка отдела: свой → отдел упаковщика. Закрывает операции без
     * записанного department_id (создание админом, исторические записи).
     */
    public static function effectiveDepartmentChain(): array
    {
        return ['department_id', 'packer.department_id'];
    }

    /** @return HasMany<WorkshopItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(WorkshopItem::class);
    }

    /**
     * Строки сырья (вход).
     *
     * @return HasMany<WorkshopItem, $this>
     */
    public function rawItems(): HasMany
    {
        return $this->hasMany(WorkshopItem::class)->where('role', WorkshopItem::ROLE_RAW);
    }

    /**
     * Строки упаковки/тары.
     *
     * @return HasMany<WorkshopItem, $this>
     */
    public function packageItems(): HasMany
    {
        return $this->hasMany(WorkshopItem::class)->where('role', WorkshopItem::ROLE_PACKAGE);
    }

    /**
     * Строки продукта (выход).
     *
     * @return HasMany<WorkshopItem, $this>
     */
    public function productItems(): HasMany
    {
        return $this->hasMany(WorkshopItem::class)->where('role', WorkshopItem::ROLE_PRODUCT);
    }

    /** @return HasMany<WorkshopLog, $this> */
    public function workshopLogs(): HasMany
    {
        return $this->hasMany(WorkshopLog::class);
    }

    /** Итог по сырью (вход, м²) — для реестра и карточки. */
    public function getTotalQuantityAttribute()
    {
        return $this->items->where('role', WorkshopItem::ROLE_RAW)->sum('quantity');
    }

    /** Итог по продукту (выход) — делитель processingSum. */
    public function getOutputQuantityAttribute()
    {
        return $this->items->where('role', WorkshopItem::ROLE_PRODUCT)->sum('quantity');
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
