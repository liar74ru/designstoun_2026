<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    public function batch()
    {
        return $this->belongsTo(RawMaterialBatch::class, 'batch_id');
    }

    public function fromStore()
    {
        return $this->belongsTo(Store::class, 'from_store_id');
    }

    public function toStore()
    {
        return $this->belongsTo(Store::class, 'to_store_id');
    }

    public function fromWorker()
    {
        return $this->belongsTo(Worker::class, 'from_worker_id');
    }

    public function toWorker()
    {
        return $this->belongsTo(Worker::class, 'to_worker_id');
    }

    public function movedBy()
    {
        return $this->belongsTo(Worker::class, 'moved_by');
    }
}
