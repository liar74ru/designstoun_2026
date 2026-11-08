<?php

namespace App\Models;

use App\Models\Concerns\HasEffectiveDepartment;
use App\Models\Concerns\HasMoyskladSync;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoneReception extends Model
{
    use HasEffectiveDepartment;
    use HasMoyskladSync;

    protected $table = 'stone_receptions';

    /**
     * Статусы приемки
     */
    const STATUS_ACTIVE    = 'active';

    const STATUS_COMPLETED = 'completed';

    const STATUS_PROCESSED = 'processed';

    const STATUS_ERROR     = 'error';

    protected $fillable = [
        'receiver_id',
        'cutter_id',
        'store_id',
        'department_id',
        'raw_material_batch_id',
        'raw_quantity_used',
        'notes',
        'moysklad_processing_id',
        'moysklad_processing_name',
        'moysklad_sync_status',
        'moysklad_sync_error',
        'status',
        'synced_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'raw_quantity_used' => 'decimal:3',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    /**
     * Приемщик
     *
     * @return BelongsTo<Worker, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'receiver_id');
    }

    /**
     * Работник
     *
     * @return BelongsTo<Worker, $this>
     */
    public function cutter(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'cutter_id');
    }

    /**
     * Склад
     *
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Партия сырья
     *
     * @return BelongsTo<RawMaterialBatch, $this>
     */
    public function rawMaterialBatch(): BelongsTo
    {
        return $this->belongsTo(RawMaterialBatch::class);
    }

    /**
     * Цепочка отдела: свой → отдел партии → отдел пильщика.
     * Используется для расчёта себестоимости и для видимости документа по отделу.
     *
     * Накладные расходы живут в `department_expenses` и не наследуются
     * из глобальных настроек, поэтому у документа без отдела они были бы
     * нулевыми. Цепочка закрывает исторические документы, у которых
     * department_id остался пустым.
     */
    public static function effectiveDepartmentChain(): array
    {
        return ['department_id', 'rawMaterialBatch.department_id', 'cutter.department_id'];
    }

    /**
     * Журнал изменений приёмки
     *
     * @return HasMany<ReceptionLog, $this>
     */
    public function receptionLogs(): HasMany
    {
        return $this->hasMany(ReceptionLog::class);
    }

    /**
     * Позиции приемки (продукты)
     *
     * @return HasMany<StoneReceptionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StoneReceptionItem::class);
    }

    /**
     * Получить общее количество продукции
     */
    public function getTotalQuantityAttribute()
    {
        return $this->items->sum('quantity');
    }

    /**
     * Получить список продуктов через позиции
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'stone_reception_items')
            ->withPivot('quantity')
            ->withTimestamps();
    }
}
