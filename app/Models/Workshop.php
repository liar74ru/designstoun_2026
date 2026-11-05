<?php

namespace App\Models;

use App\Models\Concerns\HasEffectiveDepartment;
use App\Models\Concerns\HasMoyskladSync;
use App\Traits\ManagesStock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Workshop extends Model
{
    use HasEffectiveDepartment;
    use HasMoyskladSync;
    use ManagesStock;

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

    // ─── Статус ──────────────────────────────────────────────────────────────

    public function markAsCompleted(): void
    {
        $this->update(['status' => self::STATUS_COMPLETED]);
    }

    public function markAsActive(): void
    {
        $this->update([
            'status'                 => self::STATUS_ACTIVE,
            'moysklad_processing_id' => null,
            'synced_at'              => null,
        ]);
    }

    public function markAsError(): void
    {
        $this->update([
            'status'    => self::STATUS_ERROR,
            'synced_at' => now(),
        ]);
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Скорректировать складской остаток одного товара тары (ProductStock).
     * Двигается только упаковочная тара (role=package); сырьё и продукт — нет.
     *
     * delta > 0 — списываем (отнимаем со склада); delta < 0 — возвращаем.
     */
    public function adjustPackageStock(int $productId, float $delta, ?string $storeId = null): void
    {
        $storeId ??= $this->store_id;

        if (!$productId || !$storeId || abs($delta) < 0.0001) {
            return;
        }

        $this->adjustStock($productId, $storeId, -$delta);
    }

    /**
     * Списать/вернуть тару всех package-строк со склада.
     * $sign = +1 списать, -1 вернуть.
     */
    public function adjustAllPackageStock(int $sign = 1, ?string $storeId = null): void
    {
        foreach ($this->packageItems()->get() as $item) {
            $this->adjustPackageStock($item->product_id, $sign * (float) $item->quantity, $storeId);
        }
    }

    protected static function booted()
    {
        // Списание тары на создании выполняется в WorkshopService::create()
        // после вставки package-строк (событие created срабатывает до них).
        static::deleting(function (Workshop $workshop) {
            DB::transaction(function () use ($workshop) {
                $workshop->adjustAllPackageStock(-1);
            });
        });
    }
}
