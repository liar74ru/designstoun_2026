<?php

namespace App\Http\Controllers;

use App\Models\RawMaterialBatch;
use App\Models\Product;
use App\Models\Store;
use App\Models\Worker;
use App\Models\ProductStock;
use App\Traits\ManagesStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RawMaterialBatchController extends Controller
{
    use ManagesStock;

    /**
     * Список партий с фильтрацией.
     */
    public function index(Request $request)
    {
        $query = RawMaterialBatch::with(['product', 'currentStore', 'currentWorker']);

        // Фильтр по статусу
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Фильтр по пильщику
        if ($request->filled('worker_id')) {
            $query->where('current_worker_id', $request->worker_id);
        }

        // Фильтр по продукту
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // Поиск по номеру партии
        if ($request->filled('search')) {
            $query->where('batch_number', 'like', '%' . $request->search . '%');
        }

        $batches = $query->orderBy('created_at', 'desc')->paginate(15);

        // Для фильтров в шаблоне
        $workers = Worker::orderBy('name')->get();
        $products = Product::orderBy('name')->get();
        $statuses = ['active' => 'Активные', 'used' => 'Израсходованы', 'returned' => 'Возвращены'];

        return view('raw-batches.index', compact('batches', 'workers', 'products', 'statuses'));
    }

    /**
     * Детальный просмотр партии.
     */
    public function show($id)
    {
        $batch = RawMaterialBatch::with([
            'product',
            'currentStore',
            'currentWorker',
            'movements' => function ($q) {
                $q->with(['fromStore', 'toStore', 'fromWorker', 'toWorker', 'movedBy'])
                    ->orderBy('created_at', 'desc');
            },
            'receptions' => function ($q) {
                $q->with(['product', 'receiver', 'cutter'])
                    ->orderBy('created_at', 'desc');
            }
        ])->findOrFail($id);

        return view('raw-batches.show', compact('batch'));
    }

    /**
     * Форма создания новой партии.
     */
    public function create()
    {
        // Только продукты, которые могут быть сырьём (можно добавить условие, если есть is_raw)
        $products = Product::orderBy('name')->get();
        $stores = Store::orderBy('name')->get();
        $workers = Worker::orderBy('name')->get();

        return view('raw-batches.create', compact('products', 'stores', 'workers'));
    }

    /**
     * Сохранение новой партии.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id'       => 'required|exists:products,id',
            'quantity'         => 'required|numeric|min:0.001',
            'worker_id'        => 'required|exists:workers,id',
            'from_store_id'    => 'required|exists:stores,id',
            'to_store_id'      => 'required|exists:stores,id',
            'batch_number'     => 'nullable|string|max:255',
        ]);

        // Проверка наличия сырья на складе-источнике
        $sourceStock = ProductStock::where('product_id', $data['product_id'])
            ->where('store_id', $data['from_store_id'])
            ->first();

        if (!$sourceStock || $sourceStock->quantity < $data['quantity']) {
            return back()->withErrors(['quantity' => 'Недостаточно сырья на складе.'])->withInput();
        }

        DB::transaction(function () use ($data) {
            $batch = RawMaterialBatch::create([
                'product_id'          => $data['product_id'],
                'initial_quantity'    => $data['quantity'],
                'remaining_quantity'  => $data['quantity'],
                'current_store_id'    => $data['to_store_id'],
                'current_worker_id'   => $data['worker_id'],
                'batch_number'        => $data['batch_number'],
                'status'              => 'active',
            ]);

            // Запись перемещения
            \App\Models\RawMaterialMovement::create([
                'batch_id'       => $batch->id,
                'from_store_id'  => $data['from_store_id'],
                'to_store_id'    => $data['to_store_id'],
                'from_worker_id' => null,
                'to_worker_id'   => $data['worker_id'],
                'moved_by'       => auth()->id(), // предполагается, что пользователь авторизован
                'movement_type'  => 'create',
                'quantity'       => $data['quantity'],
            ]);

            // Обновление остатков
            $this->adjustStock($data['product_id'], $data['from_store_id'], -$data['quantity']);
            $this->adjustStock($data['product_id'], $data['to_store_id'], +$data['quantity']);
        });

        return redirect()->route('raw-batches.index')
            ->with('success', 'Партия сырья успешно создана.');
    }

    /**
     * Форма редактирования (если нужна).
     */
    public function edit(RawMaterialBatch $batch)
    {
        // Обычно партии не редактируют, можно вернуть ошибку или просто показать форму с запретом
        abort(404);
    }

    /**
     * Обновление (если нужно).
     */
    public function update(Request $request, RawMaterialBatch $batch)
    {
        abort(404);
    }

    /**
     * Удаление партии (если нужно).
     */
    public function destroy(RawMaterialBatch $batch)
    {
        // Проверка, можно ли удалять (например, только если нет связанных приемок)
        if ($batch->receptions()->exists()) {
            return back()->with('error', 'Нельзя удалить партию, к которой есть приемки.');
        }

        DB::transaction(function () use ($batch) {
            // Возвращаем остаток на склад (если партия ещё активна и остаток > 0)
            if ($batch->status === 'active' && $batch->remaining_quantity > 0) {
                $this->adjustStock($batch->product_id, $batch->current_store_id, -$batch->remaining_quantity);
                // Возврат на склад-источник? Лучше запретить удаление, если есть остаток
            }
            $batch->movements()->delete(); // удаляем связанные перемещения
            $batch->delete();
        });

        return redirect()->route('raw-batches.index')
            ->with('success', 'Партия удалена.');
    }

    /**
     * Форма передачи партии другому пильщику.
     */
    public function transferForm(RawMaterialBatch $batch)
    {
        if (!$batch->isActive()) {
            return redirect()->route('raw-batches.show', $batch)
                ->with('error', 'Эта партия уже неактивна.');
        }

        $workers = Worker::orderBy('name')->get();
        return view('raw-batches.transfer', compact('batch', 'workers'));
    }

    /**
     * Обработка передачи.
     */
    public function transfer(Request $request, RawMaterialBatch $batch)
    {
        if (!$batch->isActive()) {
            return back()->withErrors(['batch' => 'Эта партия уже неактивна.']);
        }

        $data = $request->validate([
            'to_worker_id' => 'required|exists:workers,id',
        ]);

        DB::transaction(function () use ($batch, $data) {
            $oldWorker = $batch->current_worker_id;

            \App\Models\RawMaterialMovement::create([
                'batch_id'       => $batch->id,
                'from_store_id'  => null,
                'to_store_id'    => null,
                'from_worker_id' => $oldWorker,
                'to_worker_id'   => $data['to_worker_id'],
                'moved_by'       => auth()->id(),
                'movement_type'  => 'transfer_to_worker',
                'quantity'       => $batch->remaining_quantity,
            ]);

            $batch->current_worker_id = $data['to_worker_id'];
            $batch->save();
        });

        return redirect()->route('raw-batches.show', $batch)
            ->with('success', 'Партия передана другому пильщику.');
    }

    /**
     * Форма возврата партии на склад.
     */
    public function returnForm(RawMaterialBatch $batch)
    {
        if (!$batch->isActive()) {
            return redirect()->route('raw-batches.show', $batch)
                ->with('error', 'Эта партия уже неактивна.');
        }

        $stores = Store::orderBy('name')->get();
        return view('raw-batches.return', compact('batch', 'stores'));
    }

    /**
     * Обработка возврата.
     */
    public function return(Request $request, RawMaterialBatch $batch)
    {
        if (!$batch->isActive()) {
            return back()->withErrors(['batch' => 'Эта партия уже неактивна.']);
        }

        $data = $request->validate([
            'to_store_id' => 'required|exists:stores,id',
        ]);

        DB::transaction(function () use ($batch, $data) {
            $oldStore = $batch->current_store_id;
            $quantity = $batch->remaining_quantity;

            \App\Models\RawMaterialMovement::create([
                'batch_id'       => $batch->id,
                'from_store_id'  => $oldStore,
                'to_store_id'    => $data['to_store_id'],
                'from_worker_id' => $batch->current_worker_id,
                'to_worker_id'   => null,
                'moved_by'       => auth()->id(),
                'movement_type'  => 'return_to_store',
                'quantity'       => $quantity,
            ]);

            // Обновление остатков
            $this->adjustStock($batch->product_id, $oldStore, -$quantity);
            $this->adjustStock($batch->product_id, $data['to_store_id'], +$quantity);

            // Обновление партии
            $batch->current_store_id = $data['to_store_id'];
            $batch->current_worker_id = null;
            $batch->status = 'returned';
            $batch->save();
        });

        return redirect()->route('raw-batches.show', $batch)
            ->with('success', 'Партия возвращена на склад.');
    }
}
