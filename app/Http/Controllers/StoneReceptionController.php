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
     * Загружает общие данные для форм create и edit
     */
    private function getFormData(StoneReception $reception = null)
    {
        $data = [
            'workers' => Worker::orderBy('name')->get(),
            'products' => Product::orderBy('name')->get(),
            'stores' => Store::orderBy('name')->get(),
            'defaultStore' => Store::find(env('DEFAULT_STORE_ID', self::DEFAULT_STORE_ID)),
            'activeBatches' => $this->getActiveBatches($reception),
        ];

        if ($reception) {
            $data['stoneReception'] = $reception->load('items');
        }

        return $data;
    }

    /**
     * Получает последние приемки для отображения
     */
    private function getLastReceptions($limit = 10)
    {
        return StoneReception::with(['receiver', 'cutter', 'store', 'items.product', 'rawMaterialBatch.product'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $receptions = StoneReception::with([
            'receiver',
            'cutter',
            'store',
            'items.product',
            'rawMaterialBatch.product'
        ])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('stone-receptions.index', compact('receptions'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $data = $this->getFormData();
        $data['lastReceptions'] = $this->getLastReceptions();

        return view('stone-receptions.create', $data);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Валидация данных
        $data = $this->validateReception($request, true);

        // Проверка остатка сырья
        if (!$this->checkBatchStock($data['raw_material_batch_id'], $data['raw_quantity_used'])) {
            return back()
                ->withErrors(['raw_quantity_used' => 'Недостаточно сырья в выбранной партии'])
                ->withInput();
        }

        try {
            DB::transaction(function () use ($data) {
                // Создаем приемку
                $reception = StoneReception::create($this->prepareReceptionData($data));

                // Создаем позиции продуктов
                $this->createReceptionItems($reception, $data['products']);

                // Обновляем остатки
                $reception->updateStocks();

                Log::info('Приемка успешно создана', [
                    'reception_id' => $reception->id,
                    'user_id' => auth()->id()
                ]);
            });

            return redirect()->route('stone-receptions.create')
                ->with('success', 'Приемка успешно создана');

        } catch (\Exception $e) {
            Log::error('Ошибка при создании приемки', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);

            return back()
                ->withErrors(['error' => 'Произошла ошибка при создании приемки: ' . $e->getMessage()])
                ->withInput();
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(StoneReception $stoneReception)
    {
        $data = $this->getFormData($stoneReception);
        $data['lastReceptions'] = $this->getLastReceptions();

        return view('stone-receptions.edit', $data);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, StoneReception $stoneReception)
    {
        // Валидация данных
        $data = $this->validateReception($request, false);

        try {
            DB::transaction(function () use ($stoneReception, $data) {
                // Обрабатываем изменения в партии сырья
                $this->handleBatchChanges($stoneReception, $data);

                // Обновляем основную информацию
                $stoneReception->update($this->prepareReceptionData($data, false));

                // Обновляем позиции продуктов
                $this->updateReceptionItems($stoneReception, $data['products']);

                Log::info('Приемка успешно обновлена', [
                    'reception_id' => $stoneReception->id,
                    'user_id' => auth()->id()
                ]);
            });

            return redirect()->route('stone-receptions.index')
                ->with('success', 'Приемка успешно обновлена');

        } catch (\Exception $e) {
            Log::error('Ошибка при обновлении приемки', [
                'error' => $e->getMessage(),
                'reception_id' => $stoneReception->id,
                'data' => $data
            ]);

            return back()
                ->withErrors(['error' => 'Произошла ошибка при обновлении приемки: ' . $e->getMessage()])
                ->withInput();
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(StoneReception $stoneReception)
    {
        try {
            DB::transaction(function () use ($stoneReception) {
                $stoneReception->delete();

                Log::info('Приемка удалена', [
                    'reception_id' => $stoneReception->id,
                    'user_id' => auth()->id()
                ]);
            });

            return redirect()->route('stone-receptions.index')
                ->with('success', 'Приемка успешно удалена');

        } catch (\Exception $e) {
            Log::error('Ошибка при удалении приемки', [
                'error' => $e->getMessage(),
                'reception_id' => $stoneReception->id
            ]);

            return back()
                ->withErrors(['error' => 'Произошла ошибка при удалении приемки']);
        }
    }

    /**
     * Copy the specified resource.
     */
    public function copy(StoneReception $stoneReception)
    {
        try {
            $stoneReception->load('items');

            session()->flash('copy_from', [
                'reception' => $this->prepareReceptionData($stoneReception->toArray(), false, true),
                'products' => $stoneReception->items->map(fn($item) => [
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                ])->toArray()
            ]);

            Log::info('Приемка скопирована', [
                'original_id' => $stoneReception->id,
                'user_id' => auth()->id()
            ]);

            return redirect()->route('stone-receptions.create')
                ->with('success', 'Данные скопированы. Измените при необходимости.');

        } catch (\Exception $e) {
            Log::error('Ошибка при копировании приемки', [
                'error' => $e->getMessage(),
                'reception_id' => $stoneReception->id
            ]);

            return redirect()->route('stone-receptions.index')
                ->withErrors(['error' => 'Произошла ошибка при копировании приемки']);
        }
    }

    /**
     * Подготавливает данные для создания/обновления приемки
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

        // Для копирования не нужно добавлять временные метки
        if (!$forCopy) {
            if ($forCreate) {
                $prepared['created_at'] = now();
            }
            $prepared['updated_at'] = now();
        }

        return $prepared;
    }

    /**
     * Создает позиции продуктов для приемки
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
     * Обновляет позиции продуктов для приемки
     */
    private function updateReceptionItems(StoneReception $reception, array $products): void
    {
        // Удаляем старые позиции
        $reception->items()->delete();

        // Создаем новые позиции
        $this->createReceptionItems($reception, $products);
    }
}
