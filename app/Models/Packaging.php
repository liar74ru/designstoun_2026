<?php

namespace App\Models;

use App\Models\Concerns\HasMoyskladSync;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Packaging extends Model
{
    use HasMoyskladSync;

    protected $table = 'packagings';

    const STATUS_ACTIVE    = 'active';
    const STATUS_COMPLETED = 'completed';
    const STATUS_ERROR     = 'error';

    protected $fillable = [
        'packer_id',
        'receiver_id',
        'store_id',
        'package_product_id',
        'package_quantity',
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
        'package_quantity' => 'decimal:3',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
        'synced_at'        => 'datetime',
    ];

    public function packer()
    {
        return $this->belongsTo(Worker::class, 'packer_id');
    }

    public function receiver()
    {
        return $this->belongsTo(Worker::class, 'receiver_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function packageProduct()
    {
        return $this->belongsTo(Product::class, 'package_product_id');
    }

    public function items()
    {
        return $this->hasMany(PackagingItem::class);
    }

    public function packagingLogs()
    {
        return $this->hasMany(PackagingLog::class);
    }

    public function getTotalQuantityAttribute()
    {
        return $this->items->sum('quantity');
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
     * Списать тару со склада упаковщика (ProductStock).
     * Согласовано с пользователем: упакованные продукты остаются на месте,
     * двигается только упаковочная тара.
     */
    public function adjustPackageStock(float $delta): void
    {
        if (!$this->package_product_id || !$this->store_id || abs($delta) < 0.0001) {
            return;
        }

        $stock = ProductStock::firstOrCreate([
            'product_id' => $this->package_product_id,
            'store_id'   => $this->store_id,
        ]);

        // delta > 0 — списываем (отнимаем со склада); delta < 0 — возвращаем.
        $stock->quantity = (float) $stock->quantity - $delta;
        $stock->save();
    }

    protected static function booted()
    {
        static::created(function (Packaging $packaging) {
            DB::transaction(function () use ($packaging) {
                $packaging->adjustPackageStock((float) $packaging->package_quantity);
            });
        });

        static::deleted(function (Packaging $packaging) {
            DB::transaction(function () use ($packaging) {
                $packaging->adjustPackageStock(-1 * (float) $packaging->package_quantity);
            });
        });
    }
}
