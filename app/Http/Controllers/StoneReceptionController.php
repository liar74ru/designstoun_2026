<?php

namespace App\Http\Controllers;

use App\Models\RawMaterialBatch;
use App\Models\StoneReception;
use App\Models\Worker;
use App\Models\Product;
use App\Models\Store;
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
    const DEFAULT_STORE_ID = '0b1972f7-5e59-11ec-0a80-0698000bf502';

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
            'defaultStore' => Store::find(env('DEFAULT_STORE_ID', self::DEFAULT_STORE_ID)),
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
     * Получает последние приемки
     */
    private function getLastReceptions($limit = 10)
    {
        return StoneReception::with(['receiver', 'cutter', 'store', 'items.product', 'rawMaterialBatch.product'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Отображает список приемок
     */
    public function index()
    {
        $receptions = StoneReception::with([
            'receiver', 'cutter', 'store', 'items.product', 'rawMaterialBatch.product'
        ])->orderBy('created_at', 'desc')->paginate(20);

        return view('stone-receptions.index', compact('receptions'));
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
            DB::transaction(function () use ($data) {
                $reception = StoneReception::create($this->prepareReceptionData($data));
                $this->createReceptionItems($reception, $data['products']);
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
        $data = $this->validateReception($request, false);

        try {
            DB::transaction(function () use ($stoneReception, $data) {
                $this->handleBatchUpdate($stoneReception, $data);
                $stoneReception->update($this->prepareReceptionData($data, false));
                $this->updateReceptionItems($stoneReception, $data['products']);
            });

            return redirect()->route('stone-receptions.index')->with('success', 'Приемка обновлена');

        } catch (\Exception $e) {
            Log::error('Ошибка обновления:', ['error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Ошибка: ' . $e->getMessage()])->withInput();
        }
    }

    /**
     * Обрабатывает обновление партии сырья
     */
    private function handleBatchUpdate(StoneReception $reception, array $newData)
    {
        $oldBatchId = $reception->raw_material_batch_id;
        $oldQty = $reception->raw_quantity_used;
        $newBatchId = $newData['raw_material_batch_id'];
        $newQty = $newData['raw_quantity_used'];

        if ($oldBatchId == $newBatchId && $oldQty == $newQty) {
            return;
        }

        // Возврат в старую партию
        if ($oldBatchId && $oldBatch = RawMaterialBatch::find($oldBatchId)) {
            $oldBatch->remaining_quantity += $oldQty;
            $oldBatch->save();
        }

        // Списание из новой партии
        $newBatch = RawMaterialBatch::find($newBatchId);
        if (!$newBatch || $newBatch->remaining_quantity < $newQty) {
            throw new \Exception('Недостаточно сырья');
        }

        $newBatch->remaining_quantity -= $newQty;
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
            $prepared[$forCreate ? 'created_at' : 'updated_at'] = now();
        }

        return $prepared;
    }

    /**
     * Создает позиции продуктов
     */
    private function createReceptionItems(StoneReception $reception, array $products): void
    {
        foreach ($products as $product) {
            $reception->items()->create([
                'product_id' => $product['product_id'],
                'quantity' => $product['quantity'],
            ]);
        }
    }

    /**
     * Обновляет позиции продуктов
     */
    private function updateReceptionItems(StoneReception $reception, array $products): void
    {
        $existingIds = $reception->items()->pluck('id')->toArray();

        $upsertData = array_map(fn($p) => [
            'stone_reception_id' => $reception->id,
            'product_id'         => $p['product_id'],
            'quantity'           => $p['quantity'],
            'updated_at'         => now(),
        ], $products);

        \DB::table('stone_reception_items')->upsert(
            $upsertData,
            ['stone_reception_id', 'product_id'],
            ['quantity', 'updated_at']
        );

        // Удаляем только те позиции, которых нет в новых данных
        $newProductIds = array_column($products, 'product_id');
        $reception->items()->whereNotIn('product_id', $newProductIds)->delete();
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
}
