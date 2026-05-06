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
    ];

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
