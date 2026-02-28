<?php

namespace App\Http\Controllers;

use App\Models\RawMaterialBatch;
use App\Models\StoneReception;
use App\Models\Worker;
use App\Models\Product;
use App\Models\Store;
use App\Traits\ManagesStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\RawMaterialMovement;

class StoneReceptionController extends Controller
{
    use ManagesStock;
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
        $workers = Worker::orderBy('name')->get();
        $products = Product::orderBy('name')->get();
        $defaultStore = Store::find(env('DEFAULT_STORE_ID', '0b1972f7-5e59-11ec-0a80-0698000bf502')); // ID склада цеха
        $lastReceptions = StoneReception::with(['receiver', 'cutter', 'product', 'store'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Активные партии сырья с остатком > 0
        $activeBatches = RawMaterialBatch::with(['product', 'currentWorker'])
            ->where('status', 'active')
            ->where('remaining_quantity', '>', 0)
            ->orderBy('created_at', 'desc')
            ->get();

        return view('stone-receptions.create', compact('workers', 'products', 'defaultStore', 'lastReceptions', 'activeBatches'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'receiver_id' => 'required|exists:workers,id',
            'cutter_id' => 'nullable|exists:workers,id',
            'product_id' => 'required|exists:products,id',
            'store_id' => 'required|exists:stores,id',
            'quantity' => 'required|numeric|min:0.001',
            'raw_material_batch_id' => 'required|exists:raw_material_batches,id',
            'raw_quantity_used' => 'required|numeric|min:0.001',
            'notes' => 'nullable|string',
        ]);

        $batch = RawMaterialBatch::findOrFail($data['raw_material_batch_id']);

        if (!$batch->isActive()) {
            return back()->withErrors(['raw_material_batch_id' => 'Выбранная партия неактивна.']);
        }

        if ($batch->remaining_quantity < $data['raw_quantity_used']) {
            return back()->withErrors(['raw_quantity_used' => 'В партии недостаточно остатка.']);
        }

        DB::transaction(function () use ($data, $batch) {
            // Создание приемки
            $reception = StoneReception::create($data);

            // Уменьшаем остаток в партии
            $batch->remaining_quantity -= $data['raw_quantity_used'];
            if ($batch->remaining_quantity == 0) {
                $batch->status = 'used';
            }
            $batch->save();

            // Запись перемещения типа use
            RawMaterialMovement::create([
                'batch_id' => $batch->id,
                'from_store_id' => $batch->current_store_id,
                'to_store_id' => null,
                'from_worker_id' => $batch->current_worker_id,
                'to_worker_id' => null,
                'moved_by' => auth()->id(),
                'movement_type' => 'use',
                'quantity' => $data['raw_quantity_used'],
            ]);

            // Обновление остатков на складе:
            // Сырье уменьшается
            $this->adjustStock($batch->product_id, $batch->current_store_id, -$data['raw_quantity_used']);
            // Готовая продукция увеличивается (на том же складе или на указанном в приемке)
            $this->adjustStock($data['product_id'], $data['store_id'], +$data['quantity']);
        });

        return redirect()->route('stone-receptions.create')
            ->with('success', 'Приемка успешно зарегистрирована.');
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
