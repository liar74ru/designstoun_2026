<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReceptionLog extends Model
{
    const TYPE_CREATED = 'created';

    const TYPE_UPDATED = 'updated';

    protected $fillable = [
        'stone_reception_id',
        'raw_material_batch_id',
        'cutter_id',
        'receiver_id',
        'type',
        'raw_quantity_delta',
        'raw_quantity_snapshot',
        'created_at',
    ];

    protected $casts = [
        'raw_quantity_delta'    => 'decimal:3',
        'raw_quantity_snapshot' => 'decimal:3',
        'created_at'            => 'datetime',
    ];

    /** @return BelongsTo<StoneReception, $this> */
    public function stoneReception(): BelongsTo
    {
        return $this->belongsTo(StoneReception::class);
    }

    /** @return BelongsTo<RawMaterialBatch, $this> */
    public function rawMaterialBatch(): BelongsTo
    {
        return $this->belongsTo(RawMaterialBatch::class);
    }

    /** @return BelongsTo<Worker, $this> */
    public function cutter(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'cutter_id');
    }

    /** @return BelongsTo<Worker, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'receiver_id');
    }

    /** @return HasMany<ReceptionLogItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ReceptionLogItem::class);
    }
}
