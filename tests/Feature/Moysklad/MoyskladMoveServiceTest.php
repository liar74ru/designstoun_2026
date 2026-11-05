<?php

use App\Services\Moysklad\MoySkladMoveService;

// ══════════════════════════════════════════════════════════════════════════════
// MoySkladMoveService — createMove()
// ══════════════════════════════════════════════════════════════════════════════

describe('MoySkladMoveService::createMove()', function () {

    test('возвращает ошибку когда токен пустой', function () {
        config()->set('services.moysklad.token', '');

        $service = new MoySkladMoveService();
        $result = $service->createMove([
            'from_store_id' => '00000000-0000-4000-8000-000000000001',
            'to_store_id' => '00000000-0000-4000-8000-000000000002',
            'products' => [],
        ]);

        expect($result['success'])->toBeFalse();
        expect($result['message'])->toContain('токен');
    });

    test('возвращает ошибку при пустом списке товаров', function () {
        config()->set('services.moysklad.token', 'test-token');

        $service = new MoySkladMoveService();
        $result = $service->createMove([
            'from_store_id' => '00000000-0000-4000-8000-000000000001',
            'to_store_id' => '00000000-0000-4000-8000-000000000002',
            'products' => [],
        ]);

        expect($result['success'])->toBeFalse();
    });
});

// ══════════════════════════════════════════════════════════════════════════════════════
// MoySkladMoveService — updateMove()
// ══════════════════════════════════════════════════════════════════════════════

describe('MoySkladMoveService::updateMove()', function () {

    test('возвращает ошибку когда токен пустой', function () {
        config()->set('services.moysklad.token', '');

        $service = new MoySkladMoveService();
        $result = $service->updateMove('move-001', [
            'from_store_id' => '00000000-0000-4000-8000-000000000001',
            'to_store_id' => '00000000-0000-4000-8000-000000000002',
            'products' => [],
        ]);

        expect($result['success'])->toBeFalse();
    });

    test('возвращает ошибку при пустом списке товаров', function () {
        config()->set('services.moysklad.token', 'test-token');

        $service = new MoySkladMoveService();
        $result = $service->updateMove('move-001', [
            'from_store_id' => '00000000-0000-4000-8000-000000000001',
            'to_store_id' => '00000000-0000-4000-8000-000000000002',
            'products' => [],
        ]);

        expect($result['success'])->toBeFalse();
    });
});

// ══════════════════════════════════════════════════════════════════════════════════════
// MoySkladMoveService — deleteMove()
// ══════════════════════════════════════════════════════════════════════════════

describe('MoySkladMoveService::deleteMove()', function () {

    test('успешно удаляет перемещение', function () {
        config()->set('services.moysklad.token', 'test-token');

        Http::fake([
            '*' => Http::response(null, 204),
        ]);

        $service = new MoySkladMoveService();
        $result = $service->deleteMove('move-001');

        expect($result['success'])->toBeTrue();
        expect($result['message'])->toContain('удалено');
    });
});
