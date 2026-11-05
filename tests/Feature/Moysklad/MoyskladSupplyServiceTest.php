<?php

use App\Models\Counterparty;
use App\Models\Store;
use App\Models\SupplierOrder;
use App\Services\Moysklad\MoySkladSupplyService;

// ══════════════════════════════════════════════════════════════════════════════
// MoySkladSupplyService — createSupply()
// ══════════════════════════════════════════════════════════════════════════════════════

describe('MoySkladSupplyService::createSupply()', function () {

    test('возвращает ошибку когда токен пустой', function () {
        config()->set('services.moysklad.token', '');

        $service = new MoySkladSupplyService();
        $result = $service->createSupply(new SupplierOrder());

        expect($result['success'])->toBeFalse();
        expect($result['message'])->toContain('токен');
    });

    test('возвращает ошибку когда заказ не синхронизирован', function () {
        config()->set('services.moysklad.token', 'test-token');

        $store = Store::create(['id' => '00000000-0000-4000-8000-000000000001', 'name' => 'Склад']);
        $counterparty = Counterparty::create(['moysklad_id' => 'counter-001', 'name' => 'Поставщик']);
        $order = SupplierOrder::create([
            'number' => 'ЗАКАЗ-001',
            'store_id' => '00000000-0000-4000-8000-000000000001',
            'counterparty_id' => $counterparty->id,
            'moysklad_id' => null,
        ]);

        $service = new MoySkladSupplyService();
        $result = $service->createSupply($order);

        expect($result['success'])->toBeFalse();
        expect($result['message'])->toContain('не синхронизирован');
    });
});
