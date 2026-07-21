<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

    protected $fillable = [
        'name',
        'email',
        'phone',
        'position',
        'department_id',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
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

    public function user()
    {
        return $this->hasOne(User::class);
    }

    /** Основной отдел (дефолты форм, наследование department_id в документах) */
    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    /** Все отделы, в которых задействован работник (включая основной) */
    public function departments()
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

    public function receptionsAsCutter()
    {
        return $this->hasMany(StoneReception::class, 'cutter_id');
    }

    public function receptionsAsReceiver()
    {
        return $this->hasMany(StoneReception::class, 'receiver_id');
    }
}
