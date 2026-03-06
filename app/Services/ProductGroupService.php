<?php

namespace App\Services;

use App\Models\ProductGroup;
use Illuminate\Support\Facades\DB;

class ProductGroupService
{
    /**
     * Получить дерево групп
     */
    public function getGroupsTree()
    {
        // Загружаем сразу с counts
        $allGroups = ProductGroup::withCount('products')
            ->with(['children'])
            ->get();

        $rootGroups = $allGroups->whereNull('parent_id');
        return $this->buildTree($rootGroups, $allGroups);
    }


    /**
     * Построить дерево групп рекурсивно
     */
    private function buildTree($groups, $allGroups)
    {
        $tree = [];

        foreach ($groups as $group) {
            $productsCount = $group->products_count; // Уже загружено
            $children = $allGroups->where('parent_id', $group->moysklad_id);
            $childrenTree = $this->buildTree($children, $allGroups);

            $totalProducts = $productsCount + array_sum(array_column($childrenTree, 'total_products'));

            $tree[] = [
                'id' => $group->moysklad_id,
                'name' => $group->name,
                'path' => $group->full_path,
                'level' => $group->level,
                'children' => $childrenTree,
                'products_count' => $productsCount,
                'total_products' => $totalProducts,
            ];
        }
        usort($tree, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return $tree;
    }

    /**
     * Получить статистику по группам
     */
    public function getStats(): array
    {
        return [
            'total_groups' => ProductGroup::count(),
            'root_groups' => ProductGroup::whereNull('parent_id')->count(),
            'products_in_groups' => ProductGroup::has('products')->count(),
        ];
    }

    /**
     * Получает ID группы и всех её дочерних групп
     *
     * @param string|null $groupId moysklad_id группы
     * @return array
     */
    public function getGroupAndChildrenIds($groupId): array
    {
        if (!$groupId) {
            return [];
        }

        // Используем рекурсивный CTE для PostgreSQL
        $results = DB::select("
            WITH RECURSIVE group_tree AS (
                SELECT moysklad_id, parent_id
                FROM product_groups
                WHERE moysklad_id = ?

                UNION ALL

                SELECT pg.moysklad_id, pg.parent_id
                FROM product_groups pg
                INNER JOIN group_tree gt ON gt.moysklad_id = pg.parent_id
            )
            SELECT moysklad_id FROM group_tree
        ", [$groupId]);

        return collect($results)->pluck('moysklad_id')->toArray();
    }

    /**
     * Альтернативный метод с использованием Eloquent (рекурсивный)
     */
    public function getGroupAndChildrenIdsEloquent($groupId): array
    {
        if (!$groupId) {
            return [];
        }

        $group = ProductGroup::where('moysklad_id', $groupId)->first();

        if (!$group) {
            return [$groupId];
        }

        $ids = [$group->moysklad_id];
        $this->addChildrenIds($group->moysklad_id, $ids);

        return $ids;
    }

    /**
     * Рекурсивно добавляет ID дочерних групп
     */
    private function addChildrenIds($parentMoyskladId, &$ids): void
    {
        $children = ProductGroup::where('parent_id', $parentMoyskladId)->get();

        foreach ($children as $child) {
            $ids[] = $child->moysklad_id;
            $this->addChildrenIds($child->moysklad_id, $ids);
        }
    }

    /**
     * Вычисляет уровень вложенности группы
     */
    private function calculateLevel($group): int
    {
        $level = 0;
        $currentGroup = $group;

        while ($currentGroup->parent_id) {
            $level++;
            $currentGroup = ProductGroup::where('moysklad_id', $currentGroup->parent_id)->first();
            if (!$currentGroup) break;
        }

        return $level;
    }

    /**
     * Получить группу по moysklad_id
     */
    public function find($moyskladId): ?ProductGroup
    {
        return ProductGroup::where('moysklad_id', $moyskladId)->first();
    }
}
