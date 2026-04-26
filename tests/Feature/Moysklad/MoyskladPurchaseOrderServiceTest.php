<?php

use App\Models\Counterparty;
use App\Models\Product;
use App\Models\Store;
use App\Models\SupplierOrder;
use App\Services\Moysklad\MoySkladPurchaseOrderService;

// ══════════════════════════════════════════════════════════════════════════════
// MoySkladPurchaseOrderService — createPurchaseOrder()
// ══════════════════════════════════════════════════════════════════════════════

describe('MoySkladPurchaseOrderService::createPurchaseOrder()', function () {

    test('возвращает ошибку когда токен пустой', function () {
        config()->set('services.moysklad.token', '');

        $service = new MoySkladPurchaseOrderService();
        $result = $service->createPurchaseOrder(new SupplierOrder());

        expect($result['success'])->toBeFalse();
    });
});

// ══════════════════════════════════════════════════════════════════════════════════════
// MoySkladPurchaseOrderService — updatePurchaseOrder()
// ══════════════════════════════════════════════════════════════════════════════

describe('MoySkladPurchaseOrderService::updatePurchaseOrder()', function () {

    test('возвращает ошибку когда токен пустой', function () {
        config()->set('services.moysklad.token', '');

        $service = new MoySkladPurchaseOrderService();
        $result = $service->updatePurchaseOrder(new SupplierOrder());

        expect($result['success'])->toBeFalse();
    });

    describe('MoySkladPurchaseOrderService::updatePurchaseOrder()', function () {

    test('возвращает ошибку когда токен пустой', function () {
        config()->set('services.moysklad.token', '');

        $service = new MoySkladPurchaseOrderService();
        $result = $service->updatePurchaseOrder(new SupplierOrder());

        expect($result['success'])->toBeFalse();
    });
});
});

// ══════════════════��═══════════════════════════════════════════════════════════
// MoySkladPurchaseOrderService — checkExists()
// ══════════════════════════════════════════════════════════════════════════════

describe('MoySkladPurchaseOrderService::checkExists()', function () {

    test('возвращает true когда заказ существует', function () {
        config()->set('services.moysklad.token', 'test-token');

        Http::fake([
            '*' => Http::response(['id' => 'order-001'], 200),
        ]);

        $service = new MoySkladPurchaseOrderService();
        $result = $service->checkExists('order-001');

        expect($result)->toBeTrue();
    });

    test('возвращает false когда заказ не найден', function () {
        config()->set('services.moysklad.token', 'test-token');

        Http::fake([
            '*' => Http::response(null, 404),
        ]);

        $service = new MoySkladPurchaseOrderService();
        $result = $service->checkExists('unknown');

        expect($result)->toBeFalse();
    });
});