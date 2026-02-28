<?php

namespace App\Http\Controllers;

use App\Models\StoneReception;
use App\Models\Worker;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Http\Request;

class StoneReceptionController extends Controller
{
    /**
     * ID склада по умолчанию (6. Склад Уралия Цех)
     */
    const DEFAULT_STORE_ID = '0b1972f7-5e59-11ec-0a80-0698000bf502';

    public function index()
    {
        $receptions = StoneReception::with(['receiver', 'cutter', 'product', 'store'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('stone-receptions.index', compact('receptions'));
    }

    public function create()
    {
        // Получаем данные для форм
        $workers = Worker::orderBy('name')->get();
        $products = Product::orderBy('name')->get();
        $defaultStore = Store::find(self::DEFAULT_STORE_ID);
        $lastReceptions = StoneReception::getLastReceptions(10);

        return view('stone-receptions.create', compact('workers', 'products', 'defaultStore', 'lastReceptions'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'receiver_id' => 'required|exists:workers,id',
            'cutter_id' => 'nullable|exists:workers,id',
            'product_id' => 'required|exists:products,id',
            'store_id' => 'required|exists:stores,id',
            'quantity' => 'required|numeric|min:0|regex:/^\d+(\.\d{1,3})?$/',
            'notes' => 'nullable|string|max:500',
        ]);

        $reception = StoneReception::create($validated);

        // Обновляем количество продукта на складе (опционально)
        // $this->updateProductStock($reception);

        return redirect()->route('stone-receptions.create')
            ->with('success', 'Приемка успешно добавлена');
    }

    public function edit(StoneReception $stoneReception)
    {
        $workers = Worker::orderBy('name')->get();
        $products = Product::orderBy('name')->get();
        $stores = Store::orderBy('name')->get();

        return view('stone-receptions.edit', compact('stoneReception', 'workers', 'products', 'stores'));
    }

    public function update(Request $request, StoneReception $stoneReception)
    {
        $validated = $request->validate([
            'receiver_id' => 'required|exists:workers,id',
            'cutter_id' => 'nullable|exists:workers,id',
            'product_id' => 'required|exists:products,id',
            'store_id' => 'required|exists:stores,id',
            'quantity' => 'required|numeric|min:0|regex:/^\d+(\.\d{1,3})?$/',
            'notes' => 'nullable|string|max:500',
        ]);

        $stoneReception->update($validated);

        return redirect()->route('stone-receptions.index')
            ->with('success', 'Приемка успешно обновлена');
    }

    public function destroy(StoneReception $stoneReception)
    {
        $stoneReception->delete();

        return redirect()->route('stone-receptions.index')
            ->with('success', 'Приемка успешно удалена');
    }

    /**
     * Копировать данные из другой приемки
     */
    public function copy(StoneReception $stoneReception)
    {
        session()->flash('copy_from', $stoneReception);

        return redirect()->route('stone-receptions.create');
    }
}
