<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RawMaterialBatch extends Model
{
    // Статусы
    const STATUS_NEW      = 'new';      // Создана, без действий
    const STATUS_IN_WORK  = 'in_work';  // В работе (бывший 'active')
    const STATUS_USED     = 'used';
    const STATUS_RETURNED = 'returned';
    const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'product_id',
        'initial_quantity',
        'remaining_quantity',
        'status',
        'current_store_id',
        'current_worker_id',
        'batch_number',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'initial_quantity'   => 'decimal:3',
        'remaining_quantity' => 'decimal:3',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function currentStore()
    {
        return $this->belongsTo(Store::class, 'current_store_id');
    }

    public function currentWorker()
    {
        return $this->belongsTo(Worker::class, 'current_worker_id');
    }

    public function movements()
    {
        return $this->hasMany(RawMaterialMovement::class, 'batch_id');
    }

    public function latestMovement()
    {
        return $this->hasOne(RawMaterialMovement::class, 'batch_id')->latestOfMany();
    }

    public function receptions()
    {
        return $this->hasMany(StoneReception::class, 'raw_material_batch_id');
    }

    /**
     * Активная приёмка партии (статус 'active').
     * По бизнес-логике у партии одновременно может быть только одна активная приёмка.
     */
    public function getActiveReception(): ?StoneReception
    {
        return $this->receptions()->where('status', StoneReception::STATUS_ACTIVE)->latest()->first();
    }

    // --- Проверки статуса ---

    public function isNew(): bool
    {
        return $this->status === self::STATUS_NEW;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_IN_WORK;
    }

    /**
     * "Рабочая" партия: доступна для производства.
     * Покрывает оба рабочих статуса (new + in_work).
     */
    public function isWorkable(): bool
    {
        return in_array($this->status, [self::STATUS_NEW, self::STATUS_IN_WORK]);
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    public function canBeEdited(): bool
    {
        return $this->status !== self::STATUS_ARCHIVED;
    }

    /**
     * Редактировать (продукт + количество) или удалить можно только партию
     * в статусе 'new' — ни одного производства и ни одного перемещения ещё не было.
     */
    public function canBeEditedOrDeleted(): bool
    {
        return $this->status === self::STATUS_NEW;
    }

    public function canBeArchived(): bool
    {
        return in_array($this->status, [self::STATUS_USED, self::STATUS_RETURNED])
            && (float) $this->remaining_quantity <= 0;
    }

    /**
     * Человекочитаемый лейбл статуса
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_NEW      => 'Новая',
            self::STATUS_IN_WORK  => 'В работе',
            self::STATUS_USED     => 'Израсходована',
            self::STATUS_RETURNED => 'Возвращена',
            self::STATUS_ARCHIVED => 'Архив',
            default               => $this->status,
        };
    }

    /**
     * CSS-класс бейджа для статуса
     */
    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_NEW      => 'bg-info text-dark',
            self::STATUS_IN_WORK  => 'bg-success',
            self::STATUS_USED     => 'bg-warning text-dark',
            self::STATUS_RETURNED => 'bg-secondary',
            self::STATUS_ARCHIVED => 'bg-dark',
            default               => 'bg-secondary',
        };
    }
}
