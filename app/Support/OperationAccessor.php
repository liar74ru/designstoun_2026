<?php

namespace App\Support;

use App\Models\Department;
use App\Models\User;

class OperationAccessor
{
    /**
     * Может ли пользователь видеть/использовать операцию реестра
     * (одна точка истины для UI шапки и middleware can:see-{key}).
     */
    public static function canSee(?User $user, string $operationKey): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        $op = config("department_operations.{$operationKey}");
        if (! $op || ! empty($op['admin_only'])) {
            return false;
        }

        $position = $user->worker?->position;
        if (! $position) {
            return false;
        }

        if (in_array($position, $op['positions_always_visible'] ?? [], true)) {
            return true;
        }

        if (! in_array($position, $op['configurable_positions'] ?? [], true)) {
            return false;
        }

        $deptId = $user->worker?->department_id;
        if (! $deptId) {
            return false;
        }

        return Department::positionAllowedFor((int) $deptId, $operationKey, $position);
    }
}
