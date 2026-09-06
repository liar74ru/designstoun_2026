<?php

namespace App\Support;

use App\Models\Department;
use App\Models\User;

class OperationAccessor
{
    /**
     * Операции, чьи формы используют AJAX-эндпоинты товаров:
     * дерево групп (ProductPicker), остатки по складам, коэффициенты продукта.
     * Основа составного gate `use-product-api` (см. AppServiceProvider).
     */
    public const PRODUCT_API_OPERATIONS = [
        'products',
        'stone-receptions',
        'workshops',
        'raw-batches',
        'supplier-orders',
    ];

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

        // Работник может состоять в нескольких отделах — доступ есть,
        // если должность разрешена хотя бы в одном из них.
        foreach ($user->worker?->departmentIds() ?? [] as $deptId) {
            if (Department::positionAllowedFor($deptId, $operationKey, $position)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Доступна ли пользователю хотя бы одна операция из списка.
     * Для эндпоинтов, обслуживающих сразу несколько операций реестра.
     *
     * @param string[] $operationKeys
     */
    public static function canSeeAny(?User $user, array $operationKeys): bool
    {
        foreach ($operationKeys as $key) {
            if (self::canSee($user, $key)) {
                return true;
            }
        }

        return false;
    }
}
