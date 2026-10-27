<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Проводка копилки работника: остаток — сумма amount. */
class PiggyBankEntry extends Model
{
    public const TYPE_PAYOUT     = 'payout';
    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'worker_id',
        'amount',
        'type',
        'salary_payout_id',
        'comment',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
        ];
    }

    public function worker()
    {
        return $this->belongsTo(Worker::class);
    }

    public function salaryPayout()
    {
        return $this->belongsTo(SalaryPayout::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeAdjustments($query)
    {
        return $query->where('type', self::TYPE_ADJUSTMENT);
    }
}
