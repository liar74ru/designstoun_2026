<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Начисленное выдачи в одном отделе — снимок на момент «Зарплата выдана».
 * Раскладку по отделам (наличные, карта, налог, копилка) считает SalaryPayoutReportService.
 */
class SalaryPayoutDepartment extends Model
{
    protected $fillable = [
        'salary_payout_id',
        'department_id',
        'accrued',
    ];

    protected function casts(): array
    {
        return [
            'accrued' => 'float',
        ];
    }

    /** @return BelongsTo<SalaryPayout, $this> */
    public function payout(): BelongsTo
    {
        return $this->belongsTo(SalaryPayout::class, 'salary_payout_id');
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
