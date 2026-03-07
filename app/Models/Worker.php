<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Worker extends Model
{
    use HasFactory;

    const POSITIONS = [
        'Директор',
        'Мастер',
        'Пильщик',
        'Галтовщик',
        'Приемщик',
        'Разнорабочий',
    ];

    protected $fillable = [
        'name',
        'email',
        'phone',
        'position',
        'department_id',
    ];

    /**
     * Учётная запись этого работника в системе
     */
    public function user()
    {
        return $this->hasOne(User::class);
    }

    /**
     * Отдел сотрудника
     */
    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Все приёмки, где этот работник был пильщиком
     */
    public function receptionsAsCutter()
    {
        return $this->hasMany(StoneReception::class, 'cutter_id');
    }

    /**
     * Все приёмки, где этот работник был приёмщиком
     */
    public function receptionsAsReceiver()
    {
        return $this->hasMany(StoneReception::class, 'receiver_id');
    }
}
