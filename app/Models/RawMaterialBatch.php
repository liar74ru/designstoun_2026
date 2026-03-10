<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RawMaterialBatch extends Model
{
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
        'initial_quantity' => 'decimal:3',
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

    public function receptions()
    {
        return $this->hasMany(StoneReception::class, 'raw_material_batch_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    public function canBeEdited(): bool
    {
        return $this->status !== 'archived';
    }

    public function canBeArchived(): bool
    {
        return in_array($this->status, ['used', 'returned'])
            && (float) $this->remaining_quantity <= 0;
    }
}
