<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductStock extends Model
{
    use SoftDeletes;

    protected $table = 'product_stocks';

    protected $fillable = [
        'product_id',
        'store_id',
        'quantity',
        'reserved',
        'available',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'reserved' => 'integer',
        'available' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Получить товар
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    /**
     * Получить склад
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id', 'id');
    }

    /**
     * Обновить доступное количество
     */
    public function updateAvailable(): void
    {
        $this->available = max(0, $this->quantity - $this->reserved);
        $this->save();
    }

    /**
     * Увеличить остаток (поступление)
     */
    public function addQuantity(int $amount, ?string $notes = null): void
    {
        $this->quantity += $amount;
        if ($notes) {
            $this->notes = ($this->notes ? $this->notes . "\n" : '') . $notes;
        }
        $this->updateAvailable();
    }

    /**
     * Уменьшить остаток (отпуск)
     */
    public function removeQuantity(int $amount, ?string $notes = null): void
    {
        $this->quantity = max(0, $this->quantity - $amount);
        if ($notes) {
            $this->notes = ($this->notes ? $this->notes . "\n" : '') . $notes;
        }
        $this->updateAvailable();
    }

    /**
     * Зарезервировать товар
     */
    public function reserve(int $amount): void
    {
        $this->reserved += $amount;
        $this->updateAvailable();
    }

    /**
     * Отменить резервирование
     */
    public function unreserve(int $amount): void
    {
        $this->reserved = max(0, $this->reserved - $amount);
        $this->updateAvailable();
    }
}
