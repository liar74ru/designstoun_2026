<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
        'created_at',
    ];

    protected $casts = [
        'raw_quantity_delta' => 'decimal:3',
        'created_at' => 'datetime',
    ];

    public function stoneReception()
    {
        return $this->belongsTo(StoneReception::class);
    }

    public function rawMaterialBatch()
    {
        return $this->belongsTo(RawMaterialBatch::class);
    }

    public function cutter()
    {
        return $this->belongsTo(Worker::class, 'cutter_id');
    }

    public function receiver()
    {
        return $this->belongsTo(Worker::class, 'receiver_id');
    }

    public function items()
    {
        return $this->hasMany(ReceptionLogItem::class);
    }
}
