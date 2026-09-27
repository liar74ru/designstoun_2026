<?php

use App\Models\Department;
use App\Models\Order;
use App\Models\OrderPositionSetting;
use App\Models\OrderState;
use App\Models\Product;
use App\Services\OrderPositionService;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

/**
 * Смешанная заявка Цеха и Карьера: позиция Цеха, позиция Карьера и общая.
 *
 * @return array{order: Order, ceh: Department, kar: Department, cehItem: Product, karItem: Product, common: Product}
 */
function hiddenFixture(): array
{
    $ceh = Department::create(['name' => 'Цех', 'is_active' => true]);
    $kar = Department::create(['name' => 'Карьер', 'is_active' => true]);

    $order = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);
    $order->departments()->attach([$ceh->id, $kar->id]);

    $cehItem = Product::factory()->create(['name' => 'Плитка Цеха']);
    $karItem = Product::factory()->create(['name' => 'Бут Карьера']);
    $common  = Product::factory()->create(['name' => 'Общий товар']);

    foreach ([$cehItem, $karItem, $common] as $product) {
        $order->items()->create(['product_id' => $product->id, 'quantity' => 10, 'shipped' => 0]);
    }

    return compact('order', 'ceh', 'kar', 'cehItem', 'karItem', 'common');
}

/** Скрыть позицию для отдела напрямую через сервис. */
function hidePosition(Order $order, Product $product, Department $dept, bool $hidden = true): void
{
    app(OrderPositionService::class)->setHidden($order, $product->id, $dept->id, $hidden);
}

/** Названия позиций заявки в списке. */
function visiblePositions($response, Order $order): array
{
    return $response->viewData('rowsByOrder')[$order->id]->pluck('name')->sort()->values()->all();
}

function hideUrl(Order $order, Product $product): string
{
    return route('orders.position.hidden', [$order->moysklad_id, $product->id]);
}

beforeEach(function () {
    // Справочник статусов пуст — фильтр статусов в списке не мешает
    OrderState::query()->delete();
});

// ══════════════════════════════════════════════════════════════════════════════
// Список заявок
// ══════════════════════════════════════════════════════════════════════════════

describe('Список заявок: скрытые позиции', function () {

    test('мастер не видит позицию, скрытую для его отдела', function () {
        $f = hiddenFixture();
        hidePosition($f['order'], $f['karItem'], $f['ceh']);

        $response = $this->actingAs(Access::master($f['ceh'], 'orders'))
            ->get(route('orders.index'))
            ->assertSuccessful()
            ->assertSee('+ 1 скрыто');

        expect(visiblePositions($response, $f['order']))->toBe(['Общий товар', 'Плитка Цеха'])
            ->and($response->viewData('hiddenCountByOrder')[$f['order']->id])->toBe(1);
    });

    test('«Показывать скрытые позиции» выводит их приглушёнными', function () {
        $f = hiddenFixture();
        hidePosition($f['order'], $f['karItem'], $f['ceh']);

        $response = $this->actingAs(Access::master($f['ceh'], 'orders'))
            ->get(route('orders.index', ['filter' => ['show_hidden' => 1]]))
            ->assertSuccessful()
            ->assertSee('is-hidden')
            ->assertDontSee('+ 1 скрыто');

        expect(visiblePositions($response, $f['order']))->toBe(['Бут Карьера', 'Общий товар', 'Плитка Цеха']);
    });

    test('скрытие для Цеха не влияет на мастера Карьера', function () {
        $f = hiddenFixture();
        hidePosition($f['order'], $f['cehItem'], $f['kar']);
        hidePosition($f['order'], $f['karItem'], $f['ceh']);

        $response = $this->actingAs(Access::master($f['kar'], 'orders'))
            ->get(route('orders.index'))
            ->assertSuccessful();

        expect(visiblePositions($response, $f['order']))->toBe(['Бут Карьера', 'Общий товар']);
    });

    test('общая позиция видна обоим мастерам', function () {
        $f = hiddenFixture();
        hidePosition($f['order'], $f['cehItem'], $f['kar']);
        hidePosition($f['order'], $f['karItem'], $f['ceh']);

        foreach ([$f['ceh'], $f['kar']] as $dept) {
            $response = $this->actingAs(Access::master($dept, 'orders'))
                ->get(route('orders.index'))
                ->assertSuccessful();

            expect(visiblePositions($response, $f['order']))->toContain('Общий товар');
        }
    });

    test('позиция, скрытая для обоих отделов, не видна ни одному мастеру', function () {
        $f = hiddenFixture();
        hidePosition($f['order'], $f['common'], $f['ceh']);
        hidePosition($f['order'], $f['common'], $f['kar']);

        foreach ([$f['ceh'], $f['kar']] as $dept) {
            $response = $this->actingAs(Access::master($dept, 'orders'))
                ->get(route('orders.index'))
                ->assertSuccessful();

            expect(visiblePositions($response, $f['order']))->not->toContain('Общий товар');
        }
    });

    test('мастер двух отделов видит позицию, скрытую только для одного из них', function () {
        $f = hiddenFixture();
        hidePosition($f['order'], $f['karItem'], $f['ceh']);

        Access::allowOperation($f['kar'], 'orders');
        $master = Access::master($f['ceh'], 'orders');
        $master->worker->departments()->attach($f['kar']->id);

        $response = $this->actingAs($master->fresh())
            ->get(route('orders.index'))
            ->assertSuccessful();

        expect(visiblePositions($response, $f['order']))->toContain('Бут Карьера');
    });

    test('отдел в фильтре задаёт, чьими глазами смотрит мастер двух отделов', function () {
        $f = hiddenFixture();
        hidePosition($f['order'], $f['karItem'], $f['ceh']);

        Access::allowOperation($f['kar'], 'orders');
        $master = Access::master($f['ceh'], 'orders');
        $master->worker->departments()->attach($f['kar']->id);

        $response = $this->actingAs($master->fresh())
            ->get(route('orders.index', ['filter' => ['department_id' => [$f['ceh']->id]]]))
            ->assertSuccessful();

        expect(visiblePositions($response, $f['order']))->not->toContain('Бут Карьера');
    });

    test('админ без выбранного отдела видит все позиции с плашкой «Скрыто»', function () {
        $f = hiddenFixture();
        hidePosition($f['order'], $f['karItem'], $f['ceh']);

        $response = $this->actingAs(H::adminUser())
            ->get(route('orders.index'))
            ->assertSuccessful()
            ->assertSee('Скрыто:');

        expect(visiblePositions($response, $f['order']))->toBe(['Бут Карьера', 'Общий товар', 'Плитка Цеха']);
    });

    test('админ с выбранным отделом не видит позиции, скрытые для него', function () {
        $f = hiddenFixture();
        hidePosition($f['order'], $f['karItem'], $f['ceh']);

        $response = $this->actingAs(H::adminUser())
            ->get(route('orders.index', ['filter' => ['department_id' => [$f['ceh']->id]]]))
            ->assertSuccessful();

        expect(visiblePositions($response, $f['order']))->toBe(['Общий товар', 'Плитка Цеха']);
    });

    test('заявка со всеми скрытыми позициями остаётся в списке с пометкой', function () {
        $f = hiddenFixture();
        foreach ([$f['cehItem'], $f['karItem'], $f['common']] as $product) {
            hidePosition($f['order'], $product, $f['ceh']);
        }

        $response = $this->actingAs(Access::master($f['ceh'], 'orders'))
            ->get(route('orders.index'))
            ->assertSuccessful()
            ->assertSee('Все позиции скрыты для вашего отдела (3)');

        expect(collect($response->viewData('orders')->items())->pluck('name')->all())->toBe(['Заявка 1']);
    });

    test('отметка отдела, снятого с заявки, ничего не прячет', function () {
        $f = hiddenFixture();
        hidePosition($f['order'], $f['karItem'], $f['ceh']);
        $f['order']->departments()->detach($f['ceh']->id);

        $response = $this->actingAs(H::adminUser())
            ->get(route('orders.index', ['filter' => ['department_id' => [$f['ceh']->id, $f['kar']->id]]]))
            ->assertSuccessful();

        expect(visiblePositions($response, $f['order']))->toContain('Бут Карьера');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// OrderController::updateHidden()
// ══════════════════════════════════════════════════════════════════════════════

describe('OrderController::updateHidden()', function () {

    test('мастер скрывает позицию для своего отдела и возвращает её', function () {
        $f = hiddenFixture();
        $master = Access::master($f['ceh'], 'orders');

        $this->actingAs($master)
            ->postJson(hideUrl($f['order'], $f['karItem']), ['department_id' => $f['ceh']->id, 'hidden' => true])
            ->assertOk()
            ->assertJson(['success' => true, 'hiddenFor' => [$f['ceh']->id]]);

        $this->actingAs($master)
            ->postJson(hideUrl($f['order'], $f['karItem']), ['department_id' => $f['ceh']->id, 'hidden' => false])
            ->assertOk()
            ->assertJson(['hiddenFor' => []]);

        expect(OrderPositionSetting::where('product_id', $f['karItem']->id)->first()->hidden_department_ids)->toBeNull();
    });

    test('отметки разных отделов копятся независимо', function () {
        $f = hiddenFixture();

        $this->actingAs(Access::master($f['ceh'], 'orders'))
            ->postJson(hideUrl($f['order'], $f['common']), ['department_id' => $f['ceh']->id, 'hidden' => true])
            ->assertOk();

        $this->actingAs(Access::master($f['kar'], 'orders'))
            ->postJson(hideUrl($f['order'], $f['common']), ['department_id' => $f['kar']->id, 'hidden' => true])
            ->assertOk()
            ->assertJson(['hiddenFor' => [$f['ceh']->id, $f['kar']->id]]);
    });

    test('мастер не может скрыть позицию для чужого отдела', function () {
        $f = hiddenFixture();

        $this->actingAs(Access::master($f['ceh'], 'orders'))
            ->postJson(hideUrl($f['order'], $f['karItem']), ['department_id' => $f['kar']->id, 'hidden' => true])
            ->assertForbidden();

        expect(OrderPositionSetting::count())->toBe(0);
    });

    test('админ может скрыть позицию для любого отдела заявки', function () {
        $f = hiddenFixture();

        $this->actingAs(H::adminUser())
            ->postJson(hideUrl($f['order'], $f['cehItem']), ['department_id' => $f['kar']->id, 'hidden' => true])
            ->assertOk()
            ->assertJson(['hiddenFor' => [$f['kar']->id]]);
    });

    test('отдел вне заявки отклоняется', function () {
        $f = hiddenFixture();
        $other = Department::create(['name' => 'Прочий', 'is_active' => true]);

        $this->actingAs(H::adminUser())
            ->postJson(hideUrl($f['order'], $f['cehItem']), ['department_id' => $other->id, 'hidden' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('department_id');
    });

    test('товар не из заявки — 404', function () {
        $f = hiddenFixture();
        $foreign = Product::factory()->create();

        $this->actingAs(H::adminUser())
            ->postJson(hideUrl($f['order'], $foreign), ['department_id' => $f['ceh']->id, 'hidden' => true])
            ->assertNotFound();
    });

    test('мастер отдела не из заявки получает 403', function () {
        $f = hiddenFixture();
        $other = Department::create(['name' => 'Прочий', 'is_active' => true]);

        $this->actingAs(Access::master($other, 'orders'))
            ->postJson(hideUrl($f['order'], $f['cehItem']), ['department_id' => $f['ceh']->id, 'hidden' => true])
            ->assertForbidden();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Карточка заявки и сброс уточнений
// ══════════════════════════════════════════════════════════════════════════════

describe('Карточка заявки: скрытые позиции', function () {

    test('в карточке видны все позиции, скрытая приглушена', function () {
        $f = hiddenFixture();
        hidePosition($f['order'], $f['karItem'], $f['ceh']);

        $this->actingAs(Access::master($f['ceh'], 'orders'))
            ->get(route('orders.show', $f['order']->moysklad_id))
            ->assertSuccessful()
            ->assertSee('Бут Карьера')
            ->assertSee('is-hidden')
            ->assertSee('hide-toggle-item', false);
    });

    test('мастеру предлагаются только его отделы заявки', function () {
        $f = hiddenFixture();

        $response = $this->actingAs(Access::master($f['ceh'], 'orders'))
            ->get(route('orders.show', $f['order']->moysklad_id))
            ->assertSuccessful();

        expect($response->viewData('hideDepartments')->pluck('id')->all())->toBe([$f['ceh']->id]);
    });

    test('«Снять уточнения» не сбрасывает скрытие', function () {
        $f = hiddenFixture();
        hidePosition($f['order'], $f['karItem'], $f['ceh']);

        app(OrderPositionService::class)->reset($f['order'], $f['karItem']->id);

        $setting = OrderPositionSetting::where('product_id', $f['karItem']->id)->first();
        expect($setting)->not->toBeNull()
            ->and($setting->hiddenDepartmentIds())->toBe([$f['ceh']->id]);
    });
});
