<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Worker extends Model
{
    use HasFactory;

    const POSITIONS = [
        'Администратор',
        'Мастер',
        'Помощник мастера',
        'Работник',
        'Разнорабочий',
    ];

    /** Позиции, доступные для выбора мастером/приёмщиком/ответственным в документах. */
    const MASTER_POSITIONS = [
        'Администратор',
        'Мастер',
        'Помощник мастера',
    ];

    protected $fillable = [
        'name',
        'email',
        'phone',
        'position',
        'department_id',
        'archived_at',
        'requested_payout',
        'hide_piggy_balance',
    ];

    protected function casts(): array
    {
        return [
            'archived_at'        => 'datetime',
            'requested_payout'   => 'float',
            'hide_piggy_balance' => 'boolean',
        ];
    }

    /**
     * Инвариант: основной отдел всегда присутствует среди отделов работника.
     * Держим pivot в актуальном состоянии при любой записи department_id.
     */
    protected static function booted(): void
    {
        static::saved(function (Worker $worker) {
            if ($worker->department_id && ($worker->wasRecentlyCreated || $worker->wasChanged('department_id'))) {
                $worker->departments()->syncWithoutDetaching([$worker->department_id]);
                $worker->unsetRelation('departments');
            }
        });
    }

    /** Активные (не уволенные) работники */
    public function scopeActive($query)
    {
        return $query->whereNull('archived_at');
    }

    /** Активные работники указанных отделов; null — без ограничения по отделу (админ) */
    public function scopeActiveInDepartments($query, ?array $departmentIds)
    {
        return $query->active()->when($departmentIds !== null, fn ($q) => $q->whereHas('departments', fn ($d) => $d->whereIn('departments.id', $departmentIds ?: [-1])));
    }

    /** Архивные (уволенные) работники */
    public function scopeArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** Перевести работника в архив */
    public function archive(): void
    {
        $this->update(['archived_at' => now()]);
    }

    /** Вернуть работника из архива */
    public function restore(): void
    {
        $this->update(['archived_at' => null]);
    }

    /** @return HasOne<User, $this> */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /**
     * Основной отдел (дефолты форм, наследование department_id в документах)
     *
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Все отделы, в которых задействован работник (включая основной)
     *
     * @return BelongsToMany<Department, $this>
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_worker')->withTimestamps();
    }

    /**
     * Идентификаторы всех отделов работника.
     * Основной отдел добавляется явно — страховка на случай рассинхрона pivot.
     *
     * @return int[]
     */
    public function departmentIds(): array
    {
        $ids = $this->departments->pluck('id')->all();

        if ($this->department_id) {
            $ids[] = $this->department_id;
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    public function belongsToDepartment(int $departmentId): bool
    {
        return in_array($departmentId, $this->departmentIds(), true);
    }

    /** @return HasMany<StoneReception, $this> */
    public function receptionsAsCutter(): HasMany
    {
        return $this->hasMany(StoneReception::class, 'cutter_id');
    }

    /** @return HasMany<StoneReception, $this> */
    public function receptionsAsReceiver(): HasMany
    {
        return $this->hasMany(StoneReception::class, 'receiver_id');
    }

    /**
     * Выдачи зарплаты (App\Services\SalaryService)
     *
     * @return HasMany<SalaryPayout, $this>
     */
    public function salaryPayouts(): HasMany
    {
        return $this->hasMany(SalaryPayout::class);
    }

    /**
     * Журнал копилки: остаток — сумма amount
     *
     * @return HasMany<PiggyBankEntry, $this>
     */
    public function piggyBankEntries(): HasMany
    {
        return $this->hasMany(PiggyBankEntry::class);
    }

    /**
     * Часы ремонтных работ (App\Services\WorkerHourService)
     *
     * @return HasMany<WorkerHourEntry, $this>
     */
    public function hourEntries(): HasMany
    {
        return $this->hasMany(WorkerHourEntry::class);
    }
}
