<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /** @return BelongsTo<Worker, $this> */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /** @return BelongsTo<SalaryPayout, $this> */
    public function salaryPayout(): BelongsTo
    {
        return $this->belongsTo(SalaryPayout::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeAdjustments($query)
    {
        return $query->where('type', self::TYPE_ADJUSTMENT);
    }
}
