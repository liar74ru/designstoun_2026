<?php

namespace App\Models;

use App\Support\OperationAccessor;
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

    public function isMaster(): bool
    {
        return $this->worker?->position === 'Мастер';
    }

    public function isWorker(): bool
    {
        return $this->worker?->position === 'Работник';
    }

    /**
     * Маршрут стартовой страницы; null — главная (админ и те, кому дашборд недоступен).
     * Журнал настраивается по отделам: мастер без этой операции идёт на дашборд, а не на 403.
     */
    public function startRouteName(): ?string
    {
        if ($this->isAdmin()) {
            return null;
        }

        if (in_array($this->worker?->position, ['Мастер', 'Помощник мастера'], true)
            && OperationAccessor::canSee($this, 'stone-receptions')) {
            return 'stone-receptions.logs';
        }

        return OperationAccessor::canSee($this, 'worker-dashboard') ? 'worker.dashboard' : null;
    }

    /**
     * Идентификаторы отделов, чьи записи доступны пользователю на index-страницах.
     * - null  — без ограничения (только админ)
     * - []    — не-админ без отдела: ничего не видит
     * - [...] — все отделы не-админа
     *
     * @return int[]|null
     */
    public function accessibleDepartmentIds(): ?array
    {
        if ($this->isAdmin()) {
            return null;
        }

        return $this->worker?->departmentIds() ?? [];
    }

    /**
     * Основной отдел пользователя — дефолт для селектов отдела в формах операций.
     */
    public function primaryDepartmentId(): ?int
    {
        $deptId = $this->worker?->department_id;
        return $deptId ? (int) $deptId : null;
    }
}
