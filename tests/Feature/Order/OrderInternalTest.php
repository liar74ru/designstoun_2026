<?php

use App\Models\Department;
use App\Models\Order;
use App\Models\OrderPositionSetting;
use App\Models\OrderState;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Store;
use App\Models\User;
use App\Models\WorkshopPreset;
use App\Services\InternalOrderService;
use App\Services\OrderPositionService;
use App\Services\OrderProductionService;
use App\Services\OrderService;
use App\Support\OrderPriority;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

/**
 * Внутренние заказы: отдел-заказчик заказывает отделу-исполнителю полуфабрикаты под заявку
 * покупателя целиком. В МойСклад не выгружаются, живут в общей очереди заказов.
 */

const INT_WORK    = '11111111-cccc-1111-1111-111111111111';
const INT_NEW     = '22222222-cccc-2222-2222-222222222222';
const INT_CHANGED = '33333333-cccc-3333-3333-333333333333';

beforeEach(function () {
    Http::fake();
    OrderState::create(['id' => INT_NEW, 'name' => 'Новый', 'is_enabled' => true, 'track_changes' => false, 'is_default_filter' => true, 'position' => 0]);
    OrderState::create(['id' => INT_WORK, 'name' => 'В процессе', 'is_enabled' => true, 'is_production' => true, 'is_default_filter' => true, 'position' => 1]);
    OrderState::create(['id' => INT_CHANGED, 'name' => 'Изменено', 'is_enabled' => true, 'is_changed' => true, 'position' => 2]);
    Order::forgetStateCache();
});

/**
 * Заявка «Галтовки» 00100 в работе: «Галтованный камень» × 10. Плитку для неё пилит «Цех».
 *
 * @return array{galt: Department, shop: Department, galtMaster: User, shopMaster: User,
 *               parent: Order, final: Product, tile: Product}
 */
function internalFixture(): array
{
    $galt = Access::department('Галтовка');
    $shop = Access::department('Цех');
    $galtMaster = Access::master($galt, 'orders', 'Мастер Галтовки');
    $shopMaster = Access::master($shop, 'orders', 'Мастер Цеха');

    $final = H::product(['name' => 'Галтованный камень', 'sku' => '04-01-10', 'moysklad_id' => 'pf-1']);
    $tile  = H::product(['name' => 'Плитка 30', 'sku' => '04-01-30', 'moysklad_id' => 'pt-1']);

    $parent = Order::create([
        'moysklad_id'         => 'ms-parent',
        'name'                => '00100',
        'state_moysklad_id'   => INT_WORK,
        'state_name'          => 'В процессе',
        'moment'              => now()->subDay(),
        'delivery_planned_at' => now()->addWeek()->startOfDay(),
    ]);
    $parent->items()->create(['product_id' => $final->id, 'product_moysklad_id' => 'pf-1', 'product_name' => $final->name, 'quantity' => 10, 'shipped' => 0]);
    $parent->departments()->attach($galt->id);

    return compact('galt', 'shop', 'galtMaster', 'shopMaster', 'parent', 'final', 'tile');
}

/** Разместить внутренний заказ под заявку фикстуры: по умолчанию «Галтовка» → «Цех», плитка × 15. */
function placeInternal(array $f, array $overrides = [], ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? $f['galtMaster'])->post(route('orders.internal.store', $f['parent']->uuid), array_merge([
        'customer_department_id' => $f['galt']->id,
        'executor_department_id' => $f['shop']->id,
        'state_id'               => INT_WORK,
        'items'                  => [['product_id' => $f['tile']->id, 'quantity' => 15]],
    ], $overrides));
}

/** Правка состава внутреннего заказа от имени заказчика. */
function updateInternal(array $f, Order $order, array $items, ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? $f['galtMaster'])->put(route('orders.internal.update', $order->uuid), [
        'executor_department_id' => $f['shop']->id,
        'items'                  => $items,
    ]);
}

/** Строка позиции заказа с долей в общем пуле — как в карточке. */
function internalPoolRow(Order $order, string $storeId): array
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

describe('Создание', function () {

    test('заказ под заявку: номер, заказчик, исполнитель, основание, позиции', function () {
        $f = internalFixture();

        placeInternal($f, ['items' => [
            ['product_id' => $f['tile']->id, 'quantity' => 15],
            ['product_id' => $f['final']->id, 'quantity' => 2],
        ]])->assertRedirect()->assertSessionHas('success');

        $order = Order::internal()->with('items', 'departments')->sole();

        expect($order->name)->toMatch('/^\d{2}-\d{2}-ВЗ-01$/')
            ->and($order->kind)->toBe(Order::KIND_INTERNAL)
            ->and($order->moysklad_id)->toBeNull()
            ->and($order->uuid)->not->toBeEmpty()
            ->and($order->customer_department_id)->toBe($f['galt']->id)
            ->and($order->departments->pluck('id')->all())->toBe([$f['shop']->id])
            ->and($order->parent_uuid)->toBe('ms-parent')
            ->and($order->parent_order_name)->toBe('00100')
            ->and($order->created_by_user_id)->toBe($f['galtMaster']->id)
            ->and($order->items)->toHaveCount(2)
            ->and($order->items->pluck('product_moysklad_id')->all())->toBe(['pt-1', 'pf-1'])
            ->and((float) $order->items->first()->shipped)->toBe(0.0);
    });

    test('ключ очереди считается по сроку, а не остаётся нулём', function () {
        $f = internalFixture();
        placeInternal($f);

        $order = Order::internal()->sole();

        expect((float) $order->priority_key)
            ->toBe(OrderPriority::autoKey($order->delivery_planned_at, $order->moment));
    });

    test('срок и срочность по умолчанию — как у заявки-основания', function () {
        $f = internalFixture();
        $f['parent']->update(['is_urgent' => true]);

        placeInternal($f);
        $order = Order::internal()->sole();

        expect($order->delivery_planned_at->toDateString())->toBe($f['parent']->delivery_planned_at->toDateString())
            ->and($order->is_urgent)->toBeTrue();
    });

    test('срок из формы заменяет срок основания', function () {
        $f = internalFixture();
        $date = now()->addDays(3)->format('Y-m-d');

        placeInternal($f, ['delivery_planned_at' => $date]);

        expect(Order::internal()->sole()->delivery_planned_at->toDateString())->toBe($date);
    });

    test('производственный статус открывает окно, непроизводственный — нет', function () {
        $f = internalFixture();

        placeInternal($f, ['state_id' => INT_WORK]);
        placeInternal($f, ['state_id' => INT_NEW]);

        [$work, $idle] = Order::internal()->orderBy('id')->get();

        expect($work->production_started_at)->not->toBeNull()
            ->and($idle->production_started_at)->toBeNull();
    });

    test('номер не повторяется после удаления', function () {
        $f = internalFixture();
        placeInternal($f);
        placeInternal($f);

        Order::internal()->where('name', 'like', '%-ВЗ-01')->sole()->delete();
        placeInternal($f);

        expect(Order::internal()->orderBy('id')->pluck('name')->map(fn ($n) => substr($n, -2))->all())
            ->toBe(['02', '03']);
    });

    test('ничего не отправляет в МойСклад заявок', function () {
        $f = internalFixture();
        placeInternal($f);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'customerorder'));
    });

    test('исполнитель совпадает с заказчиком — ошибка', function () {
        $f = internalFixture();

        placeInternal($f, ['executor_department_id' => $f['galt']->id])
            ->assertSessionHasErrors('executor_department_id');

        expect(Order::internal()->count())->toBe(0);
    });

    test('заказчик — только отдел заявки', function () {
        $f = internalFixture();

        placeInternal($f, ['customer_department_id' => $f['shop']->id], H::adminUser())
            ->assertSessionHasErrors('customer_department_id');
    });

    test('мастер не заказывает от чужого отдела', function () {
        $f = internalFixture();
        $f['parent']->departments()->attach($f['shop']->id);

        placeInternal($f, [
            'customer_department_id' => $f['shop']->id,
            'executor_department_id' => Access::department('МАФ')->id,
        ])->assertSessionHasErrors('customer_department_id');
    });

    test('пустой состав и нулевое количество — ошибка', function () {
        $f = internalFixture();

        placeInternal($f, ['items' => []])->assertSessionHasErrors('items');
        placeInternal($f, ['items' => [['product_id' => $f['tile']->id, 'quantity' => 0]]])
            ->assertSessionHasErrors('items.0.quantity');
    });

    test('неиспользуемый статус — ошибка', function () {
        $f = internalFixture();
        OrderState::whereKey(INT_NEW)->update(['is_enabled' => false]);

        placeInternal($f, ['state_id' => INT_NEW])->assertSessionHasErrors('state_id');
    });

    test('под внутренний заказ заказать нельзя — 404', function () {
        $f = internalFixture();
        placeInternal($f);
        $internal = Order::internal()->sole();

        $this->actingAs($f['galtMaster'])->get(route('orders.internal.create', $internal->uuid))->assertNotFound();
        $this->actingAs($f['galtMaster'])->post(route('orders.internal.store', $internal->uuid), [])->assertNotFound();
    });

    test('форму под чужую заявку открыть нельзя', function () {
        $f = internalFixture();

        $this->actingAs($f['shopMaster'])->get(route('orders.internal.create', 'ms-parent'))->assertForbidden();
    });

    test('статус по умолчанию — как у основания, из «Изменено» — первый производственный', function () {
        $f = internalFixture();
        $service = app(InternalOrderService::class);

        expect($service->defaultState($f['parent'])->id)->toBe(INT_WORK);

        $f['parent']->update(['state_moysklad_id' => INT_NEW]);
        expect($service->defaultState($f['parent'])->id)->toBe(INT_NEW);

        $f['parent']->update(['state_moysklad_id' => INT_CHANGED]);
        expect($service->defaultState($f['parent'])->id)->toBe(INT_WORK);
    });
});

describe('Правка состава', function () {

    test('до запуска в работу: состав меняется, статус и «было → стало» — нет', function () {
        $f = internalFixture();
        placeInternal($f, ['state_id' => INT_NEW]);
        $order = Order::internal()->sole();

        updateInternal($f, $order, [['product_id' => $f['tile']->id, 'quantity' => 20]])
            ->assertRedirect()->assertSessionHas('success');

        $order->refresh();
        expect((float) $order->items()->sole()->quantity)->toBe(20.0)
            ->and($order->state_moysklad_id)->toBe(INT_NEW)
            ->and($order->position_changes)->toBeNull();
    });

    test('в работе: количество изменилось — «Изменено», прежний статус запомнен, окно открыто', function () {
        $f = internalFixture();
        placeInternal($f);
        $order = Order::internal()->sole();

        updateInternal($f, $order, [['product_id' => $f['tile']->id, 'quantity' => 20]]);

        $order->refresh();
        expect($order->state_moysklad_id)->toBe(INT_CHANGED)
            ->and($order->state_name)->toBe('Изменено')
            ->and($order->state_before_change)->toBe(INT_WORK)
            ->and($order->position_changes['pt-1'])->toMatchArray(['from' => 15.0, 'to' => 20.0])
            ->and($order->production_started_at)->not->toBeNull()
            ->and($order->production_ended_at)->toBeNull();
    });

    test('повторная правка в «Изменено» копит изменения от исходного количества', function () {
        $f = internalFixture();
        placeInternal($f);
        $order = Order::internal()->sole();

        updateInternal($f, $order, [['product_id' => $f['tile']->id, 'quantity' => 20]]);
        updateInternal($f, $order, [['product_id' => $f['tile']->id, 'quantity' => 25]]);

        $order->refresh();
        expect($order->state_moysklad_id)->toBe(INT_CHANGED)
            ->and($order->state_before_change)->toBe(INT_WORK)
            ->and($order->position_changes['pt-1'])->toMatchArray(['from' => 15.0, 'to' => 25.0]);
    });

    test('количество не менялось — статус не трогаем', function () {
        $f = internalFixture();
        placeInternal($f);
        $order = Order::internal()->sole();

        updateInternal($f, $order, [['product_id' => $f['tile']->id, 'quantity' => 15]]);

        expect($order->refresh()->state_moysklad_id)->toBe(INT_WORK)
            ->and($order->position_changes)->toBeNull();
    });

    test('статус «Изменено» не задан — остаётся только плашка', function () {
        $f = internalFixture();
        OrderState::whereKey(INT_CHANGED)->update(['is_changed' => false]);
        placeInternal($f);
        $order = Order::internal()->sole();

        updateInternal($f, $order, [['product_id' => $f['tile']->id, 'quantity' => 20]]);

        $order->refresh();
        expect($order->state_moysklad_id)->toBe(INT_WORK)
            ->and($order->positions_changed_at)->not->toBeNull();
    });

    test('рост количества снимает отметку «готово»', function () {
        $f = internalFixture();
        placeInternal($f);
        $order = Order::internal()->sole();
        OrderPositionSetting::updateOrCreate(
            ['order_id' => $order->id, 'product_id' => $f['tile']->id],
            ['ready_at' => now()],
        );

        updateInternal($f, $order, [['product_id' => $f['tile']->id, 'quantity' => 20]]);

        expect(OrderPositionSetting::where('order_id', $order->id)->where('product_id', $f['tile']->id)->value('ready_at'))
            ->toBeNull();
    });

    test('товар, добавленный в открытое окно, получает снимок остатка', function () {
        $f = internalFixture();
        $store = Store::factory()->create();
        $extra = H::product(['name' => 'Плитка 50', 'moysklad_id' => 'pt-2']);
        ProductStock::create(['product_id' => $extra->id, 'store_id' => $store->id, 'quantity' => 7]);

        placeInternal($f);
        $order = Order::internal()->sole();

        updateInternal($f, $order, [
            ['product_id' => $f['tile']->id, 'quantity' => 15],
            ['product_id' => $extra->id, 'quantity' => 4],
        ]);

        $setting = OrderPositionSetting::where('order_id', $order->id)->where('product_id', $extra->id)->first();
        expect($setting?->frozen_stocks)->toEqual([$store->id => 7.0]);
    });

    test('исполнитель менять состав не может', function () {
        $f = internalFixture();
        placeInternal($f);
        $order = Order::internal()->sole();

        $this->actingAs($f['shopMaster'])->get(route('orders.internal.edit', $order->uuid))->assertForbidden();
        updateInternal($f, $order, [['product_id' => $f['tile']->id, 'quantity' => 1]], $f['shopMaster'])
            ->assertForbidden();

        expect((float) $order->items()->sole()->quantity)->toBe(15.0);
    });

    test('исполнитель не может совпадать с заказчиком', function () {
        $f = internalFixture();
        placeInternal($f);
        $order = Order::internal()->sole();

        $this->actingAs($f['galtMaster'])->put(route('orders.internal.update', $order->uuid), [
            'executor_department_id' => $f['galt']->id,
            'items'                  => [['product_id' => $f['tile']->id, 'quantity' => 15]],
        ])->assertSessionHasErrors('executor_department_id');
    });

    test('у заявки покупателя правки состава нет — 404', function () {
        $f = internalFixture();

        $this->actingAs(H::adminUser())->get(route('orders.internal.edit', 'ms-parent'))->assertNotFound();
        updateInternal($f, $f['parent'], [['product_id' => $f['tile']->id, 'quantity' => 1]], H::adminUser())
            ->assertNotFound();
    });

    test('форма правки открывается заказчику с текущим составом', function () {
        $f = internalFixture();
        placeInternal($f);
        $order = Order::internal()->sole();

        // Состав уходит в скрипт формы JSON-ом
        $this->actingAs($f['galtMaster'])->get(route('orders.internal.edit', $order->uuid))
            ->assertOk()->assertSee('"product_id":' . $f['tile']->id, false);
    });
});

describe('Статус, срок и исполнитель — только в программе', function () {

    test('смена статуса локальная и двигает окно производства', function () {
        $f = internalFixture();
        placeInternal($f, ['state_id' => INT_NEW]);
        $order = Order::internal()->sole();

        $this->actingAs($f['shopMaster'])->post(route('orders.state.update', $order->uuid), ['state_id' => INT_WORK])
            ->assertSessionHas('success');

        $order->refresh();
        expect($order->state_moysklad_id)->toBe(INT_WORK)
            ->and($order->production_started_at)->not->toBeNull();
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'customerorder'));
    });

    test('смена срока локальная и пересчитывает место в очереди', function () {
        $f = internalFixture();
        placeInternal($f);
        $order = Order::internal()->sole();
        $date = now()->addDays(20);

        $this->actingAs($f['shopMaster'])
            ->post(route('orders.delivery-date.update', $order->uuid), ['delivery_planned_at' => $date->format('Y-m-d')])
            ->assertSessionHas('success');

        $order->refresh();
        expect($order->delivery_planned_at->toDateString())->toBe($date->toDateString())
            ->and((float) $order->priority_key)->toBe(OrderPriority::autoKey($order->delivery_planned_at, $order->moment));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'customerorder'));
    });

    test('исполнителя можно сменить, но не снять всех', function () {
        $f = internalFixture();
        placeInternal($f);
        $order = Order::internal()->sole();
        $maf = Access::department('МАФ');

        $this->actingAs(H::adminUser())->post(route('orders.departments.update', $order->uuid), ['departments' => [$maf->id]])
            ->assertSessionHas('success');
        expect($order->departments()->pluck('departments.id')->all())->toBe([$maf->id]);

        $this->actingAs(H::adminUser())->post(route('orders.departments.update', $order->uuid), ['departments' => []])
            ->assertSessionHas('error');
        expect($order->departments()->count())->toBe(1);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'customerorder'));
    });
});

describe('«Принято»', function () {

    test('исполнитель возвращает заказ в прежний статус, изменения очищаются', function () {
        $f = internalFixture();
        placeInternal($f);
        $order = Order::internal()->sole();
        updateInternal($f, $order, [['product_id' => $f['tile']->id, 'quantity' => 20]]);

        $this->actingAs($f['shopMaster'])->post(route('orders.changes.acknowledge', $order->uuid))
            ->assertSessionHas('success');

        $order->refresh();
        expect($order->state_moysklad_id)->toBe(INT_WORK)
            ->and($order->position_changes)->toBeNull()
            ->and($order->state_before_change)->toBeNull()
            ->and($order->production_ended_at)->toBeNull();
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'customerorder'));
    });

    test('заказчик свою правку принять не может', function () {
        $f = internalFixture();
        placeInternal($f);
        $order = Order::internal()->sole();
        updateInternal($f, $order, [['product_id' => $f['tile']->id, 'quantity' => 20]]);

        $this->actingAs($f['galtMaster'])->post(route('orders.changes.acknowledge', $order->uuid))->assertForbidden();

        $this->actingAs($f['galtMaster'])->get(route('orders.show', $order->uuid))
            ->assertOk()->assertSee('Ждёт, пока исполнитель');
        expect($order->refresh()->state_moysklad_id)->toBe(INT_CHANGED);
    });
});

describe('Удаление', function () {

    test('заказчик удаляет — возврат к заявке-основанию', function () {
        $f = internalFixture();
        placeInternal($f);
        $order = Order::internal()->sole();

        $this->actingAs($f['galtMaster'])->delete(route('orders.internal.destroy', $order->uuid))
            ->assertRedirect(route('orders.show', 'ms-parent'));

        expect(Order::internal()->count())->toBe(0)
            ->and(Order::whereKey($f['parent']->id)->exists())->toBeTrue();
    });

    test('исполнитель удалить не может, админ — может', function () {
        $f = internalFixture();
        placeInternal($f);
        $order = Order::internal()->sole();

        $this->actingAs($f['shopMaster'])->delete(route('orders.internal.destroy', $order->uuid))->assertForbidden();
        expect(Order::internal()->count())->toBe(1);

        $this->actingAs(H::adminUser())->delete(route('orders.internal.destroy', $order->uuid))->assertRedirect();
        expect(Order::internal()->count())->toBe(0);
    });

    test('заявку покупателя так удалить нельзя — 404', function () {
        internalFixture();

        $this->actingAs(H::adminUser())->delete(route('orders.internal.destroy', 'ms-parent'))->assertNotFound();
        expect(Order::where('moysklad_id', 'ms-parent')->exists())->toBeTrue();
    });
});

describe('Видимость и связь с заявкой', function () {

    test('карточку открывают заказчик и исполнитель, третий отдел — нет', function () {
        $f = internalFixture();
        placeInternal($f);
        $order = Order::internal()->sole();
        $other = Access::master(Access::department('МАФ'), 'orders');

        $this->actingAs($f['galtMaster'])->get(route('orders.show', $order->uuid))
            ->assertOk()->assertSee('Изменить состав');
        $this->actingAs($f['shopMaster'])->get(route('orders.show', $order->uuid))
            ->assertOk()->assertSee('Внутренний')->assertSee('под заявку 00100')->assertDontSee('Изменить состав');
        $this->actingAs($other)->get(route('orders.show', $order->uuid))->assertForbidden();
    });

    test('в списке: исполнитель и заказчик видят заказ, третий отдел — нет', function () {
        $f = internalFixture();
        placeInternal($f);
        $name = Order::internal()->sole()->name;
        $other = Access::master(Access::department('МАФ'), 'orders');

        $this->actingAs($f['shopMaster'])->get(route('orders.index'))->assertOk()->assertSee($name);
        $this->actingAs($f['galtMaster'])->get(route('orders.index'))->assertOk()->assertSee($name);
        $this->actingAs($other)->get(route('orders.index'))->assertOk()->assertDontSee($name);
    });

    test('фильтр по типу разделяет заявки и внутренние заказы', function () {
        $f = internalFixture();
        placeInternal($f);
        $order = Order::internal()->sole();
        $admin = H::adminUser();

        // Строку заказа ищем по data-href: у внутреннего есть ссылка «под заявку» на основание.
        $row = fn (string $uuid) => 'data-href="' . route('orders.show', $uuid) . '"';

        $this->actingAs($admin)->get(route('orders.index', ['filter' => ['kind' => 'internal']]))
            ->assertOk()->assertSee($row($order->uuid), false)->assertDontSee($row('ms-parent'), false);
        $this->actingAs($admin)->get(route('orders.index', ['filter' => ['kind' => 'customer']]))
            ->assertOk()->assertSee($row('ms-parent'), false)->assertDontSee($row($order->uuid), false);
    });

    test('фильтр по отделу находит внутренний заказ и по заказчику', function () {
        $f = internalFixture();
        placeInternal($f);
        $order = Order::internal()->sole();

        $this->actingAs(H::adminUser())
            ->get(route('orders.index', ['filter' => ['department_id' => [$f['galt']->id]]]))
            ->assertOk()->assertSee(route('orders.show', $order->uuid));
    });

    test('карточка заявки показывает внутренние заказы: исполнитель, статус, готовность', function () {
        $f = internalFixture();

        $this->actingAs($f['galtMaster'])->get(route('orders.show', 'ms-parent'))
            ->assertOk()->assertSee('Внутренние заказы')->assertSee('не заказаны');

        placeInternal($f);
        $order = Order::internal()->sole();

        $this->actingAs($f['galtMaster'])->get(route('orders.show', 'ms-parent'))
            ->assertOk()
            ->assertSee(route('orders.show', $order->uuid))
            ->assertSee('→ Цех', false)
            ->assertSee('Плитка 30')
            ->assertSee('из 15.0');
    });

    test('в списке у заявки видны номера её внутренних заказов', function () {
        $f = internalFixture();
        placeInternal($f);
        $name = Order::internal()->sole()->name;

        $this->actingAs(H::adminUser())->get(route('orders.index', ['filter' => ['kind' => 'customer']]))
            ->assertOk()->assertSee($name);
    });

    test('основание выпало из выгрузки — пометка, вернулось — связь восстановлена', function () {
        $f = internalFixture();
        placeInternal($f);
        $order = Order::internal()->sole();

        $f['parent']->delete();
        $this->actingAs($f['galtMaster'])->get(route('orders.show', $order->uuid))
            ->assertOk()->assertSee('под заявку 00100 (закрыта)');

        Order::create(['moysklad_id' => 'ms-parent', 'name' => '00100'])->departments()->attach($f['galt']->id);
        $this->actingAs($f['galtMaster'])->get(route('orders.show', $order->uuid))
            ->assertOk()->assertSee(route('orders.show', 'ms-parent'))->assertDontSee('(закрыта)');
    });

    test('ссылки на заявки покупателей строятся по moysklad_id', function () {
        $f = internalFixture();

        expect($f['parent']->uuid)->toBe('ms-parent');
        $this->actingAs($f['galtMaster'])->get('/orders/ms-parent')->assertOk();
    });
});

describe('Общий пул товара', function () {

    test('внутренний заказ делит остаток с заявкой покупателя по очереди', function () {
        $store = Store::factory()->create();
        $shop  = Department::create(['name' => 'Цех', 'is_active' => true, 'default_production_store_id' => $store->id]);
        $tile  = H::product(['name' => 'Плитка 30']);

        $customer = Order::create(['moysklad_id' => 'ms-c', 'name' => 'Заявка', 'production_started_at' => now()->subDays(2), 'priority_key' => 100]);
        $internal = Order::create(['kind' => Order::KIND_INTERNAL, 'name' => 'ВЗ', 'production_started_at' => now()->subDay(), 'priority_key' => 200]);

        foreach ([[$customer, 30], [$internal, 20]] as [$order, $qty]) {
            $order->departments()->attach($shop->id);
            $order->items()->create(['product_id' => $tile->id, 'quantity' => $qty, 'shipped' => 0]);
            OrderPositionSetting::create(['order_id' => $order->id, 'product_id' => $tile->id, 'frozen_stocks' => [$store->id => 10]]);
        }

        expect(internalPoolRow($customer, $store->id)['totalQty'])->toBe(10.0)
            ->and(internalPoolRow($internal, $store->id)['totalQty'])->toBe(0.0)
            ->and(internalPoolRow($internal, $store->id)['short'])->toBe(20.0);

        $internal->update(['is_urgent' => true]);

        expect(internalPoolRow($internal, $store->id)['totalQty'])->toBe(10.0)
            ->and(internalPoolRow($customer, $store->id)['totalQty'])->toBe(0.0);
    });
});

describe('Подсказка из шаблонов цеха', function () {

    test('сырьё по норме на недостающее количество позиции', function () {
        $f = internalFixture();
        $preset = WorkshopPreset::create(['department_id' => $f['galt']->id, 'name' => 'Галтовка 30']);
        $preset->items()->create(['product_id' => $f['final']->id, 'role' => 'product', 'quantity' => 2]);
        $preset->items()->create(['product_id' => $f['tile']->id, 'role' => 'raw', 'quantity' => 3]);

        $suggestions = app(InternalOrderService::class)
            ->presetSuggestions([$f['final']->id => 10.0], [$f['galt']->id]);

        expect($suggestions[$f['final']->id])->toHaveCount(1)
            ->and($suggestions[$f['final']->id][0]['preset'])->toBe('Галтовка 30')
            ->and($suggestions[$f['final']->id][0]['items'][0])
            ->toMatchArray(['product_id' => $f['tile']->id, 'quantity' => 15.0]);
    });

    test('шаблоны других отделов и шаблоны без позиции не подсказываются', function () {
        $f = internalFixture();
        $foreign = WorkshopPreset::create(['department_id' => $f['shop']->id, 'name' => 'Чужой']);
        $foreign->items()->create(['product_id' => $f['final']->id, 'role' => 'product', 'quantity' => 1]);
        $foreign->items()->create(['product_id' => $f['tile']->id, 'role' => 'raw', 'quantity' => 1]);
        $other = WorkshopPreset::create(['department_id' => $f['galt']->id, 'name' => 'Другой продукт']);
        $other->items()->create(['product_id' => $f['tile']->id, 'role' => 'product', 'quantity' => 1]);

        expect(app(InternalOrderService::class)->presetSuggestions([$f['final']->id => 10.0], [$f['galt']->id]))
            ->toBe([]);
    });

    test('форма показывает кнопки шаблонов и «Заполнить по шаблонам»', function () {
        $f = internalFixture();
        $preset = WorkshopPreset::create(['department_id' => $f['galt']->id, 'name' => 'Галтовка 30']);
        $preset->items()->create(['product_id' => $f['final']->id, 'role' => 'product', 'quantity' => 1]);
        $preset->items()->create(['product_id' => $f['tile']->id, 'role' => 'raw', 'quantity' => 2]);

        $this->actingAs($f['galtMaster'])->get(route('orders.internal.create', 'ms-parent'))
            ->assertOk()
            ->assertSee('Из шаблона «Галтовка 30»', false)
            ->assertSee('Заполнить по шаблонам');
    });
});
