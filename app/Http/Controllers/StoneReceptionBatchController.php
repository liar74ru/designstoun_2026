<?php

namespace App\Http\Controllers;

use App\Models\StoneReception;
use App\Models\Store;
use App\Services\MoySkladProcessingService;
use App\Support\DocumentNaming;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Рефакторинг от 06.04.2026

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
        $request->validate([
            'reception_ids'   => 'required|array|min:1',
            'reception_ids.*' => 'exists:stone_receptions,id',
        ]);

        $receptions = StoneReception::with([
            'items.product',
            'store',
            'rawMaterialBatch.product',
        ])
            ->whereIn('id', $request->input('reception_ids'))
            ->where('status', '!=', StoneReception::STATUS_PROCESSED)
            ->get();

        if ($receptions->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Нет доступных приемок для отправки'], 400);
        }

        // Все приёмки должны быть на одном складе
        $storeId = $receptions->first()->store_id;
        if ($receptions->contains(fn($r) => $r->store_id !== $storeId)) {
            return response()->json(['success' => false, 'message' => 'Все приемки должны быть на одном складе'], 400);
        }

        $store = Store::find($storeId);
        if (!$store) {
            return response()->json(['success' => false, 'message' => 'Склад не найден'], 400);
        }

        $totalProductsQuantity = 0;
        $receptionsData = $receptions->map(function ($reception) use (&$totalProductsQuantity) {
            $items = $reception->items->map(function ($item) use (&$totalProductsQuantity) {
                $totalProductsQuantity += $item->quantity;
                return [
                    'product_id' => $item->product_id,
                    'product'    => $item->product ? [
                        'id'          => $item->product->id,
                        'name'        => $item->product->name,
                        'moysklad_id' => $item->product->moysklad_id,
                    ] : null,
                    'quantity' => $item->quantity,
                ];
            })->toArray();

            return [
                'id'                 => $reception->id,
                'store_id'           => $reception->store_id,
                'items'              => $items,
                'total_quantity'     => $reception->total_quantity,
                'raw_quantity_used'  => $reception->raw_quantity_used,
                'raw_material_batch' => $reception->rawMaterialBatch ? [
                    'id'      => $reception->rawMaterialBatch->id,
                    'product' => $reception->rawMaterialBatch->product ? [
                        'id'          => $reception->rawMaterialBatch->product->id,
                        'name'        => $reception->rawMaterialBatch->product->name,
                        'moysklad_id' => $reception->rawMaterialBatch->product->moysklad_id,
                    ] : null,
                ] : null,
            ];
        })->toArray();

        // Генерируем имя техоперации: ГГ-НН-ТО-ПП
        $weekCount = StoneReception::whereBetween('synced_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->where('status', StoneReception::STATUS_PROCESSED)
            ->count();
        $processingName = DocumentNaming::weeklyName('ТО', $weekCount + 1);

        $result = $this->processingService->createProcessing($receptionsData, $store->id, $processingName);

        // Коллизия имени — повторяем с суффиксом
        if (!$result['success'] && ($result['code'] ?? '') === 'duplicate_name') {
            $processingName = DocumentNaming::nextSuffix($processingName);
            $result = $this->processingService->createProcessing($receptionsData, $store->id, $processingName);
        }

        if (!$result['success']) {
            Log::error('Ошибка отправки в МойСклад:', ['message' => $result['message']]);
            StoneReception::whereIn('id', $receptions->pluck('id'))
                ->update(['status' => StoneReception::STATUS_ERROR]);

            return response()->json(['success' => false, 'message' => $result['message']], 500);
        }

        try {
            DB::transaction(function () use ($receptions, $result) {
                $receptions->each(function ($reception) use ($result) {
                    $reception->update([
                        'status'                 => StoneReception::STATUS_PROCESSED,
                        'moysklad_processing_id' => $result['processing_id'],
                        'synced_at'              => now(),
                    ]);
                });
            });
        } catch (\Exception $e) {
            Log::error('Ошибка при сохранении статусов приёмок:', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Ошибка при сохранении статусов: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'success'         => true,
            'message'         => 'Приемки успешно отправлены в техоперацию',
            'processing_id'   => $result['processing_id'],
            'processed_count' => $receptions->count(),
            'total_quantity'  => $totalProductsQuantity,
        ]);
    }
}
