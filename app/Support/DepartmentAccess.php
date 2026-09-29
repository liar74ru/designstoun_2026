<?php

namespace App\Support;

use App\Models\User;

/**
 * Право менять запись по её отделу — единая точка для политик `modify`.
 *
 * Смотреть чужие записи можно (в списках мастер выбирает чужой отдел фильтром),
 * менять — только записи своих отделов (основной + pivot, User::accessibleDepartmentIds()).
 * Отдел записи не определился — ограничивать не по чему, запись доступна (так остались
 * старые записи; новые без отдела не-админ не создаёт — canAssign()).
 */
class DepartmentAccess
{
    public static function allows(User $user, int|string|null $departmentId): bool
    {
        return self::allowsAny($user, $departmentId === null ? [] : [(int) $departmentId]);
    }

    /**
     * Отнести запись к отделу — создать в нём или перенести в него: админ — к любому
     * (и без отдела), остальные — только к своему. Запись без отдела не-админ не создаёт:
     * её потом смог бы менять любой (allowsAny пропускает пустой отдел).
     */
    public static function canAssign(User $user, int|string|null $departmentId): bool
    {
        $accessible = $user->accessibleDepartmentIds();

        if ($accessible === null) {
            return true;
        }

        return $departmentId !== null && $departmentId !== ''
            && in_array((int) $departmentId, $accessible, true);
    }

    /**
     * Запись с несколькими отделами (работник: основной + pivot) — достаточно одного общего.
     *
     * @param  array<int, int>  $departmentIds
     */
    public static function allowsAny(User $user, array $departmentIds): bool
    {
        $accessible = $user->accessibleDepartmentIds();

        if ($accessible === null || $departmentIds === []) {
            return true;
        }

        return array_intersect($departmentIds, $accessible) !== [];
    }
}
