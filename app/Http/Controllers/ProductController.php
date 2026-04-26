<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductStock;
use App\Services\Moysklad\MoySkladService;
use App\Services\Moysklad\StockSyncService;
use App\Services\ProductGroupService;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

// рефакторинг v2 от 26.04.2026 — controller → service
class ProductController extends Controller
{
    public function __construct(
        private readonly MoySkladService $moySkladService,
        private readonly ProductGroupService $productGroupService,
        private readonly ProductService $productService,
    ) {}

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
        if (!$this->moySkladService->hasCredentials()) {
            return back()->with('error', 'Логин или пароль МойСклад не найдены в .env');
        }

        $result = $this->productService->refreshFromMoysklad($id);

        if (!$result['success']) {
            return back()->with('error', $result['message']);
        }

        cache()->forget('products_tree_json_v2');

        return redirect()->route('products.show', $result['product']->moysklad_id)
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

            return $this->productGroupService->attachProductsToTree($groups);
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

}
