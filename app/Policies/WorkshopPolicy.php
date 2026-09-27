<?php

namespace App\Policies;

use App\Models\Workshop;
use App\Models\User;
use App\Support\DepartmentAccess;

class WorkshopPolicy
{
    /**
     * Менять — только запись своего отдела; смотреть можно любую.
     * Отдел цеха — по цепочке «свой → упаковщик».
     */
    public function modify(User $user, Workshop $record): bool
    {
        return DepartmentAccess::allows($user, $record->effectiveDepartmentId());
    }
}
