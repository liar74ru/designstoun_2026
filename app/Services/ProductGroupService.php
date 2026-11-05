<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderState;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Support\Uom;
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
        usort($tree, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

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
     */
    public function getGroupAndChildrenIds($groupId): array
    {
        if (!$groupId) {
            return [];
        }

        // Используем рекурсивный CTE для PostgreSQL
        $results = DB::select('
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
        ', [$groupId]);

        return collect($results)->pluck('moysklad_id')->toArray();
    }

    public function attachProductsToTree(array $groups): array
    {
        foreach ($groups as &$group) {
            $group['products'] = Product::where('group_id', $group['id'])
                ->orderBy('name')
                ->get(['id', 'moysklad_id', 'name', 'sku', 'uom', 'is_hidden'])
                ->map(fn ($p) => [
                    'id'     => $p->id,
                    // moysklad_id — ссылка на карточку товара из мобильного каталога-дерева на products.index
                    'ms_id'  => $p->moysklad_id,
                    'label'  => $p->name,
                    'sku'    => $p->sku ?? '',
                    'unit'   => Uom::label($p->uom),
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
     * Товары из активных заявок всех отделов: статус «Исп.» и не «Вниз» (собран, отгружен —
     * работы по заявке уже нет). Заявки покупателей в неиспользуемых статусах синк удаляет сам;
     * условие по статусу — и для внутренних заказов, которых синк не трогает.
     * Позиция заявки покупателя, отгруженная целиком, товар не отмечает; у внутренних заказов
     * отгрузок нет — их позиции отмечают товар по статусу.
     */
    public function productIdsInActiveOrders(): array
    {
        // Список id, а не подзапрос: orders.state_moysklad_id — varchar, order_states.id — uuid,
        // и PostgreSQL не сравнивает их без приведения
        $stateIds = OrderState::enabled()->where('is_list_bottom', false)->pluck('id')->all();

        return OrderItem::whereNotNull('product_id')
            ->whereHas('order', fn ($q) => $q->whereIn('state_moysklad_id', $stateIds))
            ->where(fn ($q) => $q->whereColumn('shipped', '<', 'quantity')
                ->orWhereHas('order', fn ($o) => $o->where('kind', Order::KIND_INTERNAL)))
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
