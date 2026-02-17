<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource with sorting.
     */
    public function index(Request $request)
    {
        // Если есть параметр ?moysklad - показываем товары из API
        if ($request->has('moysklad')) {
            return $this->fetchFromMoySklad($request);
        }

        // Сортировка
        $sortField = $request->get('sort', 'name');
        $sortDirection = $request->get('direction', 'asc');

        // Разрешенные поля для сортировки
        $allowedSorts = ['name', 'sku', 'price', 'quantity', 'created_at'];

        if (!in_array($sortField, $allowedSorts)) {
            $sortField = 'name';
        }

        // Иначе показываем товары из БД с сортировкой
        $products = Product::orderBy($sortField, $sortDirection)
            ->paginate(50)
            ->withQueryString(); // Сохраняем параметры сортировки в пагинации

        return view('products.index', compact('products', 'sortField', 'sortDirection'));
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
        $product = Product::where('moysklad_id', $id)->firstOrFail();
        return view('products.show', compact('product'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        abort(404);
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
     * Синхронизация товаров с МойСклад
     */
    public function syncFromMoySklad(Request $request)
    {
        $login = env('MOYSKLAD_LOGIN');
        $password = env('MOYSKLAD_PASSWORD');

        if (!$login || !$password) {
            return redirect()->route('products.index')
                ->with('error', 'Логин или пароль МойСклад не найдены в .env');
        }

        try {
            $response = Http::withBasicAuth($login, $password)
                ->withHeaders(['Accept-Encoding' => 'gzip'])
                ->get('https://api.moysklad.ru/api/remap/1.2/entity/product', [
                    'limit' => 1000, // Увеличиваем лимит до 1000
                    'order' => 'updated,desc',
                ]);

            if (!$response->successful()) {
                return redirect()->route('products.index')
                    ->with('error', 'Ошибка API МойСклад: ' . $response->status());
            }

            $data = $response->json();
            $moyskladProducts = $data['rows'] ?? [];
            $synced = 0;
            $updated = 0;
            $errors = 0;

            foreach ($moyskladProducts as $item) {
                try {
                    $price = 0;
                    $oldPrice = null;

                    if (isset($item['salePrices']) && count($item['salePrices']) > 0) {
                        $price = $item['salePrices'][0]['value'] / 100;

                        if (isset($item['salePrices'][1])) {
                            $oldPrice = $item['salePrices'][1]['value'] / 100;
                        }
                    }

                    // Получаем SKU
                    $sku = $item['article'] ?? $item['code'] ?? null;

                    // Проверяем существование товара
                    $existingProduct = Product::where('moysklad_id', $item['id'])->first();

                    if ($existingProduct) {
                        $updated++;
                    } else {
                        $synced++;
                    }

                    Product::updateOrCreate(
                        ['moysklad_id' => $item['id']],
                        [
                            'name' => $item['name'] ?? 'Без названия',
                            'sku' => $sku,
                            'description' => $item['description'] ?? null,
                            'price' => $price,
                            'old_price' => $oldPrice,
                            'quantity' => $item['stock'] ?? 0,
                            'is_active' => true,
                            'attributes' => json_encode([
                                'code' => $item['code'] ?? null,
                                'article' => $item['article'] ?? null,
                                'weight' => $item['weight'] ?? null,
                                'volume' => $item['volume'] ?? null,
                                'path_name' => $item['pathName'] ?? null,
                                'updated' => $item['updated'] ?? null,
                            ]),
                        ]
                    );

                } catch (\Exception $e) {
                    $errors++;
                    Log::warning('Ошибка при сохранении товара', [
                        'moysklad_id' => $item['id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $message = "Синхронизация завершена. Добавлено: $synced, обновлено: $updated";
            if ($errors > 0) {
                $message .= ", ошибок: $errors (проверьте логи)";
            }

            return redirect()->route('products.index')
                ->with('success', $message);

        } catch (\Exception $e) {
            Log::error('Ошибка синхронизации товаров', ['error' => $e->getMessage()]);
            return redirect()->route('products.index')
                ->with('error', 'Ошибка: ' . $e->getMessage());
        }
    }

    /**
     * Обновить конкретный товар из МойСклад
     */
    public function refresh($id)
    {
        $login = env('MOYSKLAD_LOGIN');
        $password = env('MOYSKLAD_PASSWORD');

        try {
            $response = Http::withBasicAuth($login, $password)
                ->withHeaders(['Accept-Encoding' => 'gzip'])
                ->get("https://api.moysklad.ru/api/remap/1.2/entity/product/{$id}");

            if (!$response->successful()) {
                return back()->with('error', 'Не удалось обновить товар');
            }

            $item = $response->json();

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

        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Получить товары из МойСклад (для отображения)
     */
    private function fetchFromMoySklad(Request $request)
    {
        $login = env('MOYSKLAD_LOGIN');
        $password = env('MOYSKLAD_PASSWORD');

        try {
            $response = Http::withBasicAuth($login, $password)
                ->withHeaders(['Accept-Encoding' => 'gzip'])
                ->get('https://api.moysklad.ru/api/remap/1.2/entity/product', [
                    'limit' => 50,
                    'order' => 'updated,desc',
                ]);

            if (!$response->successful()) {
                return redirect()->route('products.index')
                    ->with('error', 'Ошибка API МойСклад');
            }

            $data = $response->json();
            $moyskladProducts = $data['rows'] ?? [];

            $products = collect($moyskladProducts)->map(function($item) {
                $price = 0;
                $oldPrice = null;
                $discountPercent = null;

                if (isset($item['salePrices']) && count($item['salePrices']) > 0) {
                    $price = $item['salePrices'][0]['value'] / 100;

                    if (isset($item['salePrices'][1])) {
                        $oldPrice = $item['salePrices'][1]['value'] / 100;
                        if ($oldPrice > $price) {
                            $discountPercent = round((($oldPrice - $price) / $oldPrice) * 100);
                        }
                    }
                }

                return (object) [
                    'moysklad_id' => $item['id'] ?? null,
                    'name' => $item['name'] ?? 'Без названия',
                    'sku' => $item['article'] ?? $item['code'] ?? 'Без артикула',
                    'description' => $item['description'] ?? '',
                    'price' => $price,
                    'old_price' => $oldPrice,
                    'discount_percent' => $discountPercent,
                    'quantity' => $item['stock'] ?? 0,
                    'path_name' => $item['pathName'] ?? '',
                    'updated' => $item['updated'] ?? null,
                    'images' => $item['images'] ?? null,
                    'code' => $item['code'] ?? '',
                    'article' => $item['article'] ?? '',
                    'weight' => $item['weight'] ?? null,
                    'volume' => $item['volume'] ?? null,
                    'is_active' => true,
                ];
            });

            return view('products.moysklad', [
                'products' => $products,
                'total' => $data['meta']['size'] ?? 0
            ]);

        } catch (\Exception $e) {
            return redirect()->route('products.index')
                ->with('error', 'Ошибка: ' . $e->getMessage());
        }
    }
}
