<?php

namespace App\Models;

use App\Models\Concerns\HasEffectiveDepartment;
use App\Models\Concerns\HasMoyskladSync;
use App\Support\BatchStock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Models\RawMaterialBatch;

class StoneReception extends Model
{
    use HasMoyskladSync;
    use HasEffectiveDepartment;

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
     */
    public function receiver()
    {
        return $this->belongsTo(Worker::class, 'receiver_id');
    }

    /**
     * Работник
     */
    public function cutter()
    {
        return $this->belongsTo(Worker::class, 'cutter_id');
    }

    /**
     * Склад
     */
    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Партия сырья
     */
    public function rawMaterialBatch()
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
     */
    public function receptionLogs()
    {
        return $this->hasMany(\App\Models\ReceptionLog::class);
    }

    /**
     * Позиции приемки (продукты)
     */
    public function items()
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
     */
    public function products()
    {
        return $this->belongsToMany(Product::class, 'stone_reception_items')
            ->withPivot('quantity')
            ->withTimestamps();
    }

    /**
     * Отметить как завершённую (партия израсходована, синхронизации ещё не было)
     */
    public function markAsCompleted(): void
    {
        $this->update(['status' => self::STATUS_COMPLETED]);
    }

    /**
     * Скопировать данные из другой приемки
     */
    public function copyFrom(StoneReception $other)
    {
        $this->receiver_id = $other->receiver_id;
        $this->cutter_id = $other->cutter_id;
        $this->store_id = $other->store_id;
        $this->raw_material_batch_id = $other->raw_material_batch_id;
        $this->raw_quantity_used = $other->raw_quantity_used;
        $this->notes = $other->notes;

        return $this;
    }

    /**
     * Списать сырьё из партии и зафиксировать движение.
     *
     * Остатки готовой продукции в product_stocks НЕ трогаем — они являются
     * зеркалом МойСклад и подтягиваются после успешной техоперации
     * (StoneReceptionSyncService::refreshAffectedStocks).
     */
    public function updateStocks()
    {
        DB::transaction(function () {
            // Списываем сырье из партии — от свежего остатка под блокировкой строки
            $batch = BatchStock::lockById($this->raw_material_batch_id);
            if (!$batch) {
                return;
            }

            BatchStock::ensureAvailable($batch, (float) $this->raw_quantity_used);

            $batch->remaining_quantity = max(0, (float) $batch->remaining_quantity - (float) $this->raw_quantity_used);
            BatchStock::syncStatus($batch);
            $batch->save();
            $this->setRelation('rawMaterialBatch', $batch);

            // Создаем запись о списании сырья
            RawMaterialMovement::create([
                'batch_id' => $batch->id,
                'from_store_id' => $this->store_id,
                'to_store_id' => null,
                'from_worker_id' => $this->cutter_id,
                'to_worker_id' => null,
                'moved_by' => $this->receiver_id,
                'movement_type' => 'use',
                'quantity' => $this->raw_quantity_used,
            ]);
        });
    }

    protected static function booted()
    {
        static::created(function ($reception) {
            $reception->updateStocks();
        });

        static::deleted(function ($reception) {
            // Остатки готовой продукции в product_stocks локально не откатываем:
            // техоперацию в МойСклад удаляет StoneReceptionService::delete(), после
            // чего актуальные остатки подтягиваются из МойСклад
            // (StoneReceptionSyncService::deleteProcessingForReception).

            // Возвращаем сырье обратно в партию
            $batch = BatchStock::lockById($reception->raw_material_batch_id);
            if ($batch) {
                $batch->remaining_quantity = (float) $batch->remaining_quantity + (float) $reception->raw_quantity_used;
                // Статус партии при удалении приёмки НЕ меняется автоматически.
                // Управление статусом — только вручную.
                $batch->save();

                // Создаем запись о возврате сырья
                RawMaterialMovement::create([
                    'batch_id' => $batch->id,
                    'from_store_id' => null,
                    'to_store_id' => $reception->store_id,
                    'from_worker_id' => null,
                    'to_worker_id' => $reception->cutter_id,
                    'moved_by' => $reception->receiver_id,
                    'movement_type' => 'return_to_store',
                    'quantity' => $reception->raw_quantity_used,
                ]);
            }
        });
    }
}
