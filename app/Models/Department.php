<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'manager_id',
        'is_active',
        'default_raw_store_id',
        'default_product_store_id',
        'default_production_store_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function workers()
    {
        return $this->hasMany(Worker::class);
    }

    public function activeWorkers()
    {
        return $this->hasMany(Worker::class)->orderBy('name');
    }

    public function manager()
    {
        return $this->belongsTo(Worker::class, 'manager_id');
    }

    public function defaultRawStore()
    {
        return $this->belongsTo(Store::class, 'default_raw_store_id');
    }

    public function defaultProductStore()
    {
        return $this->belongsTo(Store::class, 'default_product_store_id');
    }

    public function defaultProductionStore()
    {
        return $this->belongsTo(Store::class, 'default_production_store_id');
    }
}
