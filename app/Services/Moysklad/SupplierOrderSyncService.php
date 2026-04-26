<?php

namespace App\Services\Moysklad;

use App\Models\SupplierOrder;
use App\Services\Moysklad\MoySkladPurchaseOrderService;
use App\Services\Moysklad\MoySkladSupplyService;
use App\Services\Moysklad\StockSyncService;
use App\Support\DocumentNaming;

class SupplierOrderSyncService
{
    public function __construct(
        private MoySkladPurchaseOrderService $purchaseOrderService,
        private MoySkladSupplyService $supplyService,
        private StockSyncService $stockSyncService,
    ) {}

    public function syncOrderToMoysklad(SupplierOrder $order, ?string $name = null): array
    {
        $order->loadMissing(['counterparty', 'store', 'items.product']);

        $result = $this->purchaseOrderService->createPurchaseOrder($order, $name);

        if (!$result['success'] && $result['code'] === 'duplicate_name') {
            $newNumber = DocumentNaming::nextSuffix($order->number);
            $order->update(['number' => $newNumber]);
            $result = $this->purchaseOrderService->createPurchaseOrder($order, $newNumber);
        }

        if ($result['success']) {
            $order->update([
                'moysklad_id' => $result['moysklad_id'],
                'status'      => SupplierOrder::STATUS_NEW,
                'sync_error'  => null,
            ]);
            return ['success' => true];
        }

        $order->update([
            'status'     => SupplierOrder::STATUS_ERROR,
            'sync_error' => $result['message'],
        ]);
        return ['success' => false, 'message' => $result['message']];
    }

    public function updateOrderInMoysklad(SupplierOrder $order): array
    {
        $order->loadMissing(['counterparty', 'store', 'items.product']);

        $result = $this->purchaseOrderService->updatePurchaseOrder($order);

        if ($result['success']) {
            if ($order->status === SupplierOrder::STATUS_ERROR) {
                $order->update(['status' => SupplierOrder::STATUS_NEW, 'sync_error' => null]);
            } else {
                $order->update(['sync_error' => null]);
            }
            return ['success' => true];
        }

        $order->update([
            'status'     => SupplierOrder::STATUS_ERROR,
            'sync_error' => $result['message'],
        ]);
        return ['success' => false, 'message' => $result['message']];
    }

    public function deleteOrderFromMoysklad(string $moyskladId): array
    {
        $result = $this->purchaseOrderService->deletePurchaseOrder($moyskladId);
        return ['success' => $result['success'], 'message' => $result['message'] ?? ''];
    }

    public function initiateSync(SupplierOrder $order): array
    {
        $order->loadMissing(['counterparty', 'store', 'items.product']);

        if (!$order->moysklad_id) {
            return ['status' => 'confirm_needed', 'issue' => 'order_not_created', 'suggested_name' => null];
        }

        if (!$this->purchaseOrderService->checkExists($order->moysklad_id)) {
            return ['status' => 'confirm_needed', 'issue' => 'order_missing', 'suggested_name' => null];
        }

        $result = $this->supplyService->createSupply($order);

        if ($result['success']) {
            $order->update([
                'supply_moysklad_id' => $result['supply_moysklad_id'],
                'status'             => SupplierOrder::STATUS_SENT,
                'sync_error'         => null,
            ]);
            $this->syncSuppliedProductStocks($order);
            return ['status' => 'success'];
        }

        if ($result['code'] === 'duplicate_name') {
            return [
                'status'         => 'confirm_needed',
                'issue'          => 'duplicate_supply',
                'suggested_name' => DocumentNaming::nextSuffix($order->number),
            ];
        }

        $order->update(['sync_error' => $result['message']]);
        return ['status' => 'error', 'message' => $result['message']];
    }

    public function forceSync(SupplierOrder $order, string $mode, ?string $suggestedName = null): array
    {
        $order->loadMissing(['counterparty', 'store', 'items.product']);

        return match ($mode) {
            'create_order_only' => $this->createOrderOnly($order),
            'recreate'          => $this->recreateOrderAndSupply($order),
            'suffix_supply'     => $this->createSuffixSupply($order, $suggestedName),
            default             => ['status' => 'cancelled'],
        };
    }

    private function createPurchaseOrderWithFallback(SupplierOrder $order, string $name): array
    {
        $result = $this->purchaseOrderService->createPurchaseOrder($order, $name);
        if (!$result['success'] && $result['code'] === 'duplicate_name') {
            $name   = DocumentNaming::nextSuffix($name);
            $result = $this->purchaseOrderService->createPurchaseOrder($order, $name);
        }
        return ['result' => $result, 'name' => $name];
    }

    private function createOrderOnly(SupplierOrder $order): array
    {
        ['result' => $poResult, 'name' => $poName] = $this->createPurchaseOrderWithFallback($order, $order->number);

        if (!$poResult['success']) {
            $order->update(['sync_error' => $poResult['message']]);
            return ['status' => 'error', 'message' => $poResult['message']];
        }

        $order->update([
            'moysklad_id' => $poResult['moysklad_id'],
            'number'      => $poName,
            'status'      => SupplierOrder::STATUS_NEW,
            'sync_error'  => null,
        ]);

        return ['status' => 'success', 'number' => $poName];
    }

    private function recreateOrderAndSupply(SupplierOrder $order): array
    {
        ['result' => $poResult, 'name' => $poName] = $this->createPurchaseOrderWithFallback($order, $order->number);

        if (!$poResult['success']) {
            $order->update(['sync_error' => $poResult['message']]);
            return ['status' => 'error', 'message' => "Не удалось создать Заказ поставщику в МойСклад: {$poResult['message']}"];
        }

        $order->update([
            'moysklad_id' => $poResult['moysklad_id'],
            'number'      => $poName,
            'sync_error'  => null,
        ]);
        $order->refresh();

        $supplyName   = $poName;
        $supplyResult = $this->supplyService->createSupply($order, $supplyName);

        if (!$supplyResult['success'] && $supplyResult['code'] === 'duplicate_name') {
            $supplyName   = DocumentNaming::nextSuffix($supplyName);
            $supplyResult = $this->supplyService->createSupply($order, $supplyName);
        }

        if (!$supplyResult['success']) {
            $order->update(['sync_error' => $supplyResult['message']]);
            return ['status' => 'error', 'message' => "Заказ поставщику создан, но не удалось создать Приёмку: {$supplyResult['message']}"];
        }

        $order->update([
            'supply_moysklad_id' => $supplyResult['supply_moysklad_id'],
            'number'             => $supplyName,
            'status'             => SupplierOrder::STATUS_SENT,
            'sync_error'         => null,
        ]);

        return ['status' => 'success', 'number' => $order->fresh()->number];
    }

    private function createSuffixSupply(SupplierOrder $order, ?string $suggestedName): array
    {
        $supplyName   = $suggestedName ?: DocumentNaming::nextSuffix($order->number);
        $supplyResult = $this->supplyService->createSupply($order, $supplyName);

        if (!$supplyResult['success']) {
            $order->update(['sync_error' => $supplyResult['message']]);
            return ['status' => 'error', 'message' => $supplyResult['message']];
        }

        $order->update([
            'supply_moysklad_id' => $supplyResult['supply_moysklad_id'],
            'number'             => $supplyName,
            'status'             => SupplierOrder::STATUS_SENT,
            'sync_error'         => null,
        ]);

        return ['status' => 'success', 'name' => $supplyName];
    }

    private function syncSuppliedProductStocks(SupplierOrder $order): void
    {
        foreach ($order->items as $item) {
            $moyskladId = $item->product?->moysklad_id;
            if ($moyskladId) {
                $this->stockSyncService->updateProductStocksByMoyskladId($moyskladId);
            }
        }
    }
}
