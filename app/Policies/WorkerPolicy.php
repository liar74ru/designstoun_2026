<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Worker;
use App\Support\DepartmentAccess;

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

    /**
     * Сумма к выдаче из копилки: сам работник или админ. Мастер чужие деньги не видит.
     */
    public function manageSalary(User $user, Worker $worker): bool
    {
        return $user->isAdmin() || $user->worker_id === $worker->id;
    }

    /**
     * Менять карточку работника (правка, архив, учётная запись) — работника своих отделов.
     * У работника несколько отделов (основной + pivot): достаточно одного общего.
     */
    public function modify(User $user, Worker $worker): bool
    {
        return DepartmentAccess::allowsAny($user, $worker->departmentIds());
    }
}
