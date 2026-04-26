<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'worker_id',
        'is_admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_admin'          => 'boolean',
        ];
    }

    /**
     * Связанный работник.
     * Через эту связь получаем должность, данные о выработке и т.д.
     */
    public function worker()
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * Является ли пользователь администратором?
     */
    public function isAdmin(): bool
    {
        return $this->is_admin;
    }

    public function isCutter(): bool
    {
        return $this->worker?->hasPosition('Пильщик') ?? false;
    }

    public function isMaster(): bool
    {
        return $this->worker?->hasPosition('Мастер') ?? false;
    }

    public function isWorker(): bool
    {
        return $this->worker?->hasPosition('Пильщик') || $this->worker?->hasPosition('Галтовщик') ?? false;
    }
}
