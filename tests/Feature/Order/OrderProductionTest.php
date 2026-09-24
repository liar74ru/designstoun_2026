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
use App\Models\User;
use App\Models\Worker;
use App\Models\Workshop;
use App\Models\WorkshopItem;
use App\Models\WorkshopLog;
use App\Models\WorkshopLogItem;
use App\Services\OrderPositionService;
use App\Services\OrderProductionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

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
        $user = positionMaster($other);

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
        $user = User::factory()->create(['is_admin' => true]);

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
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->from(route('orders.show', 'ms-1'))
            ->post(route('orders.state.update', 'ms-1'), ['state_id' => PROD_STATE])
            ->assertSessionHas('error');

        expect($order->fresh()->production_started_at)->toBeNull()
            ->and(OrderPositionSetting::count())->toBe(0);
    });
});
