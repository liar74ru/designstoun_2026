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
use App\Services\OrderChangeService;
use App\Services\OrderProductionService;
use App\Support\OrderPriority;
use Illuminate\Support\Facades\Http;

const SYNC_PROD_STATE = '55555555-5555-5555-5555-555555555555';
const SYNC_IDLE_STATE = '66666666-6666-6666-6666-666666666666';

function customerOrderSync(): CustomerOrderSyncService
{
    return new CustomerOrderSyncService(
        new MoySkladService(),
        new OrderStateSyncService(),
        app(OrderProductionService::class),
        new OrderChangeService(),
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

// ══════════════════════════════════════════════════════════════════════════════
// Приоритет заявки по данным синхронизации
// ══════════════════════════════════════════════════════════════════════════════

describe('Приоритет по данным синхронизации', function () {

    /** Выгрузка заявки со сроком отгрузки и датой документа. */
    function fakePrioritySync(Product $product, ?string $deliveryPlanned = '2026-10-05 00:00:00.000'): void
    {
        $response = ordersResponse(SYNC_IDLE_STATE, 'Новый', $product);
        $response['rows'][0]['moment'] = '2026-09-01 10:00:00.000';
        if ($deliveryPlanned !== null) {
            $response['rows'][0]['deliveryPlannedMoment'] = $deliveryPlanned;
        }

        Http::fake([
            '*/entity/customerorder/metadata' => Http::response(syncStatesFake(), 200),
            '*/entity/customerorder?*'        => Http::response($response, 200),
            '*' => Http::response(['rows' => [], 'meta' => ['size' => 0]], 200),
        ]);
    }

    beforeEach(function () {
        config()->set('services.moysklad.token', 'test-token');
        OrderState::create(['id' => SYNC_IDLE_STATE, 'name' => 'Новый', 'is_enabled' => true]);
    });

    test('срок отгрузки сохраняется, ключ считается по нему', function () {
        fakePrioritySync(Product::factory()->create());

        customerOrderSync()->pullActive();

        $order = Order::where('moysklad_id', 'ms-1')->first();
        expect($order->delivery_planned_at->format('Y-m-d'))->toBe('2026-10-05')
            ->and($order->priority_key)->toEqual(OrderPriority::autoKey($order->delivery_planned_at, $order->moment));
    });

    test('ручной ключ синхронизация не трогает', function () {
        Order::create([
            'moysklad_id'       => 'ms-1',
            'name'              => 'Заявка 1',
            'state_moysklad_id' => SYNC_IDLE_STATE,
            'priority_key'      => 42,
            'priority_manual'   => true,
        ]);
        fakePrioritySync(Product::factory()->create());

        customerOrderSync()->pullActive();

        $order = Order::where('moysklad_id', 'ms-1')->first();
        expect($order->priority_key)->toEqual(42.0)
            ->and($order->delivery_planned_at)->not->toBeNull();
    });

    test('заявка без срока встаёт после заявок со сроком', function () {
        fakePrioritySync(Product::factory()->create(), null);

        customerOrderSync()->pullActive();

        $order = Order::where('moysklad_id', 'ms-1')->first();
        expect($order->delivery_planned_at)->toBeNull()
            ->and($order->priority_key)->toEqual(OrderPriority::autoKey(null, $order->moment))
            ->and($order->priority_key)->toBeGreaterThan(
                OrderPriority::autoKey(now()->addYears(50), $order->moment),
            );
    });

    test('срок, изменённый в МойСклад, пересчитывает авто-ключ', function () {
        Order::create([
            'moysklad_id'         => 'ms-1',
            'name'                => 'Заявка 1',
            'state_moysklad_id'   => SYNC_IDLE_STATE,
            'delivery_planned_at' => '2026-12-31 00:00:00',
            'priority_key'        => 42,
        ]);
        fakePrioritySync(Product::factory()->create());

        customerOrderSync()->pullActive();

        $order = Order::where('moysklad_id', 'ms-1')->first();
        expect($order->delivery_planned_at->format('Y-m-d'))->toBe('2026-10-05')
            ->and($order->priority_key)->toEqual(OrderPriority::autoKey($order->delivery_planned_at, $order->moment));
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Постраничная выгрузка заявок
// ══════════════════════════════════════════════════════════════════════════════

describe('Выгрузка заявок страницами', function () {

    /** Заявка МойСклад без позиций. */
    function pagedOrderRow(int $n): array
    {
        return [
            'id'         => 'ms-page-' . $n,
            'name'       => 'Заявка ' . $n,
            'state'      => [
                'meta' => ['href' => 'https://api.moysklad.ru/entity/customerorder/metadata/states/' . SYNC_IDLE_STATE],
                'name' => 'Новый',
            ],
            'positions'  => ['rows' => []],
            'attributes' => [],
        ];
    }

    /**
     * Фейк МойСклад: 250 заявок страницами по 100; $failOffset — страница, которая отвечает 500.
     */
    function fakePagedOrders(?int $failOffset = null): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($failOffset) {
            if (str_contains($request->url(), '/entity/customerorder/metadata')) {
                return Http::response(syncStatesFake(), 200);
            }
            if (! str_contains($request->url(), '/entity/customerorder?')) {
                return Http::response(['rows' => [], 'meta' => ['size' => 0]], 200);
            }

            // Пробный запрос числа заявок — limit=1 без offset и expand
            if ((int) $request->data()['limit'] === 1) {
                return Http::response(['rows' => [pagedOrderRow(1)], 'meta' => ['size' => 250]], 200);
            }

            $offset = (int) $request->data()['offset'];
            if ($offset === $failOffset) {
                return Http::response(['errors' => [['error' => 'Сбой']]], 500);
            }

            $rows = array_map('pagedOrderRow', range($offset + 1, min($offset + 100, 250)));

            return Http::response(['rows' => $rows, 'meta' => ['size' => 250]], 200);
        });
    }

    beforeEach(function () {
        config()->set('services.moysklad.token', 'test-token');
        OrderState::create(['id' => SYNC_IDLE_STATE, 'name' => 'Новый', 'is_enabled' => true]);
    });

    test('все страницы загружаются, заявки сохраняются', function () {
        fakePagedOrders();

        $result = customerOrderSync()->pullActive();

        expect($result['success'])->toBeTrue()
            ->and($result['count'])->toBe(250)
            ->and(Order::count())->toBe(250);

        foreach ([0, 100, 200] as $offset) {
            Http::assertSent(fn ($r) => str_contains($r->url(), '/entity/customerorder?')
                && (int) ($r->data()['offset'] ?? -1) === $offset
                && (int) $r->data()['limit'] === 100);
        }

        // Пробный запрос — без expand: он только узнаёт число заявок
        Http::assertSent(fn ($r) => str_contains($r->url(), '/entity/customerorder?')
            && (int) $r->data()['limit'] === 1
            && ! isset($r->data()['expand']));
    });

    test('сбой средней страницы — ни одна заявка не удалена', function () {
        Order::create(['moysklad_id' => 'ms-local-1', 'name' => 'Локальная 1', 'state_name' => 'Новый']);
        Order::create(['moysklad_id' => 'ms-local-2', 'name' => 'Локальная 2', 'state_name' => 'Новый']);
        fakePagedOrders(failOffset: 100);

        $result = customerOrderSync()->pullActive();

        // Раньше выгрузка обрывалась на сбойной странице, и заявки вне первых 100 удалялись
        expect($result['success'])->toBeFalse()
            ->and($result['message'])->toContain('Локальные заявки не изменены')
            ->and(Order::whereIn('moysklad_id', ['ms-local-1', 'ms-local-2'])->count())->toBe(2);
    });

    test('сбой первой страницы — локальные заявки не стираются', function () {
        Order::create(['moysklad_id' => 'ms-local-1', 'name' => 'Локальная', 'state_name' => 'Новый']);
        fakePagedOrders(failOffset: 0);

        $result = customerOrderSync()->pullActive();

        expect($result['success'])->toBeFalse()
            ->and(Order::where('moysklad_id', 'ms-local-1')->exists())->toBeTrue();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Пропуск неизменённых заявок
// ══════════════════════════════════════════════════════════════════════════════

describe('Неизменённые заявки не переписываются', function () {

    /** Ответ МойСклад на следующие синхронизации: повторный Http::fake прежний не заменяет. */
    function fakeOrdersSync(array $response): void
    {
        test()->ordersResponse = $response;
    }

    beforeEach(function () {
        config()->set('services.moysklad.token', 'test-token');
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), '/entity/customerorder/metadata')) {
                return Http::response(syncStatesFake(), 200);
            }
            if (str_contains($request->url(), '/entity/customerorder?')) {
                return Http::response($this->ordersResponse, 200);
            }

            return Http::response(['rows' => [], 'meta' => ['size' => 0]], 200);
        });
        OrderState::create(['id' => SYNC_PROD_STATE, 'name' => 'В процессе', 'is_enabled' => true, 'is_production' => true]);
        OrderState::create(['id' => SYNC_IDLE_STATE, 'name' => 'Новый', 'is_enabled' => true]);
    });

    test('повторная синхронизация тех же данных не пересоздаёт позиции', function () {
        fakeOrdersSync(ordersResponse(SYNC_IDLE_STATE, 'Новый', Product::factory()->create()));
        customerOrderSync()->pullActive();

        $order  = Order::where('moysklad_id', 'ms-1')->first();
        $itemId = $order->items()->value('id');

        $this->travel(1)->hour();
        $result = customerOrderSync()->pullActive();

        expect($result['success'])->toBeTrue()
            ->and($order->items()->pluck('id')->all())->toBe([$itemId])
            ->and($order->fresh()->updated_at->equalTo($order->updated_at))->toBeTrue();
    });

    test('изменилось «отгружено» у позиции — заявка обновляется', function () {
        $response = ordersResponse(SYNC_IDLE_STATE, 'Новый', Product::factory()->create());
        fakeOrdersSync($response);
        customerOrderSync()->pullActive();

        $response['rows'][0]['positions']['rows'][0]['shipped'] = 30;
        fakeOrdersSync($response);
        customerOrderSync()->pullActive();

        expect((float) Order::where('moysklad_id', 'ms-1')->first()->items()->value('shipped'))->toBe(30.0);
    });

    test('товар, появившийся в программе позже, привязывается к позиции', function () {
        $product = Product::factory()->make(['moysklad_id' => '77777777-7777-7777-7777-777777777777']);
        fakeOrdersSync(ordersResponse(SYNC_IDLE_STATE, 'Новый', $product));
        customerOrderSync()->pullActive();

        $order = Order::where('moysklad_id', 'ms-1')->first();
        expect($order->items()->value('product_id'))->toBeNull();

        $product->save();
        customerOrderSync()->pullActive();

        expect($order->items()->value('product_id'))->toBe($product->id);
    });

    test('смена статуса на производственный после синхронизации открывает окно', function () {
        $product = Product::factory()->create();
        fakeOrdersSync(ordersResponse(SYNC_IDLE_STATE, 'Новый', $product));
        customerOrderSync()->pullActive();

        fakeOrdersSync(ordersResponse(SYNC_PROD_STATE, 'В процессе', $product));
        customerOrderSync()->pullActive();

        expect(Order::where('moysklad_id', 'ms-1')->first()->production_started_at)->not->toBeNull();
    });

    test('число запросов к БД на повторной синхронизации не растёт с числом заявок', function () {
        $queriesOnRepeat = function (int $n): int {
            Order::query()->delete();
            fakeOrdersSync(['rows' => array_map('pagedOrderRow', range(1, $n)), 'meta' => ['size' => $n]]);
            customerOrderSync()->pullActive();

            \Illuminate\Support\Facades\DB::flushQueryLog();
            \Illuminate\Support\Facades\DB::enableQueryLog();
            customerOrderSync()->pullActive();
            \Illuminate\Support\Facades\DB::disableQueryLog();

            return count(\Illuminate\Support\Facades\DB::getQueryLog());
        };

        expect($queriesOnRepeat(60))->toBe($queriesOnRepeat(5));
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Изменение состава заявки
// ══════════════════════════════════════════════════════════════════════════════

describe('Изменение состава заявки', function () {

    /** UUID товара МойСклад по короткому имени (регэксп синхронизации ждёт 36 символов). */
    function chgProductId(string $key): string
    {
        return str_pad(bin2hex($key), 8, '0') . '-0000-0000-0000-000000000000';
    }

    /**
     * Выгрузка одной заявки: [ключ товара => количество].
     *
     * @param  array<string, float>  $positions
     * @param  array<string, float>  $shipped
     */
    function chgResponse(array $positions, string $stateId = SYNC_IDLE_STATE, array $shipped = []): array
    {
        $rows = [];
        foreach ($positions as $key => $qty) {
            $rows[] = [
                'quantity'   => $qty,
                'shipped'    => $shipped[$key] ?? 0,
                'assortment' => [
                    'meta' => ['href' => 'https://api.moysklad.ru/entity/product/' . chgProductId($key)],
                    'name' => 'Товар ' . $key,
                ],
            ];
        }

        return [
            'rows' => [[
                'id'         => 'ms-1',
                'name'       => 'Заявка 1',
                'state'      => [
                    'meta' => ['href' => 'https://api.moysklad.ru/entity/customerorder/metadata/states/' . $stateId],
                    'name' => 'Статус',
                ],
                'positions'  => ['rows' => $rows],
                'attributes' => [],
            ]],
            'meta' => ['size' => 1],
        ];
    }

    function chgSync(array $positions, string $stateId = SYNC_IDLE_STATE, array $shipped = []): Order
    {
        fakeOrdersSync(chgResponse($positions, $stateId, $shipped));
        customerOrderSync()->pullActive();

        return Order::where('moysklad_id', 'ms-1')->first();
    }

    beforeEach(function () {
        config()->set('services.moysklad.token', 'test-token');
        OrderState::create(['id' => SYNC_IDLE_STATE, 'name' => 'Новый', 'is_enabled' => true]);
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), '/entity/customerorder/metadata')) {
                return Http::response(syncStatesFake(), 200);
            }
            if (str_contains($request->url(), '/entity/customerorder?')) {
                return Http::response($this->ordersResponse, 200);
            }

            return Http::response(['rows' => [], 'meta' => ['size' => 0]], 200);
        });
    });

    test('новая заявка — изменений нет', function () {
        $order = chgSync(['a' => 10]);

        expect($order->positions_changed_at)->toBeNull()
            ->and($order->position_changes)->toBeNull();
    });

    test('количество изменилось — было и стало записаны', function () {
        chgSync(['a' => 10]);
        $order = chgSync(['a' => 15]);

        expect($order->positions_changed_at)->not->toBeNull()
            ->and($order->position_changes)->toEqual([
                chgProductId('a') => ['name' => 'Товар a', 'from' => 10, 'to' => 15],
            ]);
    });

    test('позиция добавлена и удалена', function () {
        chgSync(['a' => 10]);
        $changes = chgSync(['b' => 5])->position_changes;

        expect($changes[chgProductId('a')])->toMatchArray(['from' => 10, 'to' => 0])
            ->and($changes[chgProductId('b')])->toMatchArray(['from' => 0, 'to' => 5]);
    });

    test('позиции одного товара складываются', function () {
        chgSync(['a' => 10]);

        // Тот же товар двумя строками: 4 + 6 = прежние 10
        $response = chgResponse(['a' => 4]);
        $response['rows'][0]['positions']['rows'][] = array_merge($response['rows'][0]['positions']['rows'][0], ['quantity' => 6]);
        fakeOrdersSync($response);
        customerOrderSync()->pullActive();

        expect(Order::where('moysklad_id', 'ms-1')->first()->position_changes)->toBeNull();
    });

    test('изменилось только «отгружено» — не изменение', function () {
        chgSync(['a' => 10]);
        $order = chgSync(['a' => 10], SYNC_IDLE_STATE, ['a' => 4]);

        expect((float) $order->items()->value('shipped'))->toBe(4.0)
            ->and($order->positions_changed_at)->toBeNull();
    });

    test('повторное изменение хранит первоначальное «было», возврат снимает плашку', function () {
        chgSync(['a' => 10]);
        chgSync(['a' => 15]);
        $order = chgSync(['a' => 20]);

        expect($order->position_changes[chgProductId('a')])->toMatchArray(['from' => 10, 'to' => 20]);

        $order = chgSync(['a' => 10]);

        expect($order->position_changes)->toBeNull()
            ->and($order->positions_changed_at)->toBeNull();
    });

    test('статус без слежения — ни новый, ни прежний — изменений нет', function () {
        OrderState::create(['id' => SYNC_PROD_STATE, 'name' => 'Отгружен', 'is_enabled' => true, 'track_changes' => false]);

        chgSync(['a' => 10]);
        expect(chgSync(['a' => 15], SYNC_PROD_STATE)->positions_changed_at)->toBeNull();

        expect(chgSync(['a' => 20])->positions_changed_at)->toBeNull();
    });

    test('количество выросло — отметка «готова» снимается, уменьшилось — остаётся', function () {
        $a = Product::factory()->create(['moysklad_id' => chgProductId('a')]);
        $b = Product::factory()->create(['moysklad_id' => chgProductId('b')]);
        $order = chgSync(['a' => 10, 'b' => 10]);

        foreach ([$a, $b] as $product) {
            OrderPositionSetting::create(['order_id' => $order->id, 'product_id' => $product->id, 'ready_at' => now()]);
        }

        chgSync(['a' => 15, 'b' => 5]);

        expect(OrderPositionSetting::where('product_id', $a->id)->first()->ready_at)->toBeNull()
            ->and(OrderPositionSetting::where('product_id', $b->id)->first()->ready_at)->not->toBeNull();
    });

    test('переход в «Изменено» запоминает прежний статус', function () {
        OrderState::create(['id' => SYNC_PROD_STATE, 'name' => 'Изменено', 'is_enabled' => true, 'is_changed' => true]);

        chgSync(['a' => 10]);
        $order = chgSync(['a' => 10], SYNC_PROD_STATE);

        expect($order->state_before_change)->toBe(SYNC_IDLE_STATE);
    });
});
