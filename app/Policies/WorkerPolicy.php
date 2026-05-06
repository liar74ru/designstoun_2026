<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Worker;

class WorkerPolicy
{
    /**
     * Может ли пользователь редактировать пароль/учётку конкретного работника.
     * - Админ: любой работник.
     * - Иначе: только своя запись (где Worker связан с auth()->user()).
     */
    public function editSelf(User $user, Worker $worker): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        return $user->worker_id === $worker->id;
    }

    /**
     * Доступ к дашборду конкретного работника.
     * - Админ: любой.
     * - Сам работник: свой.
     * - Мастер с отделом: работники его отдела.
     */
    public function viewDashboard(User $user, Worker $worker): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if ($user->worker_id === $worker->id) {
            return true;
        }
        if ($user->isMaster() && $user->worker?->department_id) {
            return $worker->department_id === $user->worker->department_id;
        }
        return false;
    }
}
