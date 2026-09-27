<?php

namespace App\Policies;

use App\Models\RawMaterialBatch;
use App\Models\User;
use App\Support\DepartmentAccess;

class RawMaterialBatchPolicy
{
    /**
     * Менять — только запись своего отдела; смотреть можно любую.
     * Отдел партии — собственный department_id.
     */
    public function modify(User $user, RawMaterialBatch $record): bool
    {
        return DepartmentAccess::allows($user, $record->department_id);
    }
}
