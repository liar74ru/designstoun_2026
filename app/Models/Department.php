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
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Сотрудники отдела
     */
    public function workers()
    {
        return $this->hasMany(Worker::class);
    }

    /**
     * Руководитель отдела
     */
    public function manager()
    {
        return $this->belongsTo(Worker::class, 'manager_id');
    }
}
