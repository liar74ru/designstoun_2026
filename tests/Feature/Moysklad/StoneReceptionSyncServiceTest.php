<?php

use App\Models\Setting;
use App\Services\Moysklad\StoneReceptionSyncService;

// ══════════════════════════════════════════════════════════════════════════════
// StoneReceptionSyncService — manualCostPerUnit()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionSyncService::manualCostPerUnit()', function () {

    test('суммирует все накладные расходы (без PACKAGING_COST — он учитывается в техоперации упаковки)', function () {
        Setting::set('BLADE_WEAR', 100);
        Setting::set('RECEPTION_COST', 50);
        Setting::set('PACKAGING_COST', 30); // не входит в формулу приёмки
        Setting::set('WASTE_REMOVAL', 20);
        Setting::set('ELECTRICITY', 80);
        Setting::set('PPE_COST', 40);
        Setting::set('FORKLIFT_COST', 60);
        Setting::set('MACHINE_COST', 70);
        Setting::set('RENT_COST', 90);
        Setting::set('OTHER_COSTS', 10);

        $service = new StoneReceptionSyncService();
        $result = $service->manualCostPerUnit();

        // 100 + 50 + 20 + 80 + 40 + 60 + 70 + 90 + 10 = 520 (без PACKAGING_COST 30)
        expect($result)->toBe(520.0);
    });

    test('возвращает 0 когда ничего не задано', function () {
        Setting::whereIn('key', [
            'BLADE_WEAR', 'RECEPTION_COST', 'PACKAGING_COST', 'WASTE_REMOVAL',
            'ELECTRICITY', 'PPE_COST', 'FORKLIFT_COST', 'MACHINE_COST',
            'RENT_COST', 'OTHER_COSTS',
        ])->delete();

        $service = new StoneReceptionSyncService();
        $result = $service->manualCostPerUnit();

        expect($result)->toBe(0.0);
    });

    test('корректно обрабатывает строковые значения', function () {
        Setting::set('BLADE_WEAR', '100.50');
        Setting::set('RECEPTION_COST', '200.25');

        $service = new StoneReceptionSyncService();
        $result = $service->manualCostPerUnit();

        expect($result)->toBe(300.75);
    });

    test('использует значения по умолчанию для отсутствующих ключей', function () {
        Setting::whereIn('key', [
            'BLADE_WEAR', 'RECEPTION_COST', 'PACKAGING_COST', 'WASTE_REMOVAL',
            'ELECTRICITY', 'PPE_COST', 'FORKLIFT_COST', 'MACHINE_COST',
            'RENT_COST', 'OTHER_COSTS',
        ])->delete();

        $service = new StoneReceptionSyncService();
        $result = $service->manualCostPerUnit();

        expect($result)->toBe(0.0);
    });
});