<?php

namespace App\Http\Controllers;

use App\Models\RawMaterialBatch;
use App\Models\RawMaterialMovement;
use App\Models\ProductStock;
use App\Models\Store;
use App\Services\MoySkladMoveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RawMaterialMovementController extends Controller
{
    private $moySkladMoveService;

    public function __construct(MoySkladMoveService $moySkladMoveService)
    {
        $this->moySkladMoveService = $moySkladMoveService;
    }

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

        DB::transaction(function () use ($data, $request) {
            $batch = RawMaterialBatch::create([
                'product_id'          => $data['product_id'],
                'initial_quantity'    => $data['quantity'],
                'remaining_quantity'  => $data['quantity'],
                'current_store_id'    => $data['to_store_id'],
                'current_worker_id'   => $data['worker_id'],
                'batch_number'        => $data['batch_number'],
                'status'              => 'active',
            ]);

            // Запись перемещения в БД
            $movement = RawMaterialMovement::create([
                'batch_id'       => $batch->id,
                'from_store_id'  => $data['from_store_id'],
                'to_store_id'    => $data['to_store_id'],
                'from_worker_id' => null,
                'to_worker_id'   => $data['worker_id'],
                'moved_by'       => auth()->id(),
                'movement_type'  => 'create',
                'quantity'       => $data['quantity'],
            ]);

            // Обновление остатков в БД
            $this->adjustStock($data['product_id'], $data['from_store_id'], -$data['quantity']);
            $this->adjustStock($data['product_id'], $data['to_store_id'], +$data['quantity']);

            // Создание перемещения в МойСклад
            $this->createMoveInMoySklad($data, $batch, $movement);
        });

        return redirect()->route('raw-batches.index')
            ->with('success', 'Партия сырья успешно создана.');
    }

    /**
     * Создать перемещение в МойСклад
     */
    private function createMoveInMoySklad(array $data, $batch, $movement)
    {
        try {
            // Получаем moysklad_id товара
            $product = $batch->product;
            if (!$product->moysklad_id) {
                \Illuminate\Support\Facades\Log::warning(
                    'Товар не синхронизирован с МойСклад',
                    ['product_id' => $product->id]
                );
                return;
            }

            $moveData = [
                'from_store_id' => $data['from_store_id'],
                'to_store_id' => $data['to_store_id'],
                'products' => [
                    [
                        'product_id' => $product->moysklad_id,
                        'quantity' => $data['quantity']
                    ]
                ],
                'name' => 'Партия: ' . ($data['batch_number'] ?? $batch->id),
                'description' => 'Автоматическое перемещение из системы',
                'external_id' => 'movement_' . $movement->id
            ];

            $result = $this->moySkladMoveService->createMove($moveData);

            if ($result['success']) {
                // Сохраняем ID перемещения в МойСклад
                $movement->update([
                    'moysklad_move_id' => $result['move_id'],
                    'moysklad_synced' => true
                ]);

                \Illuminate\Support\Facades\Log::info(
                    'Перемещение синхронизировано с МойСклад',
                    ['move_id' => $result['move_id']]
                );
            } else {
                \Illuminate\Support\Facades\Log::warning(
                    'Ошибка синхронизации перемещения с МойСклад',
                    ['error' => $result['message']]
                );
            }

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error(
                'Исключение при создании перемещения в МойСклад',
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Возврат партии со склада
     */
    public function return(Request $request, RawMaterialBatch $batch)
    {
        // Валидация
        $data = $request->validate([
            'to_store_id' => 'required|exists:stores,id',
        ]);

        // Проверка, что партия активна
        if (!$batch->isActive()) {
            return back()->withErrors(['batch' => 'Эта партия уже неактивна.']);
        }

        // Проверка, что партия находится у работника (не на складе)
        if (!$batch->current_worker_id) {
            return back()->withErrors(['batch' => 'Партия уже находится на складе.']);
        }

        DB::transaction(function () use ($batch, $data) {
            $oldStore = $batch->current_store_id;
            $oldWorker = $batch->current_worker_id;
            $quantity = $batch->remaining_quantity;

            // Создаем запись о возврате
            $movement = RawMaterialMovement::create([
                'batch_id'       => $batch->id,
                'from_store_id'  => $oldStore,
                'to_store_id'    => $data['to_store_id'],
                'from_worker_id' => $oldWorker,
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

            // Создание перемещения в МойСклад
            $this->createReturnMoveInMoySklad($batch, $movement, $oldStore, $data['to_store_id'], $quantity);
        });

        return redirect()->route('raw-batches.show', $batch)
            ->with('success', 'Партия успешно возвращена на склад.');
    }

    private function createReturnMoveInMoySklad($batch, $movement, $fromStoreId, $toStoreId, $quantity)
    {
        try {
            $product = $batch->product;
            if (!$product->moysklad_id) {
                Log::warning(
                    'Товар не синхронизирован с МойСклад при возврате',
                    ['product_id' => $product->id, 'batch_id' => $batch->id]
                );
                return;
            }

            // Получаем склады - их id уже является moysklad_id
            $fromStore = Store::find($fromStoreId);
            $toStore = Store::find($toStoreId);

            if (!$fromStore || !$toStore) {
                Log::warning(
                    'Склады не найдены при возврате',
                    [
                        'from_store_id' => $fromStoreId,
                        'to_store_id' => $toStoreId,
                        'batch_id' => $batch->id
                    ]
                );
                return;
            }

            // ВАЖНО: Используем $fromStore->id и $toStore->id как moysklad_id
            Log::info('Информация о складах при возврате', [
                'from_store' => [
                    'id' => $fromStore->id,
                    'name' => $fromStore->name
                ],
                'to_store' => [
                    'id' => $toStore->id,
                    'name' => $toStore->name
                ]
            ]);

            $moveData = [
                'from_store_id' => $fromStore->id, // Это и есть moysklad_id
                'to_store_id' => $toStore->id,     // Это и есть moysklad_id
                'products' => [
                    [
                        'product_id' => $product->moysklad_id,
                        'quantity' => $quantity
                    ]
                ],
                'name' => 'Возврат партии: ' . ($batch->batch_number ?? '№' . $batch->id),
                'description' => 'Автоматический возврат партии на склад из системы',
                'external_id' => 'movement_' . $movement->id . '_return'
            ];

            Log::info('Отправка данных в МойСклад (return)', ['moveData' => $moveData]);

            $result = $this->moySkladMoveService->createMove($moveData);

            if ($result['success']) {
                $movement->update([
                    'moysklad_move_id' => $result['move_id'],
                    'moysklad_synced' => true
                ]);

                Log::info(
                    'Возврат партии синхронизирован с МойСклад',
                    [
                        'move_id' => $result['move_id'],
                        'batch_id' => $batch->id
                    ]
                );
            } else {
                Log::warning(
                    'Ошибка синхронизации возврата партии с МойСклад',
                    [
                        'error' => $result['message'],
                        'batch_id' => $batch->id
                    ]
                );
            }

        } catch (\Exception $e) {
            Log::error(
                'Исключение при создании перемещения в МойСклад для возврата партии',
                [
                    'error' => $e->getMessage(),
                    'batch_id' => $batch->id,
                    'trace' => $e->getTraceAsString()
                ]
            );
        }
    }

    private function adjustStock($productId, $storeId, $quantity)
    {
        $stock = ProductStock::firstOrCreate(
            ['product_id' => $productId, 'store_id' => $storeId],
            ['quantity' => 0]
        );

        $stock->quantity += $quantity;
        $stock->save();
    }
}
