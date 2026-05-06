<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepartmentOperationSetting extends Model
{
    protected $fillable = [
        'department_id',
        'operation_key',
        'enabled',
        'config',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'config'  => 'array',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
