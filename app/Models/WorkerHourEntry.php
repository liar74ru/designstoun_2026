<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Часы ремонтных работ работника (App\Services\WorkerHourService).
 *
 * amount = hours × hourly_rate; ставка — снапшот ставки отдела на момент записи.
 * В начисление недели попадает по work_date (WorkerDashboardService::getDashboardData).
 */
class WorkerHourEntry extends Model
{
    protected $fillable = [
        'worker_id',
        'department_id',
        'work_date',
        'hours',
        'hourly_rate',
        'amount',
        'comment',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'work_date'   => 'date',
            'hours'       => 'float',
            'hourly_rate' => 'float',
            'amount'      => 'float',
        ];
    }

    /** @return BelongsTo<Worker, $this> */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
