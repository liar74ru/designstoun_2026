<?php

use App\Models\Department;
use App\Models\Order;
use App\Models\OrderState;
use App\Services\Moysklad\CustomerOrderSyncService;
use App\Services\Moysklad\MoySkladService;
use App\Services\Moysklad\OrderStateSyncService;
use Illuminate\Support\Facades\Http;

function customerOrderSync(): CustomerOrderSyncService
{
    return new CustomerOrderSyncService(new MoySkladService(), new OrderStateSyncService());
}

// ══════════════════════════════════════════════════════════════════════════════
// CustomerOrderSyncService::pullActive()
// ══════════════════════════════════════════════════════════════════════════════

describe('CustomerOrderSyncService::pullActive()', function () {

    test('возвращает ошибку при отсутствии токена', function () {
        config()->set('services.moysklad.token', '');

        $result = customerOrderSync()->pullActive();

        expect($result['success'])->toBeFalse();
        expect($result['message'])->toContain('MOYSKLAD_TOKEN');
    });

    test('возвращает ошибку, когда не отмечено ни одного статуса', function () {
        config()->set('services.moysklad.token', 'test-token');
        Http::fake(['*' => Http::response(['states' => []], 200)]);

        $result = customerOrderSync()->pullActive();

        expect($result['success'])->toBeFalse();
        expect($result['message'])->toContain('Не выбрано ни одного статуса');
    });

    test('статусы берутся из справочника, а не из имён в настройках', function () {
        config()->set('services.moysklad.token', 'test-token');

        OrderState::create([
            'id'         => '11111111-1111-1111-1111-111111111111',
            'name'       => 'В процессе',
            'is_enabled' => true,
        ]);
        OrderState::create([
            'id'         => '22222222-2222-2222-2222-222222222222',
            'name'       => 'Отменен',
            'is_enabled' => false,
        ]);

        // metadata для OrderStateSyncService, затем пустой список заявок
        Http::fake([
            '*/entity/customerorder/metadata' => Http::response([
                'states' => [
                    ['id' => '11111111-1111-1111-1111-111111111111', 'name' => 'В процессе', 'color' => 15280409, 'stateType' => 'Regular'],
                    ['id' => '22222222-2222-2222-2222-222222222222', 'name' => 'Отменен', 'color' => 16711680, 'stateType' => 'Regular'],
                ],
            ], 200),
            '*' => Http::response(['rows' => [], 'meta' => ['size' => 0]], 200),
        ]);

        $result = customerOrderSync()->pullActive();

        expect($result['success'])->toBeTrue();

        // В фильтр ушёл только отмеченный статус
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/entity/customerorder?')) {
                return false;
            }

            return str_contains(urldecode($request->url()), '11111111-1111-1111-1111-111111111111')
                && ! str_contains(urldecode($request->url()), '22222222-2222-2222-2222-222222222222');
        });
    });

    test('заявки, выпавшие из выбранных статусов, удаляются', function () {
        config()->set('services.moysklad.token', 'test-token');
        Department::create(['name' => 'Тест отдел', 'is_active' => true]);
        Order::create(['moysklad_id' => 'old-id-123', 'name' => 'Старая', 'state_name' => 'Новая']);

        OrderState::create([
            'id'         => '11111111-1111-1111-1111-111111111111',
            'name'       => 'В процессе',
            'is_enabled' => true,
        ]);

        Http::fake([
            '*/entity/customerorder/metadata' => Http::response([
                'states' => [
                    ['id' => '11111111-1111-1111-1111-111111111111', 'name' => 'В процессе', 'color' => 15280409, 'stateType' => 'Regular'],
                ],
            ], 200),
            '*' => Http::response(['rows' => [], 'meta' => ['size' => 0]], 200),
        ]);

        $result = customerOrderSync()->pullActive();

        expect($result['success'])->toBeTrue();
        expect(Order::where('moysklad_id', 'old-id-123')->exists())->toBeFalse();
    });
});
