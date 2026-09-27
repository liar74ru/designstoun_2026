<?php

use App\Models\Department;
use App\Models\Order;
use App\Models\OrderPositionSetting;
use App\Models\OrderState;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ReceptionLog;
use App\Models\ReceptionLogItem;
use App\Models\StoneReception;
use App\Models\Store;
use App\Models\Worker;
use App\Models\Workshop;
use App\Models\WorkshopItem;
use App\Models\WorkshopLog;
use App\Models\WorkshopLogItem;
use App\Services\OrderPositionService;
use App\Services\OrderProductionService;
use App\Services\OrderService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

const PROD_STATE = '33333333-3333-3333-3333-333333333333';
const IDLE_STATE = '44444444-4444-4444-4444-444444444444';

beforeEach(function () {
    config()->set('services.moysklad.token', 'test-token');
    Order::forgetStateCache();
});

/**
 * Заявка на 100 единиц товара, склад отдела с остатком.
 *
 * @return array{order: Order, product: Product, store: Store, other: Store}
 */
function productionFixture(float $stock = 45.0): array
{
    $store = Store::factory()->create(['name' => 'Цех']);
    $other = Store::factory()->create(['name' => 'Дальний']);

    $dept = Department::create([
        'name'                        => 'Отдел',
        'is_active'                   => true,
        'default_production_store_id' => $store->id,
    ]);

    $product = Product::factory()->create();
    ProductStock::create(['product_id' => $product->id, 'store_id' => $store->id, 'quantity' => $stock]);

    $order = Order::create([
        'moysklad_id'       => 'ms-1',
        'name'              => 'Заявка 1',
        'state_moysklad_id' => IDLE_STATE,
        'state_name'        => 'Новый',
    ]);
    $order->departments()->attach($dept->id);
    $order->items()->create(['product_id' => $product->id, 'quantity' => 100, 'shipped' => 0]);

    OrderState::create(['id' => PROD_STATE, 'name' => 'В процессе', 'is_enabled' => true, 'is_production' => true]);
    OrderState::create(['id' => IDLE_STATE, 'name' => 'Новый', 'is_enabled' => true]);

    return compact('order', 'product', 'store', 'other');
}

/** Работник — шапки документов производства требуют приёмщика и исполнителя. */
function productionWorker(): Worker
{
    return Worker::firstOrCreate(['name' => 'Пильщик'], ['position' => 'Работник']);
}

/** Приёмка продукции: строка журнала с дельтой на указанный склад и дату. */
function recordReception(Product $product, Store $store, float $qty, Carbon $at): void
{
    $worker = productionWorker();

    $reception = StoneReception::create([
        'store_id'    => $store->id,
        'receiver_id' => $worker->id,
        'cutter_id'   => $worker->id,
        'status'      => StoneReception::STATUS_ACTIVE,
        'created_at'  => $at,
    ]);

    $log = ReceptionLog::create([
        'stone_reception_id' => $reception->id,
        'receiver_id'        => $worker->id,
        'cutter_id'          => $worker->id,
        'type'               => ReceptionLog::TYPE_CREATED,
        'created_at'         => $at,
    ]);

    ReceptionLogItem::create([
        'reception_log_id' => $log->id,
        'product_id'       => $product->id,
        'quantity_delta'   => $qty,
    ]);
}

/** Цех: изготовленное — строка role=product, склад продукта отдельный от склада сырья. */
function recordWorkshop(Product $product, Store $productStore, float $qty, Carbon $at, ?Store $rawStore = null): void
{
    $worker = productionWorker();

    $workshop = Workshop::create([
        'store_id'         => ($rawStore ?? $productStore)->id,
        'product_store_id' => $productStore->id,
        'packer_id'        => $worker->id,
        'receiver_id'      => $worker->id,
        'status'           => 'active',
        'created_at'       => $at,
    ]);

    $log = WorkshopLog::create([
        'workshop_id' => $workshop->id,
        'packer_id'   => $worker->id,
        'receiver_id' => $worker->id,
        'type'        => WorkshopLog::TYPE_CREATED,
        'created_at'  => $at,
    ]);

    WorkshopLogItem::create([
        'workshop_log_id' => $log->id,
        'product_id'      => $product->id,
        'role'            => WorkshopItem::ROLE_PRODUCT,
        'quantity_delta'  => $qty,
    ]);
}

function producedQty(Order $order, string $storeId): float
{
    $order = $order->fresh(['items.product.stocks', 'positionSettings']);

    return app(OrderPositionService::class)
        ->rows($order, $storeId, app(OrderProductionService::class)->producedForOrder($order))
        ->first()['producedQty'];
}

// ══════════════════════════════════════════════════════════════════════════════
// Окно производства
// ══════════════════════════════════════════════════════════════════════════════

describe('OrderProductionService::syncPeriod()', function () {

    test('вход в производственный статус открывает окно и снимает остаток', function () {
        ['order' => $order, 'product' => $product, 'store' => $store] = productionFixture(45);

        app(OrderProductionService::class)->syncPeriod($order, PROD_STATE);

        $order->refresh();
        expect($order->production_started_at)->not->toBeNull()
            ->and($order->production_ended_at)->toBeNull();

        $setting = OrderPositionSetting::where('product_id', $product->id)->first();
        expect($setting->frozen_stocks)->toEqual([$store->id => 45.0]);
    });

    test('выход из производственного статуса закрывает окно', function () {
        ['order' => $order] = productionFixture(45);
        $service = app(OrderProductionService::class);

        $service->syncPeriod($order, PROD_STATE);
        $service->syncPeriod($order->fresh(), IDLE_STATE);

        expect($order->fresh()->production_ended_at)->not->toBeNull();
    });

    test('непроизводственный статус окна не открывает', function () {
        ['order' => $order] = productionFixture(45);

        app(OrderProductionService::class)->syncPeriod($order, IDLE_STATE);

        expect($order->fresh()->production_started_at)->toBeNull()
            ->and(OrderPositionSetting::count())->toBe(0);
    });

    test('повторный вход без выхода снимок не пересобирает', function () {
        ['order' => $order, 'product' => $product, 'store' => $store] = productionFixture(45);
        $service = app(OrderProductionService::class);

        $service->syncPeriod($order, PROD_STATE);
        $startedAt = $order->fresh()->production_started_at;

        ProductStock::where('product_id', $product->id)->update(['quantity' => 90]);
        $service->syncPeriod($order->fresh(), PROD_STATE);

        expect($order->fresh()->production_started_at->eq($startedAt))->toBeTrue()
            ->and(OrderPositionSetting::first()->frozen_stocks)->toEqual([$store->id => 45.0]);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Подсчёт изготовленного
// ══════════════════════════════════════════════════════════════════════════════

describe('Изготовлено', function () {

    test('приёмка внутри окна попадает в изготовленное', function () {
        ['order' => $order, 'product' => $product, 'store' => $store] = productionFixture(45);
        $order->update(['production_started_at' => now()->subDay()]);

        recordReception($product, $store, 20, now()->subHours(3));

        expect(producedQty($order, $store->id))->toBe(20.0);
    });

    test('цех тоже считается — по строкам role=product', function () {
        ['order' => $order, 'product' => $product, 'store' => $store] = productionFixture(45);
        $order->update(['production_started_at' => now()->subDay()]);

        recordWorkshop($product, $store, 12, now()->subHours(2));

        expect(producedQty($order, $store->id))->toBe(12.0);
    });

    test('произведённое до входа в производство не считается', function () {
        ['order' => $order, 'product' => $product, 'store' => $store] = productionFixture(45);
        $order->update(['production_started_at' => now()->subDay()]);

        recordReception($product, $store, 30, now()->subDays(5));

        expect(producedQty($order, $store->id))->toBe(0.0);
    });

    test('произведённое после выхода из производства не считается', function () {
        ['order' => $order, 'product' => $product, 'store' => $store] = productionFixture(45);
        $order->update([
            'production_started_at' => now()->subDays(3),
            'production_ended_at'   => now()->subDays(2),
        ]);

        recordReception($product, $store, 10, now()->subDays(2)->subHour());
        recordReception($product, $store, 40, now()->subHour());

        expect(producedQty($order, $store->id))->toBe(10.0);
    });

    test('производство на невыбранный склад не считается', function () {
        ['order' => $order, 'product' => $product, 'store' => $store, 'other' => $other] = productionFixture(45);
        $order->update(['production_started_at' => now()->subDay()]);

        recordReception($product, $other, 25, now()->subHours(2));

        expect(producedQty($order, $store->id))->toBe(0.0);
    });

    test('после заморозки склад не растёт от новых приёмок — задвоения нет', function () {
        ['order' => $order, 'product' => $product, 'store' => $store] = productionFixture(45);
        app(OrderProductionService::class)->syncPeriod($order, PROD_STATE);

        $this->travel(5)->minutes();

        // Приёмка 20: сначала журнал, затем МойСклад поднимает остаток до 65
        recordReception($product, $store, 20, now());
        ProductStock::where('product_id', $product->id)->update(['quantity' => 65]);

        $order = $order->fresh(['items.product.stocks', 'positionSettings']);
        $row = app(OrderPositionService::class)
            ->rows($order, $store->id, app(OrderProductionService::class)->producedForOrder($order))
            ->first();

        expect($row['warehouseQty'])->toBe(45.0)
            ->and($row['producedQty'])->toBe(20.0)
            ->and($row['totalQty'])->toBe(65.0);
    });

    test('позиция, добавленная после заморозки, показывает живой остаток', function () {
        ['order' => $order, 'store' => $store] = productionFixture(45);
        app(OrderProductionService::class)->syncPeriod($order, PROD_STATE);

        $late = Product::factory()->create();
        ProductStock::create(['product_id' => $late->id, 'store_id' => $store->id, 'quantity' => 7]);
        $order->items()->create(['product_id' => $late->id, 'quantity' => 30, 'shipped' => 0]);

        $rows = app(OrderPositionService::class)
            ->rows($order->fresh(['items.product.stocks', 'positionSettings']), $store->id);

        expect($rows->last()['frozen'])->toBeFalse()
            ->and($rows->last()['warehouseQty'])->toBe(7.0);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Пересчёт по складам
// ══════════════════════════════════════════════════════════════════════════════

describe('OrderProductionService::recalculate()', function () {

    test('заново снимает остатки и обнуляет изготовленное', function () {
        ['order' => $order, 'product' => $product, 'store' => $store] = productionFixture(45);
        $service = app(OrderProductionService::class);

        $service->syncPeriod($order, PROD_STATE);
        $order->update(['state_moysklad_id' => PROD_STATE]);

        $this->travel(5)->minutes();

        // Приёмка 20 и остаток, поднявшийся до 65
        recordReception($product, $store, 20, now());
        ProductStock::where('product_id', $product->id)->update(['quantity' => 65]);

        $this->travel(5)->minutes();
        $service->recalculate($order->fresh());

        $order = $order->fresh(['items.product.stocks', 'positionSettings']);
        $row = app(OrderPositionService::class)
            ->rows($order, $store->id, $service->producedForOrder($order))
            ->first();

        expect($row['warehouseQty'])->toBe(65.0)
            ->and($row['producedQty'])->toBe(0.0)
            ->and($row['totalQty'])->toBe(65.0);
    });

    test('открывает окно заявке, уже висящей в производственном статусе', function () {
        ['order' => $order, 'store' => $store] = productionFixture(45);
        $order->update(['state_moysklad_id' => PROD_STATE]);

        app(OrderProductionService::class)->recalculate($order);

        expect($order->fresh()->production_started_at)->not->toBeNull()
            ->and(OrderPositionSetting::first()->frozen_stocks)->toEqual([$store->id => 45.0]);
    });

    test('вне производственного статуса окно не открывается', function () {
        ['order' => $order] = productionFixture(45);

        app(OrderProductionService::class)->recalculate($order);

        expect($order->fresh()->production_started_at)->toBeNull()
            ->and(OrderPositionSetting::count())->toBe(0);
    });

    test('мастер чужого отдела получает 403', function () {
        ['order' => $order] = productionFixture(45);
        $other = Department::create(['name' => 'Отдел 2', 'is_active' => true]);
        $user = Access::master($other, 'orders');

        $this->actingAs($user)
            ->post(route('orders.recalculate', 'ms-1'))
            ->assertForbidden();

        expect($order->fresh()->production_started_at)->toBeNull();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Смена статуса через контроллер
// ══════════════════════════════════════════════════════════════════════════════

describe('Смена статуса открывает окно производства', function () {

    test('успешный перевод в «В процессе» замораживает остаток', function () {
        Http::fake(['*' => Http::response(['id' => 'ms-1'], 200)]);

        ['order' => $order, 'store' => $store] = productionFixture(45);
        $user = H::adminUser();

        $this->actingAs($user)
            ->from(route('orders.show', 'ms-1'))
            ->post(route('orders.state.update', 'ms-1'), ['state_id' => PROD_STATE])
            ->assertSessionHas('success');

        expect($order->fresh()->production_started_at)->not->toBeNull()
            ->and(OrderPositionSetting::first()->frozen_stocks)->toEqual([$store->id => 45.0]);
    });

    test('ошибка МойСклад окно не открывает', function () {
        Http::fake(['*' => Http::response(['errors' => [['error' => 'Нет прав']]], 412)]);

        ['order' => $order] = productionFixture(45);
        $user = H::adminUser();

        $this->actingAs($user)
            ->from(route('orders.show', 'ms-1'))
            ->post(route('orders.state.update', 'ms-1'), ['state_id' => PROD_STATE])
            ->assertSessionHas('error');

        expect($order->fresh()->production_started_at)->toBeNull()
            ->and(OrderPositionSetting::count())->toBe(0);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Раздача общего товара между заявками в производстве
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Строка позиции с долей заявки в общем объёме — так её считают список и карточка.
 *
 * @return array<string, mixed>
 */
function allocatedRow(Order $order, string $storeId): array
{
    $order = $order->fresh(['items.product.stocks', 'positionSettings', 'departments']);

    return app(OrderPositionService::class)
        ->rows(
            $order,
            $storeId,
            app(OrderProductionService::class)->producedForOrder($order),
            app(OrderService::class)->allocate(collect([$order]), null)[$order->id] ?? [],
        )
        ->first();
}

/** Заявка в производстве со своим снимком остатка. */
function orderInProduction(string $name, Product $product, Store $store, float $qty, float $frozen, Carbon $startedAt, float $key): Order
{
    $order = Order::create([
        'moysklad_id'           => 'ms-' . $name,
        'name'                  => $name,
        'production_started_at' => $startedAt,
        'priority_key'          => $key,
    ]);
    $order->departments()->attach(Department::where('name', 'Отдел')->value('id'));
    $order->items()->create(['product_id' => $product->id, 'quantity' => $qty, 'shipped' => 0]);

    OrderPositionSetting::create([
        'order_id'      => $order->id,
        'product_id'    => $product->id,
        'frozen_stocks' => [$store->id => $frozen],
    ]);

    return $order;
}

/**
 * Склад 10. №1 (нужно 50) в производстве с −10 дней, до входа №2 напилили 20.
 * №2 (нужно 30) с −7 дней — его снимок уже 30. Потом напилили ещё 40.
 * Физически 70 при потребности 80.
 *
 * @return array{first: Order, second: Order, store: Store, other: Store, product: Product}
 */
function sharedProductFixture(): array
{
    ['order' => $seed, 'product' => $product, 'store' => $store, 'other' => $other] = productionFixture(10);
    $seed->delete();

    $first = orderInProduction('Первая', $product, $store, 50, 10, now()->subDays(10), 100);
    recordReception($product, $store, 20, now()->subDays(8));
    $second = orderInProduction('Вторая', $product, $store, 30, 30, now()->subDays(7), 200);
    recordReception($product, $store, 40, now()->subDays(2));

    return compact('first', 'second', 'store', 'other', 'product');
}

describe('Раздача общего товара по очереди', function () {

    test('метры не задваиваются: первая закрыта, у второй дефицит', function () {
        ['first' => $first, 'second' => $second, 'store' => $store] = sharedProductFixture();

        $a = allocatedRow($first, $store->id);
        $b = allocatedRow($second, $store->id);

        expect($a['warehouseQty'])->toBe(10.0)
            ->and($a['producedQty'])->toBe(40.0)
            ->and($a['short'])->toBe(0.0)
            ->and($b['warehouseQty'])->toBe(0.0)
            ->and($b['producedQty'])->toBe(20.0)
            ->and($b['short'])->toBe(10.0)
            ->and($a['totalQty'] + $b['totalQty'])->toBe(70.0);
    });

    test('срочная заявка забирает свою долю первой', function () {
        ['first' => $first, 'second' => $second, 'store' => $store] = sharedProductFixture();
        $second->update(['is_urgent' => true]);

        $b = allocatedRow($second, $store->id);
        $a = allocatedRow($first, $store->id);

        expect($b['totalQty'])->toBe(30.0)
            ->and($b['short'])->toBe(0.0)
            // Последняя в очереди получает весь остаток
            ->and($a['totalQty'])->toBe(40.0)
            ->and($a['short'])->toBe(10.0);
    });

    test('одиночная заявка считается как раньше', function () {
        ['order' => $order, 'product' => $product, 'store' => $store] = productionFixture(45);
        app(OrderProductionService::class)->syncPeriod($order, PROD_STATE);
        $this->travel(5)->minutes();
        recordReception($product, $store, 20, now());

        $row = allocatedRow($order, $store->id);

        expect($row['warehouseQty'])->toBe(45.0)
            ->and($row['producedQty'])->toBe(producedQty($order, $store->id))
            ->and($row['producedQty'])->toBe(20.0)
            ->and($row['sharedWith'])->toBe([]);
    });

    test('заявка с закрытым окном в раздаче не участвует', function () {
        ['first' => $first, 'second' => $second, 'store' => $store] = sharedProductFixture();
        $first->update(['production_ended_at' => now()->subDay()]);

        $b = allocatedRow($second, $store->id);

        // Вторая одна в группе — свой снимок и изготовленное с её входа
        expect($b['warehouseQty'])->toBe(30.0)
            ->and($b['producedQty'])->toBe(40.0)
            ->and($b['sharedWith'])->toBe([]);
    });

    test('позиция знает, с какими заявками делит товар', function () {
        ['first' => $first, 'store' => $store] = sharedProductFixture();

        expect(allocatedRow($first, $store->id)['sharedWith'])->toBe(['Вторая']);
    });

    test('отгруженное уменьшает потребность заявки', function () {
        ['first' => $first, 'second' => $second, 'store' => $store] = sharedProductFixture();
        $first->items()->update(['shipped' => 20]);

        $a = allocatedRow($first, $store->id);
        $b = allocatedRow($second, $store->id);

        expect($a['totalQty'])->toBe(30.0)
            ->and($a['short'])->toBe(0.0)
            ->and($b['totalQty'])->toBe(40.0)
            ->and($b['short'])->toBe(0.0);
    });

    test('изготовленное в цехе входит в общий объём', function () {
        ['first' => $first, 'second' => $second, 'store' => $store, 'product' => $product] = sharedProductFixture();
        recordWorkshop($product, $store, 15, now()->subDay());

        // Было 70 при потребности 80, с цехом — 85: дефицит второй закрыт
        $b = allocatedRow($second, $store->id);

        expect(allocatedRow($first, $store->id)['totalQty'])->toBe(50.0)
            ->and($b['totalQty'])->toBe(35.0)
            ->and($b['short'])->toBe(0.0);
    });

    test('склад, не выбранный заявкой, не расходуется, но его остаток виден', function () {
        ['first' => $first, 'second' => $second, 'store' => $store, 'other' => $other, 'product' => $product] = sharedProductFixture();
        OrderPositionSetting::where('order_id', $first->id)->where('product_id', $product->id)
            ->update(['stores' => [$other->id]]);

        $a = allocatedRow($first, $store->id);
        $b = allocatedRow($second, $store->id);

        expect($a['totalQty'])->toBe(0.0)
            // Остаток склада отдела показан — видно, откуда можно добрать
            ->and($a['storeQty'][$store->id])->toBe(10.0)
            ->and($b['totalQty'])->toBe(70.0);
    });

    test('поправка мастера ложится поверх доли', function () {
        ['second' => $second, 'store' => $store, 'product' => $product] = sharedProductFixture();
        OrderPositionSetting::where('order_id', $second->id)->where('product_id', $product->id)
            ->update(['produced_delta' => 5]);

        expect(allocatedRow($second, $store->id)['producedQty'])->toBe(25.0);
    });

    test('без снимка у ранней заявки якорем становится заявка со снимком', function () {
        ['first' => $first, 'second' => $second, 'store' => $store, 'product' => $product] = sharedProductFixture();
        OrderPositionSetting::where('order_id', $first->id)->where('product_id', $product->id)
            ->update(['frozen_stocks' => null]);

        // Пул — снимок второй (30) и изготовленное с её входа (40)
        $a = allocatedRow($first, $store->id);
        $b = allocatedRow($second, $store->id);

        expect($a['totalQty'])->toBe(50.0)
            ->and($b['totalQty'])->toBe(20.0)
            ->and($a['sharedWith'])->toBe(['Вторая']);
    });

    test('без снимков раздачи нет — числа по-старому', function () {
        ['first' => $first, 'second' => $second, 'store' => $store] = sharedProductFixture();
        OrderPositionSetting::query()->update(['frozen_stocks' => null]);

        $a = allocatedRow($first, $store->id);
        $b = allocatedRow($second, $store->id);

        // Живой остаток 10 и изготовленное в своём окне
        expect($a['totalQty'])->toBe(70.0)
            ->and($b['totalQty'])->toBe(50.0)
            ->and($a['sharedWith'])->toBe([])
            ->and($b['sharedWith'])->toBe([]);
    });

    test('поправка из карточки считает базу от доли заявки', function () {
        ['second' => $second, 'store' => $store, 'product' => $product] = sharedProductFixture();

        $this->actingAs(H::adminUser())
            ->post(route('orders.position.update', $second->moysklad_id), [
                'product_id' => $product->id,
                'stores'     => [$store->id],
                'fact'       => 12,
                'produced'   => 20,
            ])
            ->assertSessionHas('success');

        $setting = OrderPositionSetting::where('order_id', $second->id)->where('product_id', $product->id)->first();

        // Складская доля второй — 0, изготовленная — 20: снимок 30 в базу не попадает
        expect((float) $setting->stock_base)->toBe(0.0)
            ->and((float) $setting->stock_delta)->toBe(12.0)
            ->and((float) $setting->produced_base)->toBe(20.0)
            ->and((float) $setting->produced_delta)->toBe(0.0);
    });

    test('карточка заявки показывает долю', function () {
        ['second' => $second] = sharedProductFixture();

        $rows = $this->actingAs(H::adminUser())
            ->get(route('orders.show', $second->moysklad_id))
            ->assertSuccessful()
            ->assertSee('Товар делится по очереди с заявками', false)
            ->viewData('rows');

        expect($rows->first()['totalQty'])->toBe(20.0);
    });
});
