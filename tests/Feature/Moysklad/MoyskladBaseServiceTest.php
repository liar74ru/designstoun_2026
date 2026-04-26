<?php

use App\Services\Moysklad\MoySkladService;

// ══════════════════════════════════════════════════════════════════════════════
// MoySkladBaseService — hasCredentials()
// ══════════════════════════════════════════════════════════════════════════════

describe('MoySkladBaseService::hasCredentials()', function () {

    test('возвращает true когда токен установлен', function () {
        config()->set('services.moysklad.token', 'test-token-123');

        $service = new MoySkladService();
        expect($service->hasCredentials())->toBeTrue();
    });

    test('возвращает false когда токен пустой', function () {
        config()->set('services.moysklad.token', '');

        $service = new MoySkladService();
        expect($service->hasCredentials())->toBeFalse();
    });

    test('возвращает false когда токен не установлен', function () {
        config()->set('services.moysklad.token', '');

        $service = new MoySkladService();
        expect($service->hasCredentials())->toBeFalse();
    });
});