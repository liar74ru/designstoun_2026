<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductStock;
use App\Services\MoySkladService;
use App\Services\ProductGroupService;
use App\Services\StockSyncService;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ProductController extends Controller
{
    private MoySkladService $moySkladService;

    private ProductGroupService $productGroupService;

    public function __construct(MoySkladService $moySkladService, ProductGroupService $productGroupService)
    {
        $this->moySkladService = $moySkladService;
        $this->productGroupService = $productGroupService;
    }

    /**
     * Display a listing of the resource with sorting and filters.
     */
    public function index(Request $request)
    {
        $sortField = $request->input('sort', 'name');
        $sortDirection = $request->input('direction', 'asc');

        $products = QueryBuilder::for(Product::class)
            ->allowedFilters([
                AllowedFilter::callback('search', function ($query, $value) {
                    $query->where(function ($q) use ($value) {
                        $q->where('name', 'ILIKE', "%{$value}%")
                            ->orWhere('sku', 'ILIKE', "%{$value}%")
                            ->orWhere('description', 'ILIKE', "%{$value}%");
                    });
                }),
                AllowedFilter::callback('group_id', function ($query, $value) {
                    $groupIds = $this->productGroupService->getGroupAndChildrenIds($value);
                    if (! empty($groupIds)) {
                        $query->whereIn('group_id', $groupIds);
                    }
                }),
                AllowedFilter::callback('in_stock', function ($query, $value) {
                    if ($value === '1') {
                        $query->whereHas('stocks', fn ($q) => $q->where('quantity', '>', 0));
                    } elseif ($value === '0') {
                        $query->whereDoesntHave('stocks', fn ($q) => $q->where('quantity', '>', 0));
                    }
                }),
            ])
            ->defaultSort('id')
            ->allowedSorts(['name', 'sku', 'price', 'quantity', 'group_id', 'created_at'])
            ->paginate(15)
            ->withQueryString();

        $groupsTree = $this->productGroupService->getGroupsTree();

        return view('products.index', compact('products', 'sortField', 'sortDirection', 'groupsTree'));
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $product = Product::with(['stocks.store'])
            ->where('moysklad_id', $id)
            ->firstOrFail();

        return view('products.show', compact('product'));
    }

    /**
     * Показать дерево групп.
     */
    public function groups()
    {
        $groupsTree = $this->productGroupService->getGroupsTree();
        $stats = $this->productGroupService->getStats();

        return view('products.groups', compact('groupsTree', 'stats'));
    }

    /**
     * Синхронизация товаров и групп с МойСклад.
     */
    public function syncFromMoySklad()
    {
        if (! $this->moySkladService->hasCredentials()) {
            return redirect()->route('products.index')
                ->with('error', 'Логин или пароль МойСклад не найдены в .env');
        }

        $groupsResult = $this->moySkladService->syncGroups();
        $productsResult = $this->moySkladService->syncProducts();

        if (! $productsResult['success']) {
            return redirect()->route('products.index')
                ->with('error', $productsResult['message']);
        }

        $message = $productsResult['message'];
        if ($groupsResult['success'] && $groupsResult['synced'] > 0) {
            $message .= ". Синхронизировано групп: {$groupsResult['synced']}";
        }

        cache()->forget('products_tree_json_v2');

        return redirect()->route('products.index')->with('success', $message);
    }

    /**
     * Обновить один товар из МойСклад.
     */
    public function refresh($id)
    {
        if (! $this->moySkladService->hasCredentials()) {
            return back()->with('error', 'Логин или пароль МойСклад не найдены в .env');
        }

        $item = $this->moySkladService->fetchProduct($id);

        if (! $item) {
            return back()->with('error', 'Не удалось обновить товар');
        }

        $price = 0;
        $oldPrice = null;

        if (isset($item['salePrices']) && count($item['salePrices']) > 0) {
            $price = $item['salePrices'][0]['value'] / 100;
            if (isset($item['salePrices'][1])) {
                $oldPrice = $item['salePrices'][1]['value'] / 100;
            }
        }

        $product = Product::updateOrCreate(
            ['moysklad_id' => $item['id']],
            [
                'name' => $item['name'] ?? '',
                'sku' => $item['article'] ?? $item['code'] ?? '',
                'description' => $item['description'] ?? '',
                'price' => $price,
                'old_price' => $oldPrice,
                'prod_cost_coeff' => $this->moySkladService->extractAttributePublic($item, 'prodCostCoeff'),
                'attributes' => json_encode([
                    'code' => $item['code'] ?? null,
                    'article' => $item['article'] ?? null,
                    'weight' => $item['weight'] ?? null,
                    'volume' => $item['volume'] ?? null,
                    'path_name' => $item['pathName'] ?? null,
                ]),
            ]
        );

        cache()->forget('products_tree_json_v2');

        return redirect()->route('products.show', $product->moysklad_id)
            ->with('success', 'Товар обновлен');
    }

    /**
     * Синхронизировать остатки по складам для одного товара.
     */
    public function syncStocks(StockSyncService $stockSyncService, $moyskladId)
    {
        $result = $stockSyncService->updateProductStocksByMoyskladId($moyskladId);

        return redirect()->route('products.show', $moyskladId)
            ->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Синхронизировать остатки по складам для всех товаров.
     */
    public function syncAllProductsStocks(StockSyncService $stockSyncService)
    {
        $result = $stockSyncService->syncAllProductsStocksByStores();

        return redirect()->route('products.index')
            ->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Синхронизация только групп товаров.
     */
    public function syncGroups()
    {
        if (! $this->moySkladService->hasCredentials()) {
            return redirect()->route('products.groups')
                ->with('error', 'Логин или пароль МойСклад не найдены в .env');
        }

        $result = $this->moySkladService->syncGroups();

        return redirect()->route('products.groups')
            ->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    /**
     * AJAX: дерево групп с продуктами для компонента выбора продукта.
     * Кэшируется на 10 минут.
     */
    public function groupsJson()
    {
        $tree = cache()->remember('products_tree_json_v2', 600, function () {
            $groups = $this->productGroupService->getGroupsTree();

            return $this->attachProductsToTree($groups);
        });

        return response()->json($tree);
    }

    /**
     * AJAX: остатки всех продуктов по складам.
     * Формат: { product_id: { total: X, stores: { store_id: qty } } }
     */
    public function stocksJson()
    {
        $result = ProductStock::select('product_id', 'store_id', 'quantity')
            ->get()
            ->groupBy('product_id')
            ->map(fn ($items) => [
                'total' => round((float) $items->sum('quantity'), 3),
                'stores' => $items->pluck('quantity', 'store_id')
                    ->map(fn ($q) => round((float) $q, 3)),
            ]);

        return response()->json($result);
    }

    /**
     * AJAX: возвращает prod_cost_coeff продукта (используется в форме приёмки
     * для отображения/пересчёта коэффициента при выборе продукта).
     */
    public function getCoeff(Product $product)
    {
        return response()->json([
            'prod_cost_coeff' => (float) $product->prod_cost_coeff,
        ]);
    }

    /**
     * Рекурсивно добавляет продукты к узлам дерева групп.
     */
    private function attachProductsToTree(array $groups): array
    {
        foreach ($groups as &$group) {
            $group['products'] = Product::where('group_id', $group['id'])
                ->orderBy('name')
                ->get(['id', 'name', 'sku'])
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'label' => $p->name,
                    'sku' => $p->sku ?? '',
                ])
                ->toArray();

            if (! empty($group['children'])) {
                $group['children'] = $this->attachProductsToTree($group['children']);
            }
        }

        return $groups;
    }
}
