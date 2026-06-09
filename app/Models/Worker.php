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

    public function department()
    {
        return $this->belongsTo(Department::class);
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
