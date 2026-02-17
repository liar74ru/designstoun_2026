<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Http\Request;

class ProductFilterService
{
    private $query;
    private $allowedSorts = ['name', 'sku', 'price', 'quantity', 'created_at'];

    public function __construct(Request $request)
    {
        $this->query = Product::query();
        $this->applyFilters($request);
    }

    /**
     * Применить все фильтры из запроса
     */
    private function applyFilters(Request $request)
    {
        // Поиск по названию и артикулу
        if ($request->filled('search')) {
            $search = $request->search;
            $this->query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Фильтр по группе
        if ($request->filled('group')) {
            $this->query->where('group_id', $request->group);
        }

        // Фильтр по наличию
        if ($request->filled('in_stock')) {
            if ($request->in_stock == '1') {
                $this->query->where('quantity', '>', 0);
            } else {
                $this->query->where('quantity', '<=', 0);
            }
        }

        // Фильтр по цене
        if ($request->filled('price_from')) {
            $this->query->where('price', '>=', $request->price_from);
        }
        if ($request->filled('price_to')) {
            $this->query->where('price', '<=', $request->price_to);
        }
    }

    /**
     * Применить сортировку
     */
    public function applySorting($sortField, $sortDirection)
    {
        if (!in_array($sortField, $this->allowedSorts)) {
            $sortField = 'name';
        }

        $this->query->orderBy($sortField, $sortDirection);
        return $this;
    }

    /**
     * Получить результаты с пагинацией
     */
    public function paginate($perPage = 50)
    {
        return $this->query->paginate($perPage);
    }

    /**
     * Получить запрос
     */
    public function getQuery()
    {
        return $this->query;
    }
}
