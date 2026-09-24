<?php

use App\Models\Department;
use App\Models\Order;
use App\Models\OrderPositionSetting;
use App\Models\OrderState;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Store;
use App\Services\Moysklad\CustomerOrderSyncService;
use App\Services\Moysklad\MoySkladService;
use App\Services\Moysklad\OrderStateSyncService;
use App\Services\OrderProductionService;
use Illuminate\Support\Facades\Http;

const SYNC_PROD_STATE = '55555555-5555-5555-5555-555555555555';
const SYNC_IDLE_STATE = '66666666-6666-6666-6666-666666666666';

function customerOrderSync(): CustomerOrderSyncService
{
    return new CustomerOrderSyncService(
        new MoySkladService(),
        new OrderStateSyncService(),
        app(OrderProductionService::class),
    );
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

// ══════════════════════════════════════════════════════════════════════════════
// Окно производства при смене статуса в самом МойСклад
// ══════════════════════════════════════════════════════════════════════════════

describe('Окно производства по данным синхронизации', function () {

    /** Ответ на выгрузку заявок: одна заявка в заданном статусе, одна позиция. */
    function ordersResponse(string $stateId, string $stateName, Product $product): array
    {
        return [
            'rows' => [[
                'id'    => 'ms-1',
                'name'  => 'Заявка 1',
                'state' => [
                    'meta' => ['href' => 'https://api.moysklad.ru/entity/customerorder/metadata/states/' . $stateId],
                    'name' => $stateName,
                ],
                'positions' => ['rows' => [[
                    'quantity'   => 100,
                    'shipped'    => 0,
                    'assortment' => [
                        'meta' => ['href' => 'https://api.moysklad.ru/entity/product/' . $product->moysklad_id],
                        'name' => $product->name,
                    ],
                ]]],
                'attributes' => [],
            ]],
            'meta' => ['size' => 1],
        ];
    }

    function syncStatesFake(): array
    {
        return ['states' => [
            ['id' => SYNC_PROD_STATE, 'name' => 'В процессе', 'color' => 15280409, 'stateType' => 'Regular'],
            ['id' => SYNC_IDLE_STATE, 'name' => 'Новый', 'color' => 15280409, 'stateType' => 'Regular'],
        ]];
    }

    test('статус, изменённый в МойСклад, открывает окно производства', function () {
        config()->set('services.moysklad.token', 'test-token');

        OrderState::create(['id' => SYNC_PROD_STATE, 'name' => 'В процессе', 'is_enabled' => true, 'is_production' => true]);
        OrderState::create(['id' => SYNC_IDLE_STATE, 'name' => 'Новый', 'is_enabled' => true]);

        $store = Store::factory()->create();
        $product = Product::factory()->create();
        ProductStock::create(['product_id' => $product->id, 'store_id' => $store->id, 'quantity' => 45]);

        Order::create([
            'moysklad_id'       => 'ms-1',
            'name'              => 'Заявка 1',
            'state_moysklad_id' => SYNC_IDLE_STATE,
            'state_name'        => 'Новый',
        ]);

        Http::fake([
            '*/entity/customerorder/metadata' => Http::response(syncStatesFake(), 200),
            '*/entity/customerorder?*'        => Http::response(
                ordersResponse(SYNC_PROD_STATE, 'В процессе', $product), 200,
            ),
            '*' => Http::response(['rows' => [], 'meta' => ['size' => 0]], 200),
        ]);

        customerOrderSync()->pullActive();

        $order = Order::where('moysklad_id', 'ms-1')->first();
        expect($order->production_started_at)->not->toBeNull()
            ->and($order->production_ended_at)->toBeNull()
            ->and(OrderPositionSetting::first()->frozen_stocks)->toEqual([$store->id => 45.0]);
    });

    test('выход из производственного статуса в МойСклад закрывает окно', function () {
        config()->set('services.moysklad.token', 'test-token');

        OrderState::create(['id' => SYNC_PROD_STATE, 'name' => 'В процессе', 'is_enabled' => true, 'is_production' => true]);
        OrderState::create(['id' => SYNC_IDLE_STATE, 'name' => 'Новый', 'is_enabled' => true]);

        $product = Product::factory()->create();

        Order::create([
            'moysklad_id'           => 'ms-1',
            'name'                  => 'Заявка 1',
            'state_moysklad_id'     => SYNC_PROD_STATE,
            'state_name'            => 'В процессе',
            'production_started_at' => now()->subDay(),
        ]);

        Http::fake([
            '*/entity/customerorder/metadata' => Http::response(syncStatesFake(), 200),
            '*/entity/customerorder?*'        => Http::response(
                ordersResponse(SYNC_IDLE_STATE, 'Новый', $product), 200,
            ),
            '*' => Http::response(['rows' => [], 'meta' => ['size' => 0]], 200),
        ]);

        customerOrderSync()->pullActive();

        expect(Order::where('moysklad_id', 'ms-1')->first()->production_ended_at)->not->toBeNull();
    });
});
