<?php

namespace App\Policies;

use App\Models\StoneReception;
use App\Models\User;
use App\Support\DepartmentAccess;

class StoneReceptionPolicy
{
    /**
     * Менять — только запись своего отдела; смотреть можно любую.
     * Отдел приёмки — по цепочке «своя → партия → пильщик».
     */
    public function modify(User $user, StoneReception $record): bool
    {
        return DepartmentAccess::allows($user, $record->effectiveDepartmentId());
    }
}
