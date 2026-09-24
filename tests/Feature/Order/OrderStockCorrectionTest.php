<?php

use App\Models\Department;
use App\Models\DepartmentOperationSetting;
use App\Models\Order;
use App\Models\OrderStockCorrection;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Worker;
use App\Services\OrderStockCorrectionService;

/**
 * Заявка отдела со складом, товаром и остатком на нём.
 *
 * @return array{order: Order, product: Product, store: Store, dept: Department}
 */
function correctionFixture(float $stock = 45.0): array
{
    $store = Store::factory()->create();
    $dept = Department::create([
        'name'                        => 'Отдел',
        'is_active'                   => true,
        'default_production_store_id' => $store->id,
    ]);

    $product = Product::factory()->create(['name' => 'Плитняк Кварцит 20-40']);
    ProductStock::create(['product_id' => $product->id, 'store_id' => $store->id, 'quantity' => $stock]);

    $order = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1', 'state_name' => 'Новый']);
    $order->departments()->attach($dept->id);
    $order->items()->create(['product_id' => $product->id, 'quantity' => 100, 'shipped' => 0]);

    return compact('order', 'product', 'store', 'dept');
}

function correctionMaster(Department $dept): User
{
    DepartmentOperationSetting::create([
        'department_id' => $dept->id,
        'operation_key' => 'orders',
        'config'        => ['positions' => ['Мастер']],
        'enabled'       => true,
    ]);

    $worker = Worker::create([
        'name'          => 'Мастер',
        'department_id' => $dept->id,
        'position'      => 'Мастер',
    ]);

    return User::factory()->for($worker)->create(['is_admin' => false]);
}

// ══════════════════════════════════════════════════════════════════════════════
// OrderStockCorrectionService
// ══════════════════════════════════════════════════════════════════════════════

describe('OrderStockCorrectionService', function () {

    test('дельта считается от остатка МойСклад', function () {
        ['order' => $order, 'product' => $product, 'store' => $store] = correctionFixture(45);

        $c = (new OrderStockCorrectionService())->set($order, $product->id, $store->id, 38, 'бой', null);

        expect((float) $c->delta)->toBe(-7.0)
            ->and((float) $c->moysklad_quantity)->toBe(45.0)
            ->and($c->note)->toBe('бой');
    });

    test('повторное уточнение заменяет поправку, а не складывается с ней', function () {
        ['order' => $order, 'product' => $product, 'store' => $store] = correctionFixture(45);
        $service = new OrderStockCorrectionService();

        $service->set($order, $product->id, $store->id, 38, null, null);
        $c = $service->set($order, $product->id, $store->id, 42, null, null);

        expect((float) $c->delta)->toBe(-3.0)
            ->and(OrderStockCorrection::count())->toBe(1);
    });

    test('факт больше остатка даёт положительную поправку', function () {
        ['order' => $order, 'product' => $product, 'store' => $store] = correctionFixture(45);

        $c = (new OrderStockCorrectionService())->set($order, $product->id, $store->id, 50, null, null);

        expect((float) $c->delta)->toBe(5.0);
    });

    test('факт, равный остатку МойСклад, снимает поправку', function () {
        ['order' => $order, 'product' => $product, 'store' => $store] = correctionFixture(45);
        $service = new OrderStockCorrectionService();

        $service->set($order, $product->id, $store->id, 38, null, null);
        $c = $service->set($order, $product->id, $store->id, 45, null, null);

        expect($c)->toBeNull()
            ->and(OrderStockCorrection::count())->toBe(0);
    });

    test('reset() удаляет поправку', function () {
        ['order' => $order, 'product' => $product, 'store' => $store] = correctionFixture(45);
        $service = new OrderStockCorrectionService();

        $service->set($order, $product->id, $store->id, 38, null, null);
        $service->reset($order, $product->id, $store->id);

        expect(OrderStockCorrection::count())->toBe(0);
    });

    test('товар без остатка на складе: база 0', function () {
        ['order' => $order, 'store' => $store] = correctionFixture(45);
        $other = Product::factory()->create();

        $c = (new OrderStockCorrectionService())->set($order, $other->id, $store->id, 12, null, null);

        expect((float) $c->delta)->toBe(12.0)
            ->and((float) $c->moysklad_quantity)->toBe(0.0);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Маршруты уточнения
// ══════════════════════════════════════════════════════════════════════════════

describe('OrderController: уточнение остатков', function () {

    test('мастер сохраняет уточнение', function () {
        ['order' => $order, 'product' => $product, 'store' => $store, 'dept' => $dept] = correctionFixture(45);
        $user = correctionMaster($dept);

        $this->actingAs($user)
            ->from(route('orders.show', 'ms-1'))
            ->post(route('orders.corrections.store', 'ms-1'), [
                'product_id' => $product->id,
                'store_id'   => $store->id,
                'fact'       => 38,
            ])
            ->assertRedirect(route('orders.show', 'ms-1'))
            ->assertSessionHas('success');

        expect((float) OrderStockCorrection::first()->delta)->toBe(-7.0);
    });

    test('уточнение сохраняет автора', function () {
        ['order' => $order, 'product' => $product, 'store' => $store, 'dept' => $dept] = correctionFixture(45);
        $user = correctionMaster($dept);

        $this->actingAs($user)->post(route('orders.corrections.store', 'ms-1'), [
            'product_id' => $product->id,
            'store_id'   => $store->id,
            'fact'       => 38,
        ]);

        expect(OrderStockCorrection::first()->user_id)->toBe($user->id);
    });

    test('отрицательный факт отклоняется', function () {
        ['product' => $product, 'store' => $store, 'dept' => $dept] = correctionFixture(45);
        $user = correctionMaster($dept);

        $this->actingAs($user)
            ->from(route('orders.show', 'ms-1'))
            ->post(route('orders.corrections.store', 'ms-1'), [
                'product_id' => $product->id,
                'store_id'   => $store->id,
                'fact'       => -5,
            ])
            ->assertSessionHasErrors('fact');

        expect(OrderStockCorrection::count())->toBe(0);
    });

    test('мастер чужого отдела получает 403', function () {
        ['product' => $product, 'store' => $store] = correctionFixture(45);
        $other = Department::create(['name' => 'Отдел 2', 'is_active' => true]);
        $user = correctionMaster($other);

        $this->actingAs($user)
            ->post(route('orders.corrections.store', 'ms-1'), [
                'product_id' => $product->id,
                'store_id'   => $store->id,
                'fact'       => 38,
            ])
            ->assertForbidden();

        expect(OrderStockCorrection::count())->toBe(0);
    });

    test('сброс уточнения удаляет поправку', function () {
        ['order' => $order, 'product' => $product, 'store' => $store, 'dept' => $dept] = correctionFixture(45);
        $user = correctionMaster($dept);
        (new OrderStockCorrectionService())->set($order, $product->id, $store->id, 38, null, null);

        $this->actingAs($user)
            ->from(route('orders.show', 'ms-1'))
            ->delete(route('orders.corrections.destroy', ['ms-1', $product->id]), ['store_id' => $store->id])
            ->assertSessionHas('success');

        expect(OrderStockCorrection::count())->toBe(0);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Отображение
// ══════════════════════════════════════════════════════════════════════════════

describe('Уточнённый остаток в интерфейсе', function () {

    test('карточка заявки показывает поправленное число и дефицит от него', function () {
        ['order' => $order, 'product' => $product, 'store' => $store, 'dept' => $dept] = correctionFixture(45);
        $user = correctionMaster($dept);

        // Нужно 100, на складе 45 → не хватает 55. После уточнения 38 → не хватает 62.
        (new OrderStockCorrectionService())->set($order, $product->id, $store->id, 38, null, null);

        $this->actingAs($user)
            ->get(route('orders.show', 'ms-1'))
            ->assertSuccessful()
            ->assertSee('не хватает 62.0')
            ->assertDontSee('не хватает 55.0');
    });

    test('список заявок показывает то же поправленное число', function () {
        ['order' => $order, 'product' => $product, 'store' => $store, 'dept' => $dept] = correctionFixture(45);
        $user = correctionMaster($dept);

        (new OrderStockCorrectionService())->set($order, $product->id, $store->id, 38, null, null);

        $this->actingAs($user)
            ->get(route('orders.index'))
            ->assertSuccessful()
            ->assertSee('38.0');
    });

    test('без поправки показывается остаток МойСклад', function () {
        ['dept' => $dept] = correctionFixture(45);
        $user = correctionMaster($dept);

        $this->actingAs($user)
            ->get(route('orders.show', 'ms-1'))
            ->assertSuccessful()
            ->assertSee('не хватает 55.0');
    });
});
