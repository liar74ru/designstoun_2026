<?php

namespace App\Services;

use App\Models\ProductGroup;

class ProductGroupService
{
    /**
     * Получить дерево групп
     */
    public function getGroupsTree()
    {
        $allGroups = ProductGroup::with(['products', 'children'])->get();
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
            $productsCount = $group->products()->count();
            $children = $allGroups->where('parent_id', $group->moysklad_id);
            $childrenTree = $this->buildTree($children, $allGroups);

            $totalProducts = $productsCount;
            foreach ($childrenTree as $child) {
                $totalProducts += $child['total_products'];
            }

            $item = [
                'id' => $group->moysklad_id,
                'name' => $group->name,
                'path' => $group->full_path,
                'level' => $group->level,
                'children' => $childrenTree,
                'products_count' => $productsCount,
                'total_products' => $totalProducts,
            ];

            $tree[] = $item;
        }

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
}
