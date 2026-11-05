<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkshopLog extends Model
{
    const TYPE_CREATED = 'created';

    const TYPE_UPDATED = 'updated';

    protected $fillable = [
        'workshop_id',
        'packer_id',
        'receiver_id',
        'type',
        'package_quantity_delta',
        'package_quantity_snapshot',
        'created_at',
    ];

    protected $casts = [
        'package_quantity_delta'    => 'decimal:3',
        'package_quantity_snapshot' => 'decimal:3',
        'created_at'                => 'datetime',
    ];

    /** @return BelongsTo<Workshop, $this> */
    public function workshop(): BelongsTo
    {
        return $this->belongsTo(Workshop::class);
    }

    /** @return BelongsTo<Worker, $this> */
    public function packer(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'packer_id');
    }

    /** @return BelongsTo<Worker, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'receiver_id');
    }

    /** @return HasMany<WorkshopLogItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(WorkshopLogItem::class);
    }
}
