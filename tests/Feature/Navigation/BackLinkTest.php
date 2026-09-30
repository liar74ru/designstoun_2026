<?php

use App\Models\Counterparty;
use App\Models\Order;
use App\Models\RawMaterialBatch;
use App\Models\StoneReception;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Models\Workshop;
use App\Models\Worker;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

/**
 * Кнопка «Назад»: сервер отдаёт только родителя по умолчанию (форма → карточка,
 * карточка → список), куда вернуться на самом деле, решает resources/js/back-link.js
 * по истории вкладки. Здесь — серверная часть: у каждой страницы верный родитель, он не
 * зависит от предыдущей страницы, формы помечены как «проходные».
 */

/**
 * Адреса всех ссылок «Назад» страницы — `<a … data-back>` (кнопка шапки на десктопе
 * и телефоне, «Отмена» в форме).
 *
 * @return array<int, string>
 */
function bkHrefs(TestResponse $response): array
{
    preg_match_all('/<a\s[^>]*>/', $response->getContent(), $tags);

    return collect($tags[0])
        ->filter(fn (string $tag) => preg_match('/\sdata-back[\s>]/', $tag))
        ->map(fn (string $tag) => preg_match('/\shref="([^"]*)"/', $tag, $m) ? html_entity_decode($m[1]) : '')
        ->values()
        ->all();
}

/** Все ссылки «Назад» страницы ведут на родителя. */
function bkExpectParent(TestResponse $response, string $parentUrl): void
{
    $response->assertOk();

    expect(bkHrefs($response))->not->toBeEmpty()->each->toBe($parentUrl);
}

/** Новая партия: её можно и править, и передать, и вернуть, и скорректировать. */
function bkBatch(): RawMaterialBatch
{
    return H::newBatch(H::product(), H::store(), H::worker('Пильщиков Пётр', 'Работник'));
}

function bkReception(): StoneReception
{
    return H::reception(bkBatch(), H::worker(), H::cutter(), H::store());
}

function bkWorkshop(): Workshop
{
    return Workshop::create([
        'packer_id'   => Worker::create(['name' => 'Работник', 'position' => 'Работник'])->id,
        'receiver_id' => Worker::create(['name' => 'Мастер', 'position' => 'Мастер'])->id,
        'store_id'    => H::store()->id,
        'status'      => Workshop::STATUS_ACTIVE,
    ]);
}

function bkSupplierOrder(): SupplierOrder
{
    return SupplierOrder::create([
        'number'          => 'BK-01',
        'store_id'        => H::store()->id,
        'counterparty_id' => Counterparty::create(['name' => 'Поставщик', 'moysklad_id' => (string) Str::uuid()])->id,
        'status'          => SupplierOrder::STATUS_NEW,
    ]);
}

describe('Карточка ведёт в свой список', function () {

    test('заявка', function () {
        Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);

        bkExpectParent($this->actingAs(H::adminUser())->get(route('orders.show', 'ms-1')), route('orders.index'));
    });

    test('партия сырья', function () {
        $batch = bkBatch();

        bkExpectParent($this->actingAs(H::adminUser())->get(route('raw-batches.show', $batch)), route('raw-batches.index'));
    });

    test('приёмка', function () {
        $reception = bkReception();

        bkExpectParent(
            $this->actingAs(H::adminUser())->get(route('stone-receptions.show', $reception)),
            route('stone-receptions.index'),
        );
    });

    test('операция цеха', function () {
        $workshop = bkWorkshop();

        bkExpectParent($this->actingAs(H::adminUser())->get(route('workshops.show', $workshop)), route('workshops.index'));
    });

    test('поступление сырья', function () {
        $order = bkSupplierOrder();

        bkExpectParent(
            $this->actingAs(H::adminUser())->get(route('supplier-orders.show', $order)),
            route('supplier-orders.index'),
        );
    });

    test('товар', function () {
        $product = H::product(['moysklad_id' => 'p-1']);

        bkExpectParent($this->actingAs(H::adminUser())->get(route('products.show', 'p-1')), route('products.index'));
    });
});

describe('Форма ведёт в свою карточку', function () {

    test('правка, корректировка, передача и возврат партии', function (string $route) {
        $batch = bkBatch();

        bkExpectParent($this->actingAs(H::adminUser())->get(route($route, $batch)), route('raw-batches.show', $batch));
    })->with(['raw-batches.edit', 'raw-batches.adjust.form', 'raw-batches.transfer.form', 'raw-batches.return.form']);

    test('правка приёмки', function () {
        $reception = bkReception();

        bkExpectParent(
            $this->actingAs(H::adminUser())->get(route('stone-receptions.edit', $reception)),
            route('stone-receptions.show', $reception),
        );
    });

    test('правка операции цеха', function () {
        $workshop = bkWorkshop();

        bkExpectParent(
            $this->actingAs(H::adminUser())->get(route('workshops.edit', $workshop)),
            route('workshops.show', $workshop),
        );
    });

    test('правка поступления', function () {
        $order = bkSupplierOrder();

        bkExpectParent(
            $this->actingAs(H::adminUser())->get(route('supplier-orders.edit', $order)),
            route('supplier-orders.show', $order),
        );
    });

    test('подтверждение синхронизации поступления', function () {
        $order = bkSupplierOrder();

        $response = $this->actingAs(H::adminUser())
            ->withSession(["sync_confirm_{$order->id}" => ['issue' => 'order_missing', 'suggested_name' => null]])
            ->get(route('supplier-orders.sync-confirm', $order));

        bkExpectParent($response, route('supplier-orders.show', $order));
    });

    test('внутренний заказ под заявку', function () {
        $dept   = Access::department('Галтовка');
        $parent = Order::create(['moysklad_id' => 'ms-parent', 'name' => '00100']);
        $parent->departments()->attach($dept->id);

        bkExpectParent(
            $this->actingAs(H::adminUser())->get(route('orders.internal.create', 'ms-parent')),
            route('orders.show', 'ms-parent'),
        );
    });
});

describe('Родитель не зависит от предыдущей страницы', function () {

    // Раньше «К списку» брала url()->previous(): после действия в карточке (смена статуса
    // и т.п. — редирект обратно) предыдущей оказывалась сама карточка.
    test('карточка заявки после действия в ней — всё равно список', function () {
        Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);

        $response = $this->actingAs(H::adminUser())
            ->from(route('orders.show', 'ms-1'))
            ->get(route('orders.show', 'ms-1'));

        bkExpectParent($response, route('orders.index'));
    });

    // Раньше: карточка → «Передать» → сохранить → карточка, и «Назад» вела на форму передачи.
    test('карточка партии после формы передачи — список, а не форма', function () {
        $batch = bkBatch();

        $response = $this->actingAs(H::adminUser())
            ->from(route('raw-batches.transfer.form', $batch))
            ->get(route('raw-batches.show', $batch));

        bkExpectParent($response, route('raw-batches.index'));
    });

    // Карточка товара открывается отовсюду: откуда пришли, подставит back-link.js,
    // сервер больше не запоминает источник в сессии.
    test('товар, открытый из заявки, — родитель список товаров', function () {
        H::product(['moysklad_id' => 'p-1']);
        Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);

        $response = $this->actingAs(H::adminUser())
            ->from(route('orders.show', 'ms-1'))
            ->get(route('products.show', 'p-1'));

        bkExpectParent($response, route('products.index'));
    });
});

describe('Работники и синхронизация', function () {

    test('создание и правка работника ведут в список работников', function () {
        $admin  = H::adminUser();
        $worker = Worker::create(['name' => 'Иванов', 'position' => 'Работник']);

        bkExpectParent($this->actingAs($admin)->get(route('workers.create')), route('workers.index'));
        bkExpectParent($this->actingAs($admin)->get(route('workers.edit', $worker)), route('workers.index'));
    });

    test('профиль: админ — в список работников, работник без доступа к списку — на главную', function () {
        $admin = User::factory()->create([
            'is_admin'  => true,
            'worker_id' => Worker::create(['name' => 'Админ', 'position' => 'Администратор'])->id,
        ]);

        bkExpectParent($this->actingAs($admin)->get(route('workers.edit-user', $admin->worker_id)), route('workers.index'));

        $user = Access::userWithPosition('Работник', Access::department('Цех'));

        bkExpectParent($this->actingAs($user)->get(route('workers.edit-user', $user->worker_id)), route('home'));
    });

    test('синхронизация ведёт на главную', function () {
        bkExpectParent($this->actingAs(H::adminUser())->get(route('sync.index')), route('home'));
    });
});

describe('Формы помечены как проходные', function () {

    test('на форме есть meta nav-transient, на карточке — нет', function () {
        $batch = bkBatch();
        $admin = H::adminUser();

        $this->actingAs($admin)->get(route('raw-batches.transfer.form', $batch))
            ->assertOk()->assertSee('<meta name="nav-transient"', false);
        $this->actingAs($admin)->get(route('raw-batches.show', $batch))
            ->assertOk()->assertDontSee('nav-transient', false);
    });
});
