<?php

namespace App\Policies;

use App\Models\SupplierOrder;
use App\Models\User;
use App\Support\DepartmentAccess;

class SupplierOrderPolicy
{
    /**
     * Менять — только запись своего отдела; смотреть можно любую.
     * Отдел поступления — собственный department_id.
     */
    public function modify(User $user, SupplierOrder $record): bool
    {
        return DepartmentAccess::allows($user, $record->department_id);
    }
}
