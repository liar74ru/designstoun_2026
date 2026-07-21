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
     * - Мастер с отделами: работники любого из его отделов.
     */
    public function viewDashboard(User $user, Worker $worker): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if ($user->worker_id === $worker->id) {
            return true;
        }
        if ($user->isMaster() && $user->worker) {
            return array_intersect($worker->departmentIds(), $user->worker->departmentIds()) !== [];
        }
        return false;
    }
}
