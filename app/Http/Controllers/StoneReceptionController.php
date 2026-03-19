<?php

namespace App\Http\Controllers;

use App\Models\RawMaterialBatch;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use App\Models\Worker;
use App\Models\Product;
use App\Models\Store;
use App\Models\ReceptionLog;
use App\Models\ReceptionLogItem;
use App\Traits\ManagesStock;
use App\Traits\HandlesReceptionValidation;
use App\Traits\HandlesBatchStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StoneReceptionController extends Controller
{
    use ManagesStock, HandlesReceptionValidation, HandlesBatchStock;

    /**
     * ID склада по умолчанию (6. Склад Уралия Цех)
     */
    const DEFAULT_STORE_CODE = '-dggJ2jngG51VKi5mHao91'; // external_code склада по умолчанию

    /**
     * Найти склад по умолчанию: сначала по DEFAULT_STORE_CODE из env,
     * потом по константе, потом первый попавшийся.
     */
    public static function getDefaultStore(): ?\App\Models\Store
    {
        $code = env('DEFAULT_STORE_CODE') ?: self::DEFAULT_STORE_CODE;
        return \App\Models\Store::where('external_code', $code)->first()
            ?? \App\Models\Store::first();
    }

    /**
     * Загружает общие данные для форм
     */
    private function getFormData(StoneReception $reception = null, $selectedCutterId = null)
    {
        $data = [
            'masterWorkers' => Worker::where('position', 'Мастер')->orderBy('name')->get(),
            'workers' => Worker::orderBy('name')->get(),
            'products' => Product::orderBy('name')->get(),
            'stores' => Store::orderBy('name')->get(),
            'defaultStore' => self::getDefaultStore(),
            'activeBatches' => collect(),
        ];

        if ($reception) {
            $reception->load('items', 'rawMaterialBatch.currentWorker');
            $data['stoneReception'] = $reception;
            $data['activeBatches'] = $this->getBatchesForEdit($reception);
        } elseif ($selectedCutterId) {
            $data['activeBatches'] = $this->getActiveBatches($selectedCutterId);
        }

        return $data;
    }

    /**
     * Получает партии для редактирования с учетом текущей партии
     */
    private function getBatchesForEdit(StoneReception $reception)
    {
        if (!$reception->cutter_id) {
            return collect();
        }

        $batches = $this->getActiveBatches($reception->cutter_id);

        if ($reception->rawMaterialBatch && !$batches->contains('id', $reception->raw_material_batch_id)) {
            $currentBatch = clone $reception->rawMaterialBatch;
            $currentBatch->remaining_quantity += $reception->raw_quantity_used;
            $batches->prepend($currentBatch);
        }

        return $batches;
    }

    /**
     * Получает последние приемки с пагинацией
     */
    private function getLastReceptions($perPage = 15)
    {
        return StoneReception::with(['receiver', 'cutter', 'store', 'items.product', 'rawMaterialBatch.product'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Отображает список приемок
     */
    public function index(Request $request)
    {
        $filterBatches = RawMaterialBatch::whereIn('id',
            StoneReception::whereNotNull('raw_material_batch_id')
                ->distinct()->pluck('raw_material_batch_id')
        )->with('product')->get();

        $filterProducts = Product::whereIn('id',
            \App\Models\StoneReceptionItem::distinct()->pluck('product_id')
        )->orderBy('name')->get();

        $filterCutters = Worker::whereIn('id',
            StoneReception::whereNotNull('cutter_id')
                ->distinct()->pluck('cutter_id')
        )->orderBy('name')->get();

        $receptions = QueryBuilder::for(StoneReception::class)
            ->allowedFilters([
                AllowedFilter::callback('status', function ($query, $value) {
                    $query->whereIn('status', is_array($value) ? $value : [$value]);
                }),
                AllowedFilter::exact('raw_material_batch_id'),
                AllowedFilter::callback('product_id', function ($query, $value) {
                    $query->whereHas('items', fn($q) => $q->where('product_id', $value));
                }),
                AllowedFilter::exact('cutter_id'),
            ])
            ->with(['receiver', 'cutter', 'store', 'items.product', 'rawMaterialBatch.product'])
            ->when($request->filled('date_from'), fn($q) =>
            $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn($q) =>
            $q->whereDate('created_at', '<=', $request->date_to))
            ->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        return view('stone-receptions.index', compact(
            'receptions', 'filterBatches', 'filterProducts', 'filterCutters'
        ));
    }

    /**
     * Отображает список лога приемок
     */
    public function logs(Request $request)
    {
        $filterBatches = RawMaterialBatch::whereIn('id',
            StoneReception::whereNotNull('raw_material_batch_id')
                ->distinct()->pluck('raw_material_batch_id')
        )->with('product')->get();

        $filterProducts = Product::whereIn('id',
            StoneReceptionItem::distinct()->pluck('product_id')
        )->orderBy('name')->get();

        $filterCutters = Worker::whereIn('id',
            StoneReception::whereNotNull('cutter_id')
                ->distinct()->pluck('cutter_id')
        )->orderBy('name')->get();

        $logs = QueryBuilder::for(ReceptionLog::class)
            ->allowedFilters([
                AllowedFilter::exact('cutter_id'),
                AllowedFilter::exact('raw_material_batch_id'),
                AllowedFilter::callback('product_id', function ($query, $value) {
                    $query->whereHas('items', fn($q) => $q->where('product_id', $value));
                }),
            ])
            ->with(['cutter', 'receiver', 'items.product',
                'stoneReception.store', 'rawMaterialBatch.product'])
            ->when($request->filled('date_from'), fn($q) =>
            $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn($q) =>
            $q->whereDate('created_at', '<=', $request->date_to))
            ->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        return view('stone-receptions.logs', compact(
            'logs', 'filterBatches', 'filterProducts', 'filterCutters'
        ));
    }

    /**
     * Форма создания приемки
     */
    public function create(Request $request)
    {
        $cutterId = $request->input('cutter_id');
        $batchId = $request->input('raw_material_batch_id');

        $data = $this->getFormData(null, $cutterId);
        $data['lastReceptions'] = $this->getLastReceptions();
        $data['filteredBatches'] = $cutterId ? $this->getActiveBatches($cutterId) : collect();
        $data['selectedCutterId'] = $cutterId;
        $data['selectedBatchId'] = $batchId;
        $data['copiedData'] = session('copy_data');

        return view('stone-receptions.create', $data);
    }

    /**
     * Сохраняет приемку
     */
    public function store(Request $request)
    {
        Log::info('Данные формы:', $request->all());

        try {
            $data = $this->validateReception($request, true);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Ошибка валидации:', $e->errors());
            throw $e;
        }

        // Проверки
        if (!$request->input('cutter_id')) {
            return back()->withErrors(['cutter_id' => 'Выберите пильщика'])->withInput();
        }

        $batch = RawMaterialBatch::find($data['raw_material_batch_id']);
        if (!$batch) {
            return back()->withErrors(['raw_material_batch_id' => 'Партия сырья не найдена'])->withInput();
        }

        if ($batch->remaining_quantity < $data['raw_quantity_used']) {
            return back()->withErrors(['raw_quantity_used' => 'Недостаточно сырья'])->withInput();
        }

        try {
            DB::transaction(function () use ($data, $request) {
                $manualDate = $request->input('manual_created_at');
                if (auth()->user()?->isAdmin() && $manualDate) {
                    $data['manual_created_at'] = \Carbon\Carbon::parse($manualDate);
                }
                $reception = StoneReception::create($this->prepareReceptionData($data));
                $this->createReceptionItems($reception, $data['products']);

                // Пишем лог создания — перезагружаем items чтобы они точно были в коллекции
                $reception->load('items');
                $log = ReceptionLog::create([
                    'stone_reception_id'    => $reception->id,
                    'raw_material_batch_id' => $reception->raw_material_batch_id,
                    'cutter_id'             => $reception->cutter_id,
                    'receiver_id'           => $reception->receiver_id,
                    'type'                  => ReceptionLog::TYPE_CREATED,
                    'raw_quantity_delta'    => (float) $reception->raw_quantity_used,
                    'created_at'            => $reception->created_at,
                ]);
                foreach ($reception->items as $item) {
                    ReceptionLogItem::create([
                        'reception_log_id' => $log->id,
                        'product_id'       => $item->product_id,
                        'quantity_delta'   => (float) $item->quantity,
                    ]);
                }
            });

            session()->forget('copy_data');

            return redirect()->route('stone-receptions.create', ['cutter_id' => $request->input('cutter_id')])
                ->with('success', 'Приемка создана');

        } catch (\Exception $e) {
            Log::error('Ошибка:', ['error' => $e->getMessage(), 'data' => $data]);
            return back()->withErrors(['error' => 'Ошибка: ' . $e->getMessage()])->withInput();
        }
    }

    /**
     * Детальная страница приемки
     */
    public function show(StoneReception $stoneReception)
    {
        $stoneReception->load([
            'receiver',
            'cutter',
            'store',
            'items.product',
            'rawMaterialBatch.product',
            'receptionLogs' => fn($q) => $q->orderBy('created_at', 'asc'),
            'receptionLogs.items.product',
            'receptionLogs.receiver',
            'receptionLogs.cutter',
        ]);

        return view('stone-receptions.show', compact('stoneReception'));
    }

    /**
     * Форма редактирования
     */
    public function edit(StoneReception $stoneReception)
    {
        $data = $this->getFormData($stoneReception);
        $data['lastReceptions'] = $this->getLastReceptions();

        return view('stone-receptions.edit', $data);
    }

    /**
     * Обновляет приемку
     */
    public function update(Request $request, StoneReception $stoneReception)
    {
        // Вычисляем итоговый raw_quantity_used из дельты ДО валидации,
        // чтобы валидатор получил готовое значение, а не 0 от дельты
        $rawDelta = (float) $request->input('raw_quantity_delta', 0);
        $currentRaw = (float) $stoneReception->raw_quantity_used;
        $request->merge(['raw_quantity_used' => $currentRaw + $rawDelta]);

        $data = $this->validateReception($request, false);

        // Передаём manual_created_at и оригинальную дату для prepareReceptionData
        $manualDate = $request->input('manual_created_at');
        if (auth()->user()?->isAdmin() && $manualDate) {
            $data['manual_created_at'] = \Carbon\Carbon::parse($manualDate);
        }
        $data['original_created_at'] = $stoneReception->created_at;

        try {
            DB::transaction(function () use ($stoneReception, $data, $rawDelta) {

                // Запоминаем старые значения продуктов ДО сохранения
                $preSaveItems = $stoneReception->items()
                    ->get()
                    ->pluck('quantity', 'product_id')
                    ->map(fn($q) => (float) $q)
                    ->toArray();

                // Обновляем партию сырья и саму приёмку
                $this->handleBatchUpdate($stoneReception, $data);
                $stoneReception->update($this->prepareReceptionData($data, false));
                $this->updateReceptionItems($stoneReception, $data['products']);

                // Считаем дельты продуктов: новое (из формы) минус старое (из preSaveItems)
                $deltas = [];

                foreach ($data['products'] as $p) {
                    $productId = $p['product_id'];
                    $newQty    = (float) $p['quantity'];
                    $oldQty    = $preSaveItems[$productId] ?? 0.0;
                    $delta     = $newQty - $oldQty;
                    if (abs($delta) > 0.0001) {
                        $deltas[$productId] = $delta;
                    }
                }

                // Продукты которые были, но теперь удалены (не пришли в форме)
                $newProductIds = array_column($data['products'], 'product_id');
                foreach ($preSaveItems as $productId => $oldQty) {
                    if (!in_array($productId, $newProductIds) && abs($oldQty) > 0.0001) {
                        $deltas[$productId] = -$oldQty;
                    }
                }

                // Пишем лог только если реально что-то изменилось
                if (empty($deltas) && abs($rawDelta) < 0.0001) {
                    return;
                }

                $log = ReceptionLog::create([
                    'stone_reception_id'    => $stoneReception->id,
                    'raw_material_batch_id' => $stoneReception->raw_material_batch_id,
                    'cutter_id'             => $stoneReception->cutter_id,
                    'receiver_id'           => $stoneReception->receiver_id,
                    'type'                  => ReceptionLog::TYPE_UPDATED,
                    'raw_quantity_delta'    => $rawDelta,
                    'created_at'            => now(),
                ]);

                foreach ($deltas as $productId => $delta) {
                    ReceptionLogItem::create([
                        'reception_log_id' => $log->id,
                        'product_id'       => $productId,
                        'quantity_delta'   => $delta,
                    ]);
                }
            });

            return redirect()->route('stone-receptions.index')->with('success', 'Приемка обновлена');

        } catch (\Exception $e) {
            Log::error('Ошибка обновления:', ['error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Ошибка: ' . $e->getMessage()])->withInput();
        }
    }


    private function handleBatchUpdate(StoneReception $reception, array $newData)
    {
        $oldBatchId = $reception->raw_material_batch_id;
        $oldQty     = (float) $reception->raw_quantity_used;  // явный float, иначе "1.000" == 0.7 может вести себя непредсказуемо
        $newBatchId = $newData['raw_material_batch_id'];
        $newQty     = (float) $newData['raw_quantity_used'];

        // Если партия та же и количество не изменилось — ничего не делаем
        if ($oldBatchId == $newBatchId && abs($oldQty - $newQty) < 0.0001) {
            return;
        }

        // Возвращаем старое количество обратно в старую партию
        if ($oldBatchId && $oldBatch = RawMaterialBatch::find($oldBatchId)) {
            $oldBatch->remaining_quantity = (float) $oldBatch->remaining_quantity + $oldQty;
            $oldBatch->save();
        }

        // Списываем новое количество из (возможно другой) партии
        $newBatch = RawMaterialBatch::find($newBatchId);
        if (!$newBatch || (float) $newBatch->remaining_quantity < $newQty) {
            throw new \Exception('Недостаточно сырья');
        }

        $newBatch->remaining_quantity = (float) $newBatch->remaining_quantity - $newQty;

        // Если сырьё закончилось — меняем статус
        if ($newBatch->remaining_quantity <= 0) {
            $newBatch->remaining_quantity = 0;
            $newBatch->status = 'used';
            // Если вернули сырьё в партию которая была закрыта — снова активируем
        } elseif ($newBatch->status === 'used') {
            // Производство уже было — возвращаем в 'in_work'
            $newBatch->status = \App\Models\RawMaterialBatch::STATUS_IN_WORK;
        }

        $newBatch->save();
    }

    /**
     * Удаляет приемку
     */
    public function destroy(StoneReception $stoneReception)
    {
        try {
            DB::transaction(fn() => $stoneReception->delete());
            return redirect()->route('stone-receptions.index')->with('success', 'Приемка удалена');
        } catch (\Exception $e) {
            Log::error('Ошибка удаления:', ['error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Ошибка удаления']);
        }
    }

    /**
     * Копирует приемку
     */
    public function copy(Request $request, StoneReception $stoneReception)
    {
        try {
            $stoneReception->load('items');

            session()->put('copy_data', [
                'receiver_id' => $stoneReception->receiver_id,
                'notes' => $stoneReception->notes . ' (копия)',
                'products' => $stoneReception->items->map(fn($item) => [
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                ])->toArray()
            ]);

            $params = array_filter([
                'cutter_id' => $request->input('cutter_id'),
                'raw_material_batch_id' => $request->input('raw_material_batch_id')
            ]);

            return redirect()->route('stone-receptions.create', $params)
                ->with('success', 'Продукты скопированы');

        } catch (\Exception $e) {
            Log::error('Ошибка копирования:', ['error' => $e->getMessage()]);
            return redirect()->route('stone-receptions.create')
                ->withErrors(['error' => 'Ошибка копирования: ' . $e->getMessage()]);
        }
    }

    /**
     * Подготавливает данные приемки
     */
    private function prepareReceptionData(array $data, bool $forCreate = true, bool $forCopy = false): array
    {
        $prepared = [
            'receiver_id' => $data['receiver_id'],
            'cutter_id' => $data['cutter_id'] ?? null,
            'store_id' => $data['store_id'],
            'raw_material_batch_id' => $data['raw_material_batch_id'],
            'raw_quantity_used' => $data['raw_quantity_used'],
            'notes' => $data['notes'] ?? null,
        ];

        if (!$forCopy) {
            if ($forCreate) {
                $prepared['created_at'] = $data['manual_created_at'] ?? now();
                $prepared['updated_at'] = $data['manual_created_at'] ?? now();
            } else {
                $prepared['created_at'] = $data['manual_created_at'] ?? $data['original_created_at'];
                $prepared['updated_at'] = now();
            }
        }

        return $prepared;
    }

    /**
     * Создает позиции продуктов.
     * Фиксирует effective_cost_coeff на момент создания приёмки —
     * последующие изменения справочника продуктов не затронут эту приёмку.
     */
    private function createReceptionItems(StoneReception $reception, array $products): void
    {
        foreach ($products as $product) {
            $productModel = Product::find($product['product_id']);
            $baseCoeff    = (float) ($productModel?->prod_cost_coeff ?? 0);
            $isUndercut   = !empty($product['is_undercut']);
            $effCoeff     = StoneReceptionItem::computeEffectiveCoeff($baseCoeff, $isUndercut);

            $reception->items()->create([
                'product_id'           => $product['product_id'],
                'quantity'             => $product['quantity'],
                'effective_cost_coeff' => $effCoeff,
                'is_undercut'          => $isUndercut,
            ]);
        }
    }

    /**
     * Обновляет позиции продуктов.
     *
     * Для СУЩЕСТВУЮЩИХ позиций — количество обновляется, но effective_cost_coeff
     * и is_undercut НЕ трогаются (история зафиксирована).
     * Для НОВЫХ позиций — фиксируем коэффициент на текущий момент.
     */
    private function updateReceptionItems(StoneReception $reception, array $products): void
    {
        $existingItems = $reception->items()->get()->keyBy('product_id');

        $newProductIds = array_column($products, 'product_id');

        foreach ($products as $product) {
            $productId = $product['product_id'];

            if ($existingItems->has($productId)) {
                // Существующая позиция — обновляем только quantity
                $existingItems[$productId]->update([
                    'quantity'   => $product['quantity'],
                    'updated_at' => now(),
                ]);
            } else {
                // Новая позиция — фиксируем коэффициент
                $productModel = \App\Models\Product::find($productId);
                $baseCoeff    = (float) ($productModel?->prod_cost_coeff ?? 0);
                $isUndercut   = !empty($product['is_undercut']);
                $effCoeff     = \App\Models\StoneReceptionItem::computeEffectiveCoeff($baseCoeff, $isUndercut);

                $reception->items()->create([
                    'product_id'           => $productId,
                    'quantity'             => $product['quantity'],
                    'effective_cost_coeff' => $effCoeff,
                    'is_undercut'          => $isUndercut,
                ]);
            }
        }

        // Удаляем позиции которых нет в новых данных
        $reception->items()->whereNotIn('product_id', $newProductIds)->delete();
    }

    /**
     * Inline-обновление effective_cost_coeff и is_undercut позиции из страницы show.
     * Позволяет скорректировать коэффициент постфактум (например, если он был
     * неверно задан в справочнике продуктов на момент приёмки).
     */
    public function updateItemCoeff(Request $request, StoneReception $stoneReception)
    {
        $validated = $request->validate([
            'items'              => ['required', 'array'],
            'items.*.item_id'    => ['required', 'integer'],
            'items.*.base_coeff' => ['required', 'numeric'],
            'items.*.is_undercut'=> ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($stoneReception, $validated) {
            foreach ($validated['items'] as $row) {
                $item = $stoneReception->items()->findOrFail($row['item_id']);

                $isUndercut = !empty($row['is_undercut']);
                $baseCoeff  = (float) $row['base_coeff'];
                $effCoeff   = \App\Models\StoneReceptionItem::computeEffectiveCoeff($baseCoeff, $isUndercut);

                $item->update([
                    'effective_cost_coeff' => $effCoeff,
                    'is_undercut'          => $isUndercut,
                ]);
            }
        });

        return back()->with('success', 'Коэффициенты обновлены');
    }

    /**
     * Сбрасывает статус приемки
     */
    public function resetStatus(StoneReception $stoneReception)
    {
        $stoneReception->update([
            'status' => StoneReception::STATUS_ACTIVE,
            'moysklad_processing_id' => null,
            'synced_at' => null
        ]);

        return back()->with('success', 'Статус сброшен на Активна');
    }

    /**
     * AJAX: партии сырья для пильщика (используется в форме приёмки без перезагрузки страницы)
     */
    public function getBatchesJson(\App\Models\Worker $worker)
    {
        $batches = $this->getActiveBatches($worker->id)->map(fn($b) => [
            'id'                 => $b->id,
            'label'              => $b->product->name
                . ' (ост: ' . number_format($b->remaining_quantity, 2) . ' м³)'
                . ($b->batch_number ? ' №' . $b->batch_number : ''),
            'remaining_quantity' => (float) $b->remaining_quantity,
        ]);

        return response()->json($batches);
    }
}
