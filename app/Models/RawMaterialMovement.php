<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawMaterialMovement extends Model
{
    protected $fillable = [
        'batch_id',
        'from_store_id',
        'to_store_id',
        'from_worker_id',
        'to_worker_id',
        'moved_by',
        'movement_type',
        'quantity',
        'moysklad_move_id',
        'moysklad_synced',
        'merged_into_move',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'quantity'         => 'decimal:3',
        'merged_into_move' => 'boolean',
    ];

    /** @return BelongsTo<RawMaterialBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(RawMaterialBatch::class, 'batch_id');
    }

    /** @return BelongsTo<Store, $this> */
    public function fromStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'from_store_id');
    }

    /** @return BelongsTo<Store, $this> */
    public function toStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'to_store_id');
    }

    /** @return BelongsTo<Worker, $this> */
    public function fromWorker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'from_worker_id');
    }

    /** @return BelongsTo<Worker, $this> */
    public function toWorker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'to_worker_id');
    }

    /** @return BelongsTo<Worker, $this> */
    public function movedBy(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'moved_by');
    }
}
