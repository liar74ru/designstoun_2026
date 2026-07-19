<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function workshop()
    {
        return $this->belongsTo(Workshop::class);
    }

    public function packer()
    {
        return $this->belongsTo(Worker::class, 'packer_id');
    }

    public function receiver()
    {
        return $this->belongsTo(Worker::class, 'receiver_id');
    }

    public function items()
    {
        return $this->hasMany(WorkshopLogItem::class);
    }
}
