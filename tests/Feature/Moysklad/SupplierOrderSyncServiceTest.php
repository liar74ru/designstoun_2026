<?php

use App\Models\Counterparty;
use App\Models\Product;
use App\Models\Store;
use App\Models\SupplierOrder;
use App\Services\Moysklad\MoySkladPurchaseOrderService;
use App\Services\Moysklad\MoySkladSupplyService;
use App\Services\Moysklad\StockSyncService;
use App\Services\Moysklad\SupplierOrderSyncService;

// ══════════════════════════════════════════════════════════════════════════════
// SupplierOrderSyncService::syncOrderToMoysklad()
// ══════════════════════════════════════════════════════════════════════════════

describe('SupplierOrderSyncService::syncOrderToMoysklad()', function () {

    test('обновляет заказ с moysklad_id при успешной синхронизации', function () {
        $counterparty = Counterparty::create(['name' => 'Поставщик', 'moysklad_id' => 'cp-1']);
        $store = Store::factory()->create();
        $order = SupplierOrder::create([
            'counterparty_id' => $counterparty->id,
            'store_id' => $store->id,
            'number' => '001',
            'moysklad_id' => null,
        ]);
        $order->loadMissing(['counterparty', 'store', 'items.product']);

        $mockPurchaseOrder = mock(MoySkladPurchaseOrderService::class)
            ->shouldReceive('createPurchaseOrder')
            ->andReturn(['success' => true, 'moysklad_id' => 'mo-123'])
            ->getMock();

        $mockSupply = mock(MoySkladSupplyService::class);
        $mockStock = mock(StockSyncService::class);

        $service = new SupplierOrderSyncService($mockPurchaseOrder, $mockSupply, $mockStock);
        $result = $service->syncOrderToMoysklad($order);

        expect($result['success'])->toBeTrue();
        expect($order->fresh()->moysklad_id)->toBe('mo-123');
        expect($order->fresh()->status)->toBe(SupplierOrder::STATUS_NEW);
        expect($order->fresh()->sync_error)->toBeNull();
    });

    test('пытается снова с измененным номером при коллизии имени', function () {
        $counterparty = Counterparty::create(['name' => 'Поставщик', 'moysklad_id' => 'cp-1']);
        $store = Store::factory()->create();
        $order = SupplierOrder::create([
            'counterparty_id' => $counterparty->id,
            'store_id' => $store->id,
            'number' => '001',
            'moysklad_id' => null,
        ]);
        $order->loadMissing(['counterparty', 'store', 'items.product']);

        $mockPurchaseOrder = mock(MoySkladPurchaseOrderService::class)
            ->shouldReceive('createPurchaseOrder')
            ->andReturn(['success' => false, 'code' => 'duplicate_name'])
            ->once()
            ->andReturn(['success' => true, 'moysklad_id' => 'mo-123'])
            ->once()
            ->getMock();

        $mockSupply = mock(MoySkladSupplyService::class);
        $mockStock = mock(StockSyncService::class);

        $service = new SupplierOrderSyncService($mockPurchaseOrder, $mockSupply, $mockStock);
        $result = $service->syncOrderToMoysklad($order);

        expect($result['success'])->toBeTrue();
    });

    test('устанавливает статус error и ошибку при неудаче', function () {
        $counterparty = Counterparty::create(['name' => 'Поставщик', 'moysklad_id' => 'cp-1']);
        $store = Store::factory()->create();
        $order = SupplierOrder::create([
            'counterparty_id' => $counterparty->id,
            'store_id' => $store->id,
            'number' => '001',
            'moysklad_id' => null,
        ]);
        $order->loadMissing(['counterparty', 'store', 'items.product']);

        $mockPurchaseOrder = mock(MoySkladPurchaseOrderService::class)
            ->shouldReceive('createPurchaseOrder')
            ->andReturn(['success' => false, 'code' => 'api_error', 'message' => 'API ошибка'])
            ->getMock();

        $mockSupply = mock(MoySkladSupplyService::class);
        $mockStock = mock(StockSyncService::class);

        $service = new SupplierOrderSyncService($mockPurchaseOrder, $mockSupply, $mockStock);
        $result = $service->syncOrderToMoysklad($order);

        expect($result['success'])->toBeFalse();
        expect($order->fresh()->status)->toBe(SupplierOrder::STATUS_ERROR);
        expect($order->fresh()->sync_error)->toContain('API ошибка');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// SupplierOrderSyncService::updateOrderInMoysklad()
// ══════════════════════════════════════════════════════════════════════════════

describe('SupplierOrderSyncService::updateOrderInMoysklad()', function () {

    test('очищает sync_error при успешном обновлении', function () {
        $counterparty = Counterparty::create(['name' => 'Поставщик', 'moysklad_id' => 'cp-1']);
        $store = Store::factory()->create();
        $order = SupplierOrder::create([
            'counterparty_id' => $counterparty->id,
            'store_id' => $store->id,
            'number' => '001',
            'moysklad_id' => 'mo-123',
            'sync_error' => 'Предыдущая ошибка',
        ]);
        $order->loadMissing(['counterparty', 'store', 'items.product']);

        $mockPurchaseOrder = mock(MoySkladPurchaseOrderService::class)
            ->shouldReceive('updatePurchaseOrder')
            ->andReturn(['success' => true])
            ->getMock();

        $mockSupply = mock(MoySkladSupplyService::class);
        $mockStock = mock(StockSyncService::class);

        $service = new SupplierOrderSyncService($mockPurchaseOrder, $mockSupply, $mockStock);
        $result = $service->updateOrderInMoysklad($order);

        expect($result['success'])->toBeTrue();
        expect($order->fresh()->sync_error)->toBeNull();
    });

    test('возвращает статус в new при обновлении из error', function () {
        $counterparty = Counterparty::create(['name' => 'Поставщик', 'moysklad_id' => 'cp-1']);
        $store = Store::factory()->create();
        $order = SupplierOrder::create([
            'counterparty_id' => $counterparty->id,
            'store_id' => $store->id,
            'number' => '001',
            'moysklad_id' => 'mo-123',
            'status' => SupplierOrder::STATUS_ERROR,
            'sync_error' => 'Была ошибка',
        ]);
        $order->loadMissing(['counterparty', 'store', 'items.product']);

        $mockPurchaseOrder = mock(MoySkladPurchaseOrderService::class)
            ->shouldReceive('updatePurchaseOrder')
            ->andReturn(['success' => true])
            ->getMock();

        $mockSupply = mock(MoySkladSupplyService::class);
        $mockStock = mock(StockSyncService::class);

        $service = new SupplierOrderSyncService($mockPurchaseOrder, $mockSupply, $mockStock);
        $service->updateOrderInMoysklad($order);

        expect($order->fresh()->status)->toBe(SupplierOrder::STATUS_NEW);
    });

    test('устанавливает статус error при неудаче обновления', function () {
        $counterparty = Counterparty::create(['name' => 'Поставщик', 'moysklad_id' => 'cp-1']);
        $store = Store::factory()->create();
        $order = SupplierOrder::create([
            'counterparty_id' => $counterparty->id,
            'store_id' => $store->id,
            'number' => '001',
            'moysklad_id' => 'mo-123',
        ]);
        $order->loadMissing(['counterparty', 'store', 'items.product']);

        $mockPurchaseOrder = mock(MoySkladPurchaseOrderService::class)
            ->shouldReceive('updatePurchaseOrder')
            ->andReturn(['success' => false, 'message' => 'Ошибка обновления'])
            ->getMock();

        $mockSupply = mock(MoySkladSupplyService::class);
        $mockStock = mock(StockSyncService::class);

        $service = new SupplierOrderSyncService($mockPurchaseOrder, $mockSupply, $mockStock);
        $result = $service->updateOrderInMoysklad($order);

        expect($result['success'])->toBeFalse();
        expect($order->fresh()->status)->toBe(SupplierOrder::STATUS_ERROR);
        expect($order->fresh()->sync_error)->toContain('Ошибка обновления');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// SupplierOrderSyncService::deleteOrderFromMoysklad()
// ══════════════════════════════════════════════════════════════════════════════

describe('SupplierOrderSyncService::deleteOrderFromMoysklad()', function () {

    test('возвращает результат удаления от purchaseOrderService', function () {
        $mockPurchaseOrder = mock(MoySkladPurchaseOrderService::class)
            ->shouldReceive('deletePurchaseOrder')
            ->with('mo-123')
            ->andReturn(['success' => true, 'message' => ''])
            ->getMock();

        $mockSupply = mock(MoySkladSupplyService::class);
        $mockStock = mock(StockSyncService::class);

        $service = new SupplierOrderSyncService($mockPurchaseOrder, $mockSupply, $mockStock);
        $result = $service->deleteOrderFromMoysklad('mo-123');

        expect($result['success'])->toBeTrue();
    });

    test('возвращает ошибку при неудаче удаления', function () {
        $mockPurchaseOrder = mock(MoySkladPurchaseOrderService::class)
            ->shouldReceive('deletePurchaseOrder')
            ->andReturn(['success' => false, 'message' => 'Не найден'])
            ->getMock();

        $mockSupply = mock(MoySkladSupplyService::class);
        $mockStock = mock(StockSyncService::class);

        $service = new SupplierOrderSyncService($mockPurchaseOrder, $mockSupply, $mockStock);
        $result = $service->deleteOrderFromMoysklad('mo-123');

        expect($result['success'])->toBeFalse();
        expect($result['message'])->toContain('Не найден');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// SupplierOrderSyncService::forceSync() — остатки после создания приёмки
// ══════════════════════════════════════════════════════════════════════════════

describe('SupplierOrderSyncService::forceSync() — остатки', function () {

    beforeEach(function () {
        $counterparty = Counterparty::create(['name' => 'Поставщик', 'moysklad_id' => 'cp-1']);
        $this->order  = SupplierOrder::create([
            'counterparty_id' => $counterparty->id,
            'store_id'        => Store::factory()->create()->id,
            'number'          => '001',
            'moysklad_id'     => 'mo-1',
        ]);
    });

    test('recreate после создания приёмки обновляет остатки', function () {
        $mockPurchaseOrder = mock(MoySkladPurchaseOrderService::class)
            ->shouldReceive('createPurchaseOrder')
            ->andReturn(['success' => true, 'moysklad_id' => 'mo-2'])
            ->getMock();
        $mockSupply = mock(MoySkladSupplyService::class)
            ->shouldReceive('createSupply')
            ->andReturn(['success' => true, 'supply_moysklad_id' => 'sup-1'])
            ->getMock();
        $mockStock = mock(StockSyncService::class)
            ->shouldReceive('refreshProducts')->once()
            ->getMock();

        $service = new SupplierOrderSyncService($mockPurchaseOrder, $mockSupply, $mockStock);
        $result  = $service->forceSync($this->order, 'recreate');

        expect($result['status'])->toBe('success');
    });

    test('suffix_supply после создания приёмки обновляет остатки', function () {
        $mockSupply = mock(MoySkladSupplyService::class)
            ->shouldReceive('createSupply')
            ->andReturn(['success' => true, 'supply_moysklad_id' => 'sup-1'])
            ->getMock();
        $mockStock = mock(StockSyncService::class)
            ->shouldReceive('refreshProducts')->once()
            ->getMock();

        $service = new SupplierOrderSyncService(mock(MoySkladPurchaseOrderService::class), $mockSupply, $mockStock);
        $result  = $service->forceSync($this->order, 'suffix_supply', '001-1');

        expect($result['status'])->toBe('success');
    });

    test('при ошибке создания приёмки остатки не обновляются', function () {
        $mockSupply = mock(MoySkladSupplyService::class)
            ->shouldReceive('createSupply')
            ->andReturn(['success' => false, 'code' => 'api_error', 'message' => 'Сбой'])
            ->getMock();
        $mockStock = mock(StockSyncService::class)
            ->shouldNotReceive('refreshProducts')
            ->getMock();

        $service = new SupplierOrderSyncService(mock(MoySkladPurchaseOrderService::class), $mockSupply, $mockStock);
        $result  = $service->forceSync($this->order, 'suffix_supply', '001-1');

        expect($result['status'])->toBe('error');
    });
});
