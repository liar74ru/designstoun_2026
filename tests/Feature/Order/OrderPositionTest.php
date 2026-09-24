<?php

use App\Models\Department;
use App\Models\DepartmentOperationSetting;
use App\Models\Order;
use App\Models\OrderPositionSetting;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Worker;
use App\Services\OrderPositionService;

/**
 * Заявка отдела с товаром и остатками на двух складах.
 *
 * @return array{order: Order, product: Product, main: Store, extra: Store, dept: Department}
 */
function positionFixture(float $mainStock = 45.0, float $extraStock = 10.0): array
{
    $main  = Store::factory()->create(['name' => 'Основной']);
    $extra = Store::factory()->create(['name' => 'Дополнительный']);

    $dept = Department::create([
        'name'                        => 'Отдел',
        'is_active'                   => true,
        'default_production_store_id' => $main->id,
    ]);

    $product = Product::factory()->create(['name' => 'Плитняк Кварцит 20-40']);
    ProductStock::create(['product_id' => $product->id, 'store_id' => $main->id, 'quantity' => $mainStock]);
    ProductStock::create(['product_id' => $product->id, 'store_id' => $extra->id, 'quantity' => $extraStock]);

    $order = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1', 'state_name' => 'Новый']);
    $order->departments()->attach($dept->id);
    $order->items()->create(['product_id' => $product->id, 'quantity' => 100, 'shipped' => 0]);

    return compact('order', 'product', 'main', 'extra', 'dept');
}

function positionMaster(Department $dept): User
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

/** Строка позиции так, как её увидит шаблон. */
function positionRow(Order $order, ?string $defaultStoreId, array $produced = []): array
{
    return app(OrderPositionService::class)
        ->rows($order->fresh(['items.product.stocks', 'positionSettings']), $defaultStoreId, $produced)
        ->first();
}

// ══════════════════════════════════════════════════════════════════════════════
// Склады позиции
// ══════════════════════════════════════════════════════════════════════════════

describe('Склады комплектации', function () {

    test('без настройки берётся склад заявки', function () {
        ['order' => $order, 'main' => $main] = positionFixture(45, 10);

        $row = positionRow($order, $main->id);

        expect($row['stores'])->toBe([$main->id])
            ->and($row['warehouseQty'])->toBe(45.0);
    });

    test('остаток суммируется по всем выбранным складам', function () {
        ['order' => $order, 'product' => $product, 'main' => $main, 'extra' => $extra] = positionFixture(45, 10);

        app(OrderPositionService::class)
            ->save($order, $product, [$main->id, $extra->id], null, null, null, null);

        expect(positionRow($order, $main->id)['warehouseQty'])->toBe(55.0);
    });

    test('склад с нулевым остатком в выбор не предлагается', function () {
        ['order' => $order, 'main' => $main, 'extra' => $extra] = positionFixture(45, 0);

        expect(positionRow($order, $main->id)['visibleStores'])->toBe([$main->id])
            ->and(positionRow($order, $main->id)['visibleStores'])->not->toContain($extra->id);
    });

    test('когда ноль везде, остаётся склад по умолчанию', function () {
        ['order' => $order, 'main' => $main] = positionFixture(0, 0);

        expect(positionRow($order, $main->id)['visibleStores'])->toBe([$main->id]);
    });

    test('отмеченный склад остаётся в выборе, даже если на нём ноль', function () {
        ['order' => $order, 'product' => $product, 'main' => $main, 'extra' => $extra] = positionFixture(45, 0);

        app(OrderPositionService::class)
            ->save($order, $product, [$main->id, $extra->id], null, null, null, null);

        expect(positionRow($order, $main->id)['visibleStores'])->toContain($extra->id);
    });

    test('склад с нулём в остатке, но с изготовленным, остаётся в выборе', function () {
        ['order' => $order, 'main' => $main, 'extra' => $extra, 'product' => $product] = positionFixture(45, 0);
        $order->update(['production_started_at' => now()->subDay()]);

        $row = positionRow($order, $main->id, [$product->id => [$extra->id => 12.0]]);

        expect($row['visibleStores'])->toContain($extra->id);
    });

    test('склад, не отмеченный мастером, в сумму не входит', function () {
        ['order' => $order, 'product' => $product, 'main' => $main, 'extra' => $extra] = positionFixture(45, 10);

        app(OrderPositionService::class)
            ->save($order, $product, [$extra->id], null, null, null, null);

        expect(positionRow($order, $main->id)['warehouseQty'])->toBe(10.0);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Поправки мастера
// ══════════════════════════════════════════════════════════════════════════════

describe('OrderPositionService: поправки', function () {

    test('поправка к остатку хранится дельтой от расчётного значения', function () {
        ['order' => $order, 'product' => $product, 'main' => $main] = positionFixture(45);

        $setting = app(OrderPositionService::class)
            ->save($order, $product, [$main->id], 38, null, 'бой', null);

        expect((float) $setting->stock_delta)->toBe(-7.0)
            ->and((float) $setting->stock_base)->toBe(45.0)
            ->and($setting->note)->toBe('бой');
    });

    test('повторное уточнение заменяет поправку, а не складывается с ней', function () {
        ['order' => $order, 'product' => $product, 'main' => $main] = positionFixture(45);
        $service = app(OrderPositionService::class);

        $service->save($order, $product, [$main->id], 38, null, null, null);
        $setting = $service->save($order, $product, [$main->id], 42, null, null, null);

        expect((float) $setting->stock_delta)->toBe(-3.0)
            ->and(OrderPositionSetting::count())->toBe(1);
    });

    test('поправка к изготовленному считается от автоматического значения', function () {
        ['order' => $order, 'product' => $product, 'main' => $main] = positionFixture(45);

        $setting = app(OrderPositionService::class)->save(
            $order, $product, [$main->id], null, 30, null, null,
            [$main->id => 25.0],
        );

        expect((float) $setting->produced_delta)->toBe(5.0)
            ->and((float) $setting->produced_base)->toBe(25.0)
            ->and((float) $setting->stock_delta)->toBe(0.0);
    });

    test('пустое поле факта поправку не трогает', function () {
        ['order' => $order, 'product' => $product, 'main' => $main] = positionFixture(45);
        $service = app(OrderPositionService::class);

        $service->save($order, $product, [$main->id], 38, null, null, null);
        $setting = $service->save($order, $product, [$main->id], null, 12, null, null);

        expect((float) $setting->stock_delta)->toBe(-7.0)
            ->and((float) $setting->produced_delta)->toBe(12.0);
    });

    test('сброс удаляет настройки позиции', function () {
        ['order' => $order, 'product' => $product, 'main' => $main] = positionFixture(45);
        $service = app(OrderPositionService::class);

        $service->save($order, $product, [$main->id], 38, null, null, null);
        $service->reset($order, $product->id);

        expect(OrderPositionSetting::count())->toBe(0);
    });

    test('сброс сохраняет снимок остатка — он не относится к правкам мастера', function () {
        ['order' => $order, 'product' => $product, 'main' => $main] = positionFixture(45);
        $service = app(OrderPositionService::class);

        $service->save($order, $product, [$main->id], 38, null, null, null);
        OrderPositionSetting::first()->update(['frozen_stocks' => [$main->id => 45.0]]);

        $service->reset($order, $product->id);

        $setting = OrderPositionSetting::first();
        expect($setting)->not->toBeNull()
            // toEqual, а не toBe: JSON-обратка отдаёт 45 целым
            ->and($setting->frozen_stocks)->toEqual([$main->id => 45.0])
            ->and((float) $setting->stock_delta)->toBe(0.0)
            ->and($setting->stores)->toBeNull();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Всего = склад + изготовлено
// ══════════════════════════════════════════════════════════════════════════════

describe('Итоговые числа позиции', function () {

    test('всего — это склад плюс изготовленное', function () {
        ['order' => $order, 'main' => $main, 'product' => $product] = positionFixture(45);
        $order->update(['production_started_at' => now()->subDay()]);

        $row = positionRow($order, $main->id, [$product->id => [$main->id => 20.0]]);

        expect($row['warehouseQty'])->toBe(45.0)
            ->and($row['producedQty'])->toBe(20.0)
            ->and($row['totalQty'])->toBe(65.0);
    });

    test('дефицит считается от «Всего», а не от одного склада', function () {
        ['order' => $order, 'main' => $main, 'product' => $product] = positionFixture(45);
        $order->update(['production_started_at' => now()->subDay()]);

        // Нужно 100: на складе 45, изготовлено 20 → не хватает 35
        $row = positionRow($order, $main->id, [$product->id => [$main->id => 20.0]]);

        expect($row['short'])->toBe(35.0);
    });

    test('вне производства изготовленное не считается', function () {
        ['order' => $order, 'main' => $main, 'product' => $product] = positionFixture(45);

        $row = positionRow($order, $main->id, [$product->id => [$main->id => 20.0]]);

        expect($row['producedQty'])->toBe(0.0)
            ->and($row['totalQty'])->toBe(45.0);
    });

    test('позиция без товара считается прочерком', function () {
        ['order' => $order, 'main' => $main] = positionFixture(45);
        $order->items()->delete();
        $order->items()->create(['product_id' => null, 'product_name' => 'Неизвестный', 'quantity' => 5]);

        $row = positionRow($order, $main->id);

        expect($row['warehouseQty'])->toBeNull()
            ->and($row['totalQty'])->toBeNull()
            ->and($row['name'])->toBe('Неизвестный');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Маршруты
// ══════════════════════════════════════════════════════════════════════════════

describe('OrderController: настройка позиции', function () {

    test('мастер сохраняет склады и уточнение', function () {
        ['order' => $order, 'product' => $product, 'main' => $main, 'extra' => $extra, 'dept' => $dept]
            = positionFixture(45, 10);
        $user = positionMaster($dept);

        $this->actingAs($user)
            ->from(route('orders.show', 'ms-1'))
            ->post(route('orders.position.update', 'ms-1'), [
                'product_id' => $product->id,
                'stores'     => [$main->id, $extra->id],
                'fact'       => 50,
            ])
            ->assertRedirect(route('orders.show', 'ms-1'))
            ->assertSessionHas('success');

        $setting = OrderPositionSetting::first();
        expect($setting->stores)->toBe([$main->id, $extra->id])
            // База — 45 + 10, значит поправка −5
            ->and((float) $setting->stock_delta)->toBe(-5.0)
            ->and($setting->user_id)->toBe($user->id);
    });

    test('без складов запрос отклоняется', function () {
        ['product' => $product, 'dept' => $dept] = positionFixture(45);
        $user = positionMaster($dept);

        $this->actingAs($user)
            ->from(route('orders.show', 'ms-1'))
            ->post(route('orders.position.update', 'ms-1'), [
                'product_id' => $product->id,
                'stores'     => [],
                'fact'       => 38,
            ])
            ->assertSessionHasErrors('stores');

        expect(OrderPositionSetting::count())->toBe(0);
    });

    test('отрицательный факт отклоняется', function () {
        ['product' => $product, 'main' => $main, 'dept' => $dept] = positionFixture(45);
        $user = positionMaster($dept);

        $this->actingAs($user)
            ->from(route('orders.show', 'ms-1'))
            ->post(route('orders.position.update', 'ms-1'), [
                'product_id' => $product->id,
                'stores'     => [$main->id],
                'fact'       => -5,
            ])
            ->assertSessionHasErrors('fact');

        expect(OrderPositionSetting::count())->toBe(0);
    });

    test('мастер чужого отдела получает 403', function () {
        ['product' => $product, 'main' => $main] = positionFixture(45);
        $other = Department::create(['name' => 'Отдел 2', 'is_active' => true]);
        $user = positionMaster($other);

        $this->actingAs($user)
            ->post(route('orders.position.update', 'ms-1'), [
                'product_id' => $product->id,
                'stores'     => [$main->id],
                'fact'       => 38,
            ])
            ->assertForbidden();

        expect(OrderPositionSetting::count())->toBe(0);
    });

    test('сброс снимает уточнение', function () {
        ['order' => $order, 'product' => $product, 'main' => $main, 'dept' => $dept] = positionFixture(45);
        $user = positionMaster($dept);
        app(OrderPositionService::class)->save($order, $product, [$main->id], 38, null, null, null);

        $this->actingAs($user)
            ->from(route('orders.show', 'ms-1'))
            ->delete(route('orders.position.destroy', ['ms-1', $product->id]))
            ->assertSessionHas('success');

        expect(OrderPositionSetting::count())->toBe(0);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Отображение
// ══════════════════════════════════════════════════════════════════════════════

describe('Уточнённые числа в интерфейсе', function () {

    test('карточка показывает поправленный остаток и дефицит от него', function () {
        ['order' => $order, 'product' => $product, 'main' => $main, 'dept' => $dept] = positionFixture(45);
        $user = positionMaster($dept);

        // Нужно 100, на складе 45 → не хватает 55. После уточнения 38 → не хватает 62.
        app(OrderPositionService::class)->save($order, $product, [$main->id], 38, null, null, null);

        $this->actingAs($user)
            ->get(route('orders.show', 'ms-1'))
            ->assertSuccessful()
            ->assertSee('не хватает 62.0')
            ->assertDontSee('не хватает 55.0');
    });

    test('без уточнения показывается расчётный остаток', function () {
        ['dept' => $dept] = positionFixture(45);
        $user = positionMaster($dept);

        $this->actingAs($user)
            ->get(route('orders.show', 'ms-1'))
            ->assertSuccessful()
            ->assertSee('не хватает 55.0');
    });

    test('список заявок показывает то же «Всего»', function () {
        ['order' => $order, 'product' => $product, 'main' => $main, 'extra' => $extra, 'dept' => $dept]
            = positionFixture(45, 10);
        $user = positionMaster($dept);

        app(OrderPositionService::class)->save($order, $product, [$main->id, $extra->id], null, null, null, null);

        $this->actingAs($user)
            ->get(route('orders.index'))
            ->assertSuccessful()
            ->assertSee('55.0');
    });
});
