<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\StockSyncService;
use Illuminate\Http\Request;
use App\Services\MoySkladService;
use App\Services\ProductGroupService;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ProductController extends Controller
{
    private $moySkladService;
    private $productGroupService;

    public function __construct(MoySkladService $moySkladService, ProductGroupService $productGroupService)
    {
        $this->moySkladService = $moySkladService;
        $this->productGroupService = $productGroupService;
    }

    /**
     * Display a listing of the resource with sorting.
     */
    public function index(Request $request)
    {
        // Сортировка
        $sortField = $request->input('sort', 'name');
        $sortDirection = $request->input('direction', 'asc');

        $products = QueryBuilder::for(Product::class)
            ->allowedFilters([
                AllowedFilter::callback('search', function($query, $value) {
                    $query->where(function($q) use ($value) {
                        $q->where('name', 'ILIKE', "%{$value}%")
                            ->orWhere('sku', 'ILIKE', "%{$value}%")
                            ->orWhere('description', 'ILIKE', "%{$value}%");
                    });
                }),
                AllowedFilter::exact('group_id'),
                AllowedFilter::callback('in_stock', function($query, $value) {
                    if ($value === '1') {
                        $query->where('quantity', '>', 0);
                    } elseif ($value === '0') {
                        $query->where('quantity', '=', 0);
                    }
                }),
            ])
            ->defaultSort('id')
            ->allowedSorts(['name', 'sku', 'price', 'quantity', 'created_at'])
            ->paginate(15)
            ->withQueryString(); // Сохраняем параметры фильтрации в пагинации

        // Получаем дерево групп для фильтра
        $groupsTree = $this->productGroupService->getGroupsTree();

        return view('products.index', compact('products', 'sortField', 'sortDirection', 'groupsTree'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('products.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|unique:products',
            'price' => 'required|numeric|min:0',
            'quantity' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
        ]);

        Product::create($validated);

        return redirect()->route('products.index')
            ->with('success', 'Товар успешно создан');
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $product = Product::with(['stocks.store'])  // Загружаем остатки и связанные склады
        ->where('moysklad_id', $id)
            ->firstOrFail();

        return view('products.show', compact('product'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $product = Product::where('moysklad_id', $id)->firstOrFail();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|unique:products,sku,' . $product->id,
            'price' => 'required|numeric|min:0',
            'quantity' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $product->update($validated);

        return redirect()->route('products.show', $product->moysklad_id)
            ->with('success', 'Товар успешно обновлен');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $product = Product::where('moysklad_id', $id)->firstOrFail();
        $product->delete();

        return redirect()->route('products.index')
            ->with('success', 'Товар "'.$product->name.'" удален из локальной базы');
    }

    /**
     * Показать дерево групп
     */
    public function groups()
    {
        $groupsTree = $this->productGroupService->getGroupsTree();
        $stats = $this->productGroupService->getStats();

        return view('products.groups', compact('groupsTree', 'stats'));
    }

    /**
     * Синхронизация товаров с МойСклад
     */
    public function syncFromMoySklad()
    {
        if (!$this->moySkladService->hasCredentials()) {
            return redirect()->route('products.index')
                ->with('error', 'Логин или пароль МойСклад не найдены в .env');
        }

        // Сначала синхронизируем группы
        $groupsResult = $this->moySkladService->syncGroups();

        // Затем синхронизируем товары
        $productsResult = $this->moySkladService->syncProducts();

        if (!$productsResult['success']) {
            return redirect()->route('products.index')
                ->with('error', $productsResult['message']);
        }

        $message = $productsResult['message'];
        if ($groupsResult['success'] && $groupsResult['synced'] > 0) {
            $message .= ". Синхронизировано групп: {$groupsResult['synced']}";
        }

        return redirect()->route('products.index')
            ->with('success', $message);
    }

    /**
     * Обновить конкретный товар из МойСклад
     */
    public function refresh($id)
    {
        if (!$this->moySkladService->hasCredentials()) {
            return back()->with('error', 'Логин или пароль МойСклад не найдены в .env');
        }

        $item = $this->moySkladService->fetchProduct($id);

        if (!$item) {
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
                'quantity' => $item['stock'] ?? 0,
                'attributes' => json_encode([
                    'code' => $item['code'] ?? null,
                    'article' => $item['article'] ?? null,
                    'weight' => $item['weight'] ?? null,
                    'volume' => $item['volume'] ?? null,
                    'path_name' => $item['pathName'] ?? null,
                ]),
            ]
        );

        return redirect()->route('products.show', $product->moysklad_id)
            ->with('success', 'Товар обновлен');
    }

    /**
     * Синхронизация остатков для конкретного товара
     */
    public function syncStocks($moyskladId)
    {
        // Создаем экземпляр сервиса напрямую
        $stockSyncService = new StockSyncService();

        $result = $stockSyncService->updateProductStocksByMoyskladId($moyskladId);

        if ($result['success']) {
            return redirect()->route('products.show', $moyskladId)
                ->with('success', $result['message']);
        } else {
            return redirect()->route('products.show', $moyskladId)
                ->with('error', $result['message']);
        }
    }

    /**
     * Массовая синхронизация остатков по складам для всех товаров
     */
    public function syncAllProductsStocks(StockSyncService $stockSyncService)
    {
        $result = $stockSyncService->syncAllProductsStocksByStores();

        if ($result['success']) {
            return redirect()->route('products.index')
                ->with('success', $result['message']);
        } else {
            return redirect()->route('products.index')
                ->with('error', $result['message']);
        }
    }

    public function syncAllStocks(StockSyncService $stockSyncService)
    {
        $result = $stockSyncService->syncAllStocks();

        if ($result['success']) {
            return redirect()->route('products.index')
                ->with('success', $result['message']);
        } else {
            return redirect()->route('products.index')
                ->with('error', $result['message']);
        }
    }
}
