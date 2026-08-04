<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Строка накладных расходов отдела (₽/м²).
 * Произвольный набор, задаётся админом в карточке отдела; наследования нет.
 * Сумму читать только через App\Support\DepartmentSettings::overheadPerUnit().
 */
class DepartmentExpense extends Model
{
    protected $fillable = ['department_id', 'name', 'amount'];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
