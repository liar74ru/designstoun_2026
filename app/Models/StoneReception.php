<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\RawMaterialBatch;

class StoneReception extends Model
{
    protected $table = 'stone_receptions';

    /**
     * Статусы приемки
     */
    const STATUS_ACTIVE    = 'active';
    const STATUS_COMPLETED = 'completed';
    const STATUS_PROCESSED = 'processed';
    const STATUS_ERROR     = 'error';

    /**
     * Статусы синхронизации с МойСклад
     */
    const SYNC_STATUS_SYNCED     = 'synced';
    const SYNC_STATUS_NOT_SYNCED = 'not_synced';

    protected $fillable = [
        'receiver_id',
        'cutter_id',
        'store_id',
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
     * Пильщик
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

    /**
     * Партия сырья
     */
    public function rawMaterialBatch()
    {
        return $this->belongsTo(RawMaterialBatch::class);
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
     * Отметить как обработанную
     */
    public function markAsProcessed(string $processingId): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSED,
            'moysklad_processing_id' => $processingId,
            'synced_at' => now()
        ]);
    }

    /**
     * Отметить как завершённую (партия израсходована, синхронизации ещё не было)
     */
    public function markAsCompleted(): void
    {
        $this->update(['status' => self::STATUS_COMPLETED]);
    }

    /**
     * Отметить как активную
     */
    public function markAsActive(): void
    {
        $this->update([
            'status' => self::STATUS_ACTIVE,
            'moysklad_processing_id' => null,
            'synced_at' => null
        ]);
    }

    /**
     * Отметить как ошибочную
     */
    public function markAsError(): void
    {
        $this->update([
            'status' => self::STATUS_ERROR,
            'synced_at' => now()
        ]);
    }

    // --- Синхронизация с МойСклад ---

    public function hasMoySkladProcessing(): bool
    {
        return !empty($this->moysklad_processing_id);
    }

    public function hasSyncError(): bool
    {
        return !empty($this->moysklad_sync_error);
    }

    public function isSynced(): bool
    {
        return $this->moysklad_sync_status === self::SYNC_STATUS_SYNCED;
    }

    public function syncStatusLabel(): string
    {
        return $this->isSynced() ? 'Синхр' : 'Не синхр';
    }

    public function syncStatusBadgeClass(): string
    {
        return $this->isSynced() ? 'bg-success' : 'bg-danger';
    }

    public function markSynced(string $processingId, ?string $processingName = null): void
    {
        $this->update([
            'moysklad_processing_id'   => $processingId,
            'moysklad_processing_name' => $processingName ?? $this->moysklad_processing_name,
            'moysklad_sync_status'     => self::SYNC_STATUS_SYNCED,
            'moysklad_sync_error'      => null,
            'synced_at'                => now(),
        ]);
    }

    public function markSyncError(string $error): void
    {
        $this->update([
            'moysklad_sync_status' => self::SYNC_STATUS_NOT_SYNCED,
            'moysklad_sync_error'  => $error,
            'synced_at'            => now(),
        ]);
    }

    /**
     * Получить только активные приемки
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Получить только обработанные приемки
     */
    public function scopeProcessed($query)
    {
        return $query->where('status', self::STATUS_PROCESSED);
    }

    /**
     * Получить последние приемки
     */
    public static function getLastReceptions($limit = 10)
    {
        return self::with([
            'receiver',
            'cutter',
            'store',
            'items.product',
            'rawMaterialBatch.product'
        ])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
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
     * Обновить остатки на складе и в партии сырья
     */
    public function updateStocks()
    {
        DB::transaction(function () {
            // Добавляем готовую продукцию на склад
            foreach ($this->items as $item) {
                $stock = ProductStock::firstOrCreate([
                    'product_id' => $item->product_id,
                    'store_id' => $this->store_id
                ]);

                $stock->quantity += $item->quantity;
                $stock->save();
            }

            // Списываем сырье из партии
            if ($this->rawMaterialBatch) {
                $batch = $this->rawMaterialBatch;

                // ДИАГНОСТИКА: логируем ДО изменения
                Log::info('updateStocks - ДО списания', [
                    'batch_id' => $batch->id,
                    'old_remaining' => $batch->remaining_quantity,
                    'used' => $this->raw_quantity_used,
                    'status_before' => $batch->status
                ]);

                $batch->remaining_quantity -= $this->raw_quantity_used;

                if ($batch->remaining_quantity <= 0) {
                    $batch->remaining_quantity = 0;
                }

                // Первая приёмка переводит партию из 'new' в 'in_work'.
                // Статус 'used' больше НЕ выставляется автоматически — только вручную.
                if ($batch->status === RawMaterialBatch::STATUS_NEW) {
                    $batch->status = RawMaterialBatch::STATUS_IN_WORK;
                }

                $batch->save();

                // ДИАГНОСТИКА: логируем ПОСЛЕ изменения
                Log::info('updateStocks - ПОСЛЕ списания', [
                    'batch_id' => $batch->id,
                    'new_remaining' => $batch->remaining_quantity,
                    'status_after' => $batch->status
                ]);

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
            }
        });
    }

    protected static function booted()
    {
        static::created(function ($reception) {
            $reception->updateStocks();
        });

        static::deleted(function ($reception) {
            // Уменьшаем количество готовой продукции при удалении
            foreach ($reception->items as $item) {
                $stock = ProductStock::where([
                    'product_id' => $item->product_id,
                    'store_id' => $reception->store_id
                ])->first();

                if ($stock) {
                    $stock->quantity -= $item->quantity;
                    $stock->save();
                }
            }

            // Возвращаем сырье обратно в партию
            if ($reception->rawMaterialBatch) {
                $batch = $reception->rawMaterialBatch;
                $batch->remaining_quantity += $reception->raw_quantity_used;
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
