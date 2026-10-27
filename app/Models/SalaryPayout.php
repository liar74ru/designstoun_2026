<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
    ];

    protected function casts(): array
    {
        return [
            'period_from'      => 'datetime',
            'period_to'        => 'datetime',
            'paid_at'          => 'datetime',
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

    public function worker()
    {
        return $this->belongsTo(Worker::class);
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }
}
