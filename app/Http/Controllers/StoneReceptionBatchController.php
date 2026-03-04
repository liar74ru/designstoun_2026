<?php

namespace App\Http\Controllers;

use App\Models\StoneReception;
use App\Models\Store;
use App\Services\MoySkladProcessingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StoneReceptionBatchController extends Controller
{
    private $processingService;

    public function __construct(MoySkladProcessingService $processingService)
    {
        $this->processingService = $processingService;
    }

    /**
     * Отправить выбранные приемки в техоперацию МойСклад
     */
    public function sendToProcessing(Request $request)
    {
        try {
            Log::info('=== НАЧАЛО ОТПРАВКИ ПРИЕМОК ===');
            Log::info('Request data:', $request->all());

            // Валидация
            try {
                $request->validate([
                    'reception_ids' => 'required|array|min:1',
                    'reception_ids.*' => 'exists:stone_receptions,id'
                ]);
            } catch (\Illuminate\Validation\ValidationException $e) {
                Log::error('Ошибка валидации:', $e->errors());
                throw $e;
            }

            $receptionIds = $request->input('reception_ids');
            Log::info('ID приемок:', ['ids' => $receptionIds]);

            // Загружаем приемки с необходимыми связями
            $receptions = StoneReception::with([
                'items.product',
                'store',
                'rawMaterialBatch.product' // Загружаем партию сырья и продукт
            ])
                ->whereIn('id', $receptionIds)
                ->where('status', '!=', StoneReception::STATUS_PROCESSED)
                ->get();

            Log::info('Найдено приемок:', [
                'count' => $receptions->count(),
                'statuses' => $receptions->pluck('status', 'id')->toArray()
            ]);

            if ($receptions->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нет доступных приемок для отправки'
                ], 400);
            }

            // Проверяем склад
            $storeId = $receptions->first()->store_id;
            Log::info('ID склада:', ['store_id' => $storeId]);

            foreach ($receptions as $reception) {
                if ($reception->store_id !== $storeId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Все приемки должны быть на одном складе'
                    ], 400);
                }
            }

            // Получаем склад
            $store = Store::find($storeId);
            if (!$store) {
                Log::error('Склад не найден', ['store_id' => $storeId]);
                return response()->json([
                    'success' => false,
                    'message' => 'Склад не найден'
                ], 400);
            }

            Log::info('Склад найден:', [
                'id' => $store->id,
                'name' => $store->name
            ]);

            // Подготавливаем данные для сервиса
            $receptionsData = [];
            $totalProductsQuantity = 0; // Для расчета processingSum

            foreach ($receptions as $reception) {
                $items = [];
                foreach ($reception->items as $item) {
                    $items[] = [
                        'product_id' => $item->product_id,
                        'product' => $item->product ? [
                            'id' => $item->product->id,
                            'name' => $item->product->name,
                            'moysklad_id' => $item->product->moysklad_id
                        ] : null,
                        'quantity' => $item->quantity
                    ];
                    $totalProductsQuantity += $item->quantity;
                }

                $receptionsData[] = [
                    'id' => $reception->id,
                    'store_id' => $reception->store_id,
                    'items' => $items,
                    'total_quantity' => $reception->total_quantity,
                    'raw_quantity_used' => $reception->raw_quantity_used,
                    'raw_material_batch' => $reception->rawMaterialBatch ? [
                        'id' => $reception->rawMaterialBatch->id,
                        'product' => $reception->rawMaterialBatch->product ? [
                            'id' => $reception->rawMaterialBatch->product->id,
                            'name' => $reception->rawMaterialBatch->product->name,
                            'moysklad_id' => $reception->rawMaterialBatch->product->moysklad_id
                        ] : null
                    ] : null
                ];
            }

            Log::info('Данные для отправки:', [
                'receptions_count' => count($receptionsData),
                'total_products_quantity' => $totalProductsQuantity
            ]);

            // Создаем техоперацию
            $result = $this->processingService->createProcessing(
                $receptionsData,
                $store->id
            );

            Log::info('Результат от сервиса:', ['result' => $result]);

            if (!$result['success']) {
                // Отмечаем ошибку в приемках
                foreach ($receptions as $reception) {
                    $reception->status = StoneReception::STATUS_ERROR;
                    $reception->save();
                }

                return response()->json([
                    'success' => false,
                    'message' => $result['message']
                ], 500);
            }

            // Обновляем статусы приемок
            DB::beginTransaction();
            try {
                foreach ($receptions as $reception) {
                    $reception->status = StoneReception::STATUS_PROCESSED;
                    $reception->moysklad_processing_id = $result['processing_id'];
                    $reception->synced_at = now();
                    $reception->save();
                }

                DB::commit();

                Log::info('Приемки успешно отправлены', [
                    'processing_id' => $result['processing_id'],
                    'count' => $receptions->count(),
                    'total_quantity' => $totalProductsQuantity
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Приемки успешно отправлены в техоперацию',
                    'processing_id' => $result['processing_id'],
                    'processed_count' => $receptions->count(),
                    'total_quantity' => $totalProductsQuantity
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Ошибка при сохранении статусов:', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка при сохранении статусов: ' . $e->getMessage()
                ], 500);
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Ошибка валидации:', ['errors' => $e->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('КРИТИЧЕСКАЯ ОШИБКА:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка сервера: ' . $e->getMessage()
            ], 500);
        }
    }
}
