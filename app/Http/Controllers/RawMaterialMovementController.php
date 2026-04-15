<?php

namespace App\Http\Controllers;

use App\Models\ProductStock;
use App\Models\RawMaterialBatch;
use App\Models\RawMaterialMovement;
use App\Models\Store;
use App\Services\MoySkladMoveService;
use App\Support\DocumentNaming;
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

        if ($data['from_store_id'] === $data['to_store_id']) {
            return back()
                ->withErrors(['to_store_id' => 'Склад-источник и склад-назначение не могут совпадать.'])
                ->withInput();
        }

        // Проверка наличия сырья на складе-источнике
        $sourceStock = ProductStock::where('product_id', $data['product_id'])
            ->where('store_id', $data['from_store_id'])
            ->first();


        if (!$sourceStock || $sourceStock->quantity < $data['quantity']) {
            return back()->withErrors(['quantity' => 'Недостаточно сырья на складе.'])->withInput();
        }

        $batch = null;
        DB::transaction(function () use ($data, $request, &$batch) {
            $manualDate = $request->input('manual_created_at');
            $createdAt  = (auth()->user()?->isAdmin() && $manualDate)
                ? \Carbon\Carbon::parse($manualDate)
                : now();

            // moved_by: берём worker_id текущего пользователя, либо worker из формы как fallback
            $movedBy = auth()->user()?->worker_id ?? $data['worker_id'];

            $batch = RawMaterialBatch::create([
                'product_id'          => $data['product_id'],
                'initial_quantity'    => $data['quantity'],
                'remaining_quantity'  => $data['quantity'],
                'current_store_id'    => $data['to_store_id'],
                'current_worker_id'   => $data['worker_id'],
                'batch_number'        => $data['batch_number'] ?? null,
                'status'              => RawMaterialBatch::STATUS_NEW,
                'created_at'          => $createdAt,
                'updated_at'          => $createdAt,
            ]);

            // Запись перемещения в БД
            $movement = RawMaterialMovement::create([
                'batch_id'       => $batch->id,
                'from_store_id'  => $data['from_store_id'],
                'to_store_id'    => $data['to_store_id'],
                'from_worker_id' => null,
                'to_worker_id'   => $data['worker_id'],
                'moved_by'       => $movedBy,
                'movement_type'  => 'create',
                'quantity'       => $data['quantity'],
                'created_at'     => $createdAt,
                'updated_at'     => $createdAt,
            ]);

            // Обновление остатков в БД
            $this->adjustStock($data['product_id'], $data['from_store_id'], -$data['quantity']);
            $this->adjustStock($data['product_id'], $data['to_store_id'], +$data['quantity']);

            // Создание перемещения в МойСклад
            $this->createMoveInMoySklad($data, $batch, $movement);
        });

        if ($request->input('and_reception')) {
            return redirect()->route('stone-receptions.create', [
                'cutter_id'             => $data['worker_id'],
                'raw_material_batch_id' => $batch->id,
            ])->with('success', 'Партия создана. Оформите приёмку.');
        }

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

            $batchNumber = $data['batch_number'] ?? $batch->id;
            $moveData = [
                'from_store_id' => $data['from_store_id'],
                'to_store_id'   => $data['to_store_id'],
                'products'      => [
                    [
                        'product_id' => $product->moysklad_id,
                        'quantity'   => $data['quantity'],
                    ]
                ],
                'name'        => 'Партия: ' . $batchNumber,
                'description' => 'Автоматическое перемещение из системы',
                'external_id' => 'movement_' . $movement->id,
                'created_at'  => $batch->created_at,
            ];

            $result = $this->moySkladMoveService->createMove($moveData);

            // Коллизия имени — суффикс применяем к номеру партии и обновляем в БД
            if (!$result['success'] && $result['code'] === 'duplicate_name') {
                $batchNumber      = DocumentNaming::nextSuffix($batchNumber);
                $moveData['name'] = 'Партия: ' . $batchNumber;
                $result           = $this->moySkladMoveService->createMove($moveData);

                if ($result['success']) {
                    $batch->update(['batch_number' => $batchNumber]);
                }
            }

            if ($result['success']) {
                $movement->update([
                    'moysklad_move_id' => $result['move_id'],
                    'moysklad_synced'  => true,
                ]);
                Log::info('Перемещение синхронизировано с МойСклад', ['move_id' => $result['move_id']]);
            } else {
                Log::warning('Ошибка синхронизации перемещения с МойСклад', ['error' => $result['message']]);
            }

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error(
                'Исключение при создании перемещения в МойСклад',
                ['error' => $e->getMessage()]
            );
        }
    }

    public function return(Request $request, RawMaterialBatch $batch)
    {
        if (!$batch->canBeTransferredOrReturned()) {
            return back()->withErrors(['batch' => 'Вернуть можно только уточнённую партию с ненулевым остатком.']);
        }

        if (!$batch->current_worker_id) {
            return back()->withErrors(['batch' => 'Партия уже находится на складе.']);
        }

        $data = $request->validate([
            'to_store_id' => 'required|exists:stores,id',
            'quantity'    => 'required|numeric|min:0.001|max:' . $batch->remaining_quantity,
        ]);

        $qty       = (float) $data['quantity'];
        $oldStore  = $batch->current_store_id;
        $oldWorker = $batch->current_worker_id;
        $newBatch  = null;

        DB::transaction(function () use ($batch, $data, $qty, $oldStore, $oldWorker, &$newBatch) {
            $newRemaining = (float) $batch->remaining_quantity - $qty;
            $batch->remaining_quantity = $newRemaining;
            $batch->status = $newRemaining > 0
                ? RawMaterialBatch::STATUS_CONFIRMED
                : RawMaterialBatch::STATUS_IN_WORK;
            $batch->save();

            $newBatch = RawMaterialBatch::create([
                'product_id'         => $batch->product_id,
                'initial_quantity'   => $qty,
                'remaining_quantity' => $qty,
                'current_store_id'   => $data['to_store_id'],
                'current_worker_id'  => null,
                'status'             => RawMaterialBatch::STATUS_RETURNED,
                'notes'              => 'Создана от партии №' . ($batch->batch_number ?? $batch->id),
            ]);

            RawMaterialMovement::create([
                'batch_id'       => $newBatch->id,
                'from_store_id'  => $oldStore,
                'to_store_id'    => $data['to_store_id'],
                'from_worker_id' => $oldWorker,
                'to_worker_id'   => null,
                'moved_by'       => auth()->user()?->worker_id ?? null,
                'movement_type'  => 'return_to_store',
                'quantity'       => $qty,
            ]);

            $this->adjustStock($batch->product_id, $oldStore, -$qty);
            $this->adjustStock($batch->product_id, $data['to_store_id'], +$qty);
        });

        $firstMovement = $newBatch->movements()->first();
        $this->createReturnMoveInMoySklad($newBatch, $firstMovement, $oldStore, $data['to_store_id'], $qty);
        $this->updateParentBatchMove($batch);

        return redirect()->route('raw-batches.show', $batch)
            ->with('success', 'Часть партии возвращена на склад.');
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

    private function updateParentBatchMove(RawMaterialBatch $batch): void
    {
        try {
            $product = $batch->product;
            if (!$product->moysklad_id) {
                return;
            }

            $originalMovement = $batch->movements()
                ->where('movement_type', 'create')
                ->whereNotNull('moysklad_move_id')
                ->first();

            if (!$originalMovement) {
                return;
            }

            $result = $this->moySkladMoveService->updateMove($originalMovement->moysklad_move_id, [
                'from_store_id' => $originalMovement->from_store_id,
                'to_store_id'   => $originalMovement->to_store_id,
                'products'      => [['product_id' => $product->moysklad_id, 'quantity' => (float) $batch->remaining_quantity]],
            ]);

            if (!$result['success']) {
                Log::warning('Ошибка обновления перемещения родительской партии', ['error' => $result['message'], 'batch_id' => $batch->id]);
            }
        } catch (\Exception $e) {
            Log::error('Исключение при обновлении перемещения родительской партии', ['error' => $e->getMessage(), 'batch_id' => $batch->id]);
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
