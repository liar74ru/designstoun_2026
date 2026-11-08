<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerHourEntry;
use App\Services\WorkerHourService;

/** Часы ремонтных работ (App\Services\WorkerHourService). */
class WorkerHourEntryPolicy
{
    /** Добавляет часы сам работник — на свой дашборд, и админ — на странице работника. */
    public function create(User $user, Worker $worker): bool
    {
        return $user->isAdmin() || $user->worker_id === $worker->id;
    }

    /**
     * Править и удалять: админ — всегда; сам работник и мастер его отдела — пока неделя
     * записи не выплачена. Мастер — как в WorkerPolicy::viewDashboard: общий отдел.
     */
    public function modify(User $user, WorkerHourEntry $entry): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $worker  = $entry->worker;
        $own     = $user->worker_id === $worker->id;
        $master  = $user->isMaster() && $user->worker
            && array_intersect($worker->departmentIds(), $user->worker->departmentIds()) !== [];

        return ($own || $master) && app(WorkerHourService::class)->isUnpaid($entry);
    }
}
