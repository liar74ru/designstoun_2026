<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Переопределение глобальной настройки на уровне отдела.
 * Список допустимых ключей — config/department_settings.php.
 * Чтение — только через App\Support\DepartmentSettings.
 */
class DepartmentSetting extends Model
{
    protected $fillable = ['department_id', 'key', 'value'];

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
