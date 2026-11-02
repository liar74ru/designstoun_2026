<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\OrderState;
use App\Models\Product;
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

    public function attachProductsToTree(array $groups): array
    {
        foreach ($groups as &$group) {
            $group['products'] = Product::where('group_id', $group['id'])
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'uom', 'is_hidden'])
                ->map(fn ($p) => [
                    'id'     => $p->id,
                    'label'  => $p->name,
                    'sku'    => $p->sku ?? '',
                    'unit'   => $p->uom,
                    // Скрытые отдаются вместе со всеми: прячет их пикер, пока не включён «весь каталог»
                    'hidden' => $p->is_hidden,
                ])
                ->toArray();

            if (!empty($group['children'])) {
                $group['children'] = $this->attachProductsToTree($group['children']);
            }
        }

        return $groups;
    }

    /**
     * Товары из активных заявок — любых загруженных (статус «Исп.»), всех отделов.
     * Заявки покупателей в других статусах синк удаляет сам; условие по статусу — для
     * внутренних заказов, которых синк не трогает.
     */
    public function productIdsInActiveOrders(): array
    {
        return OrderItem::whereNotNull('product_id')
            ->whereHas('order', fn ($q) => $q->whereIn('state_moysklad_id', OrderState::enabled()->select('id')))
            ->distinct()
            ->pluck('product_id')
            ->all();
    }

    /**
     * Метит товары дерева из активных заявок: in_order — первыми в быстром поиске,
     * и hidden снимается — заявленный товар виден даже с флажком hiddenByDefault.
     * Накладывается поверх кэша дерева: заявки меняются чаще, чем он живёт.
     */
    public function markOrderedProducts(array $groups, array $productIds): array
    {
        $ids = array_flip($productIds);

        $mark = function (array $groups) use (&$mark, $ids) {
            foreach ($groups as &$group) {
                foreach ($group['products'] as &$product) {
                    $product['in_order'] = isset($ids[$product['id']]);
                    if ($product['in_order']) {
                        $product['hidden'] = false;
                    }
                }
                unset($product);

                if (!empty($group['children'])) {
                    $group['children'] = $mark($group['children']);
                }
            }

            return $groups;
        };

        return $mark($groups);
    }
}
