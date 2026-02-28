<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Worker extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'position',
        'department_id'
    ];

    /**
     * Отдел сотрудника
     */
    public function department()
    {
        return $this->belongsTo(Department::class);
    }
}
