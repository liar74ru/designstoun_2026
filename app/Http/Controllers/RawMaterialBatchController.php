<?php

namespace App\Http\Controllers;

use App\Models\RawMaterialBatch;
use App\Models\Product;
use App\Models\RawMaterialMovement;
use App\Models\Store;
use App\Models\Worker;
use App\Models\ProductStock;
use App\Services\ProductGroupService;
use App\Traits\ManagesStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class RawMaterialBatchController extends Controller
{
    use ManagesStock;

    private ProductGroupService $productGroupService;

    public function __construct(ProductGroupService $productGroupService)
    {
        $this->productGroupService = $productGroupService;
    }

    public function index(Request $request)
    {
        $baseQuery = RawMaterialBatch::with(['product', 'currentStore', 'currentWorker']);

        $batches = QueryBuilder::for($baseQuery)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('current_worker_id'),
                AllowedFilter::partial('batch_number'),
                AllowedFilter::exact('product_id'),
                AllowedFilter::callback('group_id', function ($query, $value) {
                    if (!empty($value)) {
                        $groupIds = $this->productGroupService->getGroupAndChildrenIds($value);
                        if (!empty($groupIds)) {
                            $query->whereHas('product', fn($q) => $q->whereIn('group_id', $groupIds));
                        }
                    }
                }),
            ])
            ->defaultSort('-created_at')
            ->allowedSorts(['batch_number', 'created_at', 'quantity'])
            ->paginate(15)
            ->withQueryString();

        $workers   = Worker::orderBy('name')->get();
        $products  = Product::orderBy('name')->get();
        $statuses  = ['active' => 'Активные', 'used' => 'Израсходованы', 'returned' => 'Возвращены'];
        $groupsTree = $this->productGroupService->getGroupsTree();

        return view('raw-batches.index', compact('batches', 'workers', 'products', 'statuses', 'groupsTree'));
    }

    public function show($id)
    {
        $batch = RawMaterialBatch::with([
            'product', 'currentStore', 'currentWorker',
            'movements' => fn($q) => $q->with(['fromStore', 'toStore', 'fromWorker', 'toWorker', 'movedBy'])->orderBy('created_at', 'desc'),
            'receptions' => fn($q) => $q->with(['product', 'receiver', 'cutter'])->orderBy('created_at', 'desc'),
        ])->findOrFail($id);

        return view('raw-batches.show', compact('batch'));
    }

    public function create()
    {
        $products = Product::orderBy('name')->get();
        $stores   = Store::orderBy('name')->get();
        $workers  = Worker::orderBy('name')->get();

        return view('raw-batches.create', compact('products', 'stores', 'workers'));
    }

    /**
     * API: следующий номер партии для пильщика на текущей неделе.
     * Формат: ГГ-НН-Фамилия-ПП
     */
    public function nextBatchNumber(Worker $worker)
    {
        return response()->json([
            'batch_number' => $this->generateBatchNumber($worker),
        ]);
    }

    /**
     * Генерируем номер партии.
     * ГГ  — две последние цифры года (26)
     * НН  — номер ISO-недели (01-53)
     * Фамилия — первое слово имени работника
     * ПП  — порядковый номер партий пильщика на этой неделе
     */
    public function generateBatchNumber(Worker $worker): string
    {
        $year   = now()->format('y');
        $week   = now()->format('W');
        $name   = explode(' ', trim($worker->name))[0];

        $count = RawMaterialBatch::where('current_worker_id', $worker->id)
            ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->count();

        return "{$year}-{$week}-{$name}-" . str_pad($count + 1, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Копировать партию — открыть форму создания с предзаполненными данными.
     * Передаём данные через сессию (как в stone-receptions).
     */
    public function copy(RawMaterialBatch $batch)
    {
        session()->put('copy_from', [
            'product_id'    => $batch->product_id,
            'product_name'  => $batch->product->name ?? '',
            'from_store_id' => $batch->movements()->orderBy('created_at')->first()?->from_store_id,
            'to_store_id'   => $batch->current_store_id,
            'worker_id'     => $batch->current_worker_id,
        ]);

        return redirect()->route('raw-batches.create')
            ->with('success', 'Данные скопированы — заполните количество и сохраните');
    }

    /**
     * Создание новой партии + запись перемещения + обновление остатков.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id'    => 'required|exists:products,id',
            'quantity'      => 'required|numeric|min:0.001',
            'worker_id'     => 'required|exists:workers,id',
            'from_store_id' => 'required|exists:stores,id',
            'to_store_id'   => 'required|exists:stores,id',
            'batch_number'  => 'nullable|string|max:255',
        ]);

        // Проверка наличия сырья на складе-источнике
        $sourceStock = ProductStock::where('product_id', $data['product_id'])
            ->where('store_id', $data['from_store_id'])
            ->first();

        if (!$sourceStock || $sourceStock->quantity < $data['quantity']) {
            return back()
                ->withErrors(['quantity' => 'Недостаточно сырья на складе-источнике.'])
                ->withInput();
        }

        DB::transaction(function () use ($data) {
            $batch = RawMaterialBatch::create([
                'product_id'         => $data['product_id'],
                'initial_quantity'   => $data['quantity'],
                'remaining_quantity' => $data['quantity'],
                'current_store_id'   => $data['to_store_id'],
                'current_worker_id'  => $data['worker_id'],
                'batch_number'       => $data['batch_number'],
                'status'             => 'active',
            ]);

            RawMaterialMovement::create([
                'batch_id'       => $batch->id,
                'from_store_id'  => $data['from_store_id'],
                'to_store_id'    => $data['to_store_id'],
                'from_worker_id' => null,
                'to_worker_id'   => $data['worker_id'],
                'moved_by'       => null,
                'movement_type'  => 'create',
                'quantity'       => $data['quantity'],
            ]);

            $this->adjustStock($data['product_id'], $data['from_store_id'], -$data['quantity']);
            $this->adjustStock($data['product_id'], $data['to_store_id'],   +$data['quantity']);
        });

        session()->forget('copy_from');

        return redirect()->route('raw-batches.index')
            ->with('success', 'Партия сырья успешно создана.');
    }

    public function edit(RawMaterialBatch $batch)
    {
        abort(404);
    }

    public function update(Request $request, RawMaterialBatch $batch)
    {
        abort(404);
    }

    public function destroy(RawMaterialBatch $batch)
    {
        if ($batch->receptions()->exists()) {
            return back()->with('error', 'Нельзя удалить партию, к которой есть приемки.');
        }

        DB::transaction(function () use ($batch) {
            if ($batch->status === 'active' && $batch->remaining_quantity > 0) {
                $this->adjustStock($batch->product_id, $batch->current_store_id, -$batch->remaining_quantity);
            }
            $batch->movements()->delete();
            $batch->delete();
        });

        return redirect()->route('raw-batches.index')->with('success', 'Партия удалена.');
    }

    public function transferForm(RawMaterialBatch $batch)
    {
        if (!$batch->isActive()) {
            return redirect()->route('raw-batches.show', $batch)->with('error', 'Партия уже неактивна.');
        }

        $workers = Worker::orderBy('name')->get();
        return view('raw-batches.transfer', compact('batch', 'workers'));
    }

    public function transfer(Request $request, RawMaterialBatch $batch)
    {
        if (!$batch->isActive()) {
            return back()->withErrors(['batch' => 'Партия уже неактивна.']);
        }

        $data = $request->validate(['to_worker_id' => 'required|exists:workers,id']);

        DB::transaction(function () use ($batch, $data) {
            RawMaterialMovement::create([
                'batch_id'       => $batch->id,
                'from_store_id'  => null,
                'to_store_id'    => null,
                'from_worker_id' => $batch->current_worker_id,
                'to_worker_id'   => $data['to_worker_id'],
                'moved_by'       => null,
                'movement_type'  => 'transfer_to_worker',
                'quantity'       => $batch->remaining_quantity,
            ]);

            $batch->update(['current_worker_id' => $data['to_worker_id']]);
        });

        return redirect()->route('raw-batches.show', $batch)->with('success', 'Партия передана.');
    }

    public function returnForm(RawMaterialBatch $batch)
    {
        if (!$batch->isActive()) {
            return redirect()->route('raw-batches.show', $batch)->with('error', 'Партия уже неактивна.');
        }

        $stores = Store::orderBy('name')->get();
        return view('raw-batches.return', compact('batch', 'stores'));
    }
}
