<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Выданная зарплата за неделю (Пт 00:00 – Чт 23:59). Начисленное — снимок на момент выдачи;
 * пересчёт этой недели после выдачи переносится в копилку при следующей (см. accounted).
 */
class SalaryPayout extends Model
{
    protected $fillable = [
        'worker_id',
        'period_from',
        'period_to',
        'accrued',
        'requested_amount',
        'paid_cash',
        'paid_card',
        'paid_tax',
        'paid_total',
        'recalc',
        'accounted',
        'to_bank',
        'paid_at',
        'paid_by_user_id',
        'comment',
        'edited_at',
        'edited_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'period_from'      => 'datetime',
            'period_to'        => 'datetime',
            'paid_at'          => 'datetime',
            'edited_at'        => 'datetime',
            'accrued'          => 'float',
            'requested_amount' => 'float',
            'paid_cash'        => 'float',
            'paid_card'        => 'float',
            'paid_tax'         => 'float',
            'paid_total'       => 'float',
            'recalc'           => 'float',
            'accounted'        => 'float',
            'to_bank'          => 'float',
        ];
    }

    /** @return BelongsTo<Worker, $this> */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /** @return BelongsTo<User, $this> */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }
}
