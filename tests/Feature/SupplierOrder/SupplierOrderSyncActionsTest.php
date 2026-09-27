<?php

use App\Models\Counterparty;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderItem;
use App\Services\Moysklad\MoySkladPurchaseOrderService;
use App\Services\Moysklad\MoySkladSupplyService;
use App\Services\Moysklad\StockSyncService;
use Illuminate\Support\Str;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

// ─── Фабрики и моки (префикс soAct — чтобы не пересечься с SupplierOrderTest) ─

/** Поступление с одной позицией; товар — с moysklad_id, чтобы было что обновлять в остатках. */
function soActOrder(array $attrs = [], float $qty = 3.5): SupplierOrder
{
    $store = H::store('Склад сырья');
    $cp    = Counterparty::create([
        'name'        => 'Поставщик ' . uniqid(),
        'moysklad_id' => (string) Str::uuid(),
    ]);

    $order = SupplierOrder::create(array_merge([
        'number'          => 'SO-' . strtoupper(Str::random(6)),
        'store_id'        => $store->id,
        'counterparty_id' => $cp->id,
        'status'          => SupplierOrder::STATUS_NEW,
    ], $attrs));

    SupplierOrderItem::create([
        'supplier_order_id' => $order->id,
        'product_id'        => H::product([
            'name'        => 'Гранит галтованный ' . uniqid(),
            'moysklad_id' => (string) Str::uuid(),
        ])->id,
        'quantity'          => $qty,
    ]);

    return $order;
}

/** Моки трёх сервисов МойСклад; возвращает их для задания ожиданий в тесте. */
function soActMocks(): array
{
    $po     = Mockery::mock(MoySkladPurchaseOrderService::class);
    $supply = Mockery::mock(MoySkladSupplyService::class);
    $stock  = Mockery::mock(StockSyncService::class);

    app()->instance(MoySkladPurchaseOrderService::class, $po);
    app()->instance(MoySkladSupplyService::class, $supply);
    app()->instance(StockSyncService::class, $stock);

    return [$po, $supply, $stock];
}

function soActPoOk(string $id = 'po-new-uuid'): array
{
    return ['success' => true, 'moysklad_id' => $id, 'message' => 'OK', 'code' => null];
}

function soActFail(string $message = 'Ошибка API', string $code = 'api_error'): array
{
    return ['success' => false, 'moysklad_id' => null, 'supply_moysklad_id' => null, 'message' => $message, 'code' => $code];
}

function soActSupplyOk(string $id = 'supply-new-uuid'): array
{
    return ['success' => true, 'supply_moysklad_id' => $id, 'message' => 'OK', 'code' => null];
}

// ══════════════════════════════════════════════════════════════════════════════
// show()
// ══════════════════════════════════════════════════════════════════════════════

describe('SupplierOrderController show()', function () {

    test('админ видит карточку поступления с поставщиком, складом и позициями', function () {
        $receiver = H::worker('Приёмщиков Иван', 'Мастер');
        $order    = soActOrder([
            'number'      => 'SHOW-01',
            'receiver_id' => $receiver->id,
            'note'        => 'Срочная партия',
            'moysklad_id' => 'po-show-uuid',
            'sync_error'  => 'Таймаут МойСклад',
        ], 7.25);

        $response = $this->actingAs(H::adminUser())
            ->get(route('supplier-orders.show', $order))
            ->assertOk()
            ->assertViewIs('supplier-orders.show')
            ->assertSee('SHOW-01')
            ->assertSee($order->counterparty->name)
            ->assertSee('Склад сырья')
            ->assertSee('Приёмщиков Иван')
            ->assertSee('Срочная партия')
            ->assertSee('po-show-uuid')
            ->assertSee('Таймаут МойСклад')
            ->assertSee($order->items->first()->product->name);

        $viewOrder = $response->viewData('supplierOrder');
        expect($viewOrder->id)->toBe($order->id)
            ->and($viewOrder->relationLoaded('items'))->toBeTrue()
            ->and($viewOrder->items->first()->relationLoaded('product'))->toBeTrue()
            ->and($viewOrder->relationLoaded('counterparty'))->toBeTrue()
            ->and($viewOrder->relationLoaded('receiver'))->toBeTrue();
    });

    test('несуществующее поступление — 404', function () {
        $this->actingAs(H::adminUser())
            ->get(route('supplier-orders.show', (string) Str::uuid()))
            ->assertNotFound();
    });

    test('мастер отдела с включённой операцией открывает карточку', function () {
        $dept  = Access::department();
        $order = soActOrder(['number' => 'SHOW-M', 'department_id' => $dept->id]);

        $this->actingAs(Access::master($dept, 'supplier-orders'))
            ->get(route('supplier-orders.show', $order))
            ->assertOk()
            ->assertSee('SHOW-M');
    });

    test('мастер отдела без операции поступлений получает 403', function () {
        $dept  = Access::department();
        $other = Access::department();
        Access::allowOperation($other, 'supplier-orders');
        $order = soActOrder(['department_id' => $other->id]);

        $this->actingAs(Access::master($dept, 'orders'))
            ->get(route('supplier-orders.show', $order))
            ->assertForbidden();
    });

    test('работник получает 403', function () {
        $dept = Access::department();
        Access::allowOperation($dept, 'supplier-orders');
        $order = soActOrder(['department_id' => $dept->id]);

        $this->actingAs(Access::userWithPosition('Работник', $dept))
            ->get(route('supplier-orders.show', $order))
            ->assertForbidden();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// create?copy_from=… → SupplierOrderService::getCopySource()
// ══════════════════════════════════════════════════════════════════════════════

describe('SupplierOrderController create() с copy_from', function () {

    test('подставляет исходное поступление с поставщиком и позициями', function () {
        $source  = soActOrder(['number' => 'COPY-SRC'], 4.5);
        $product = $source->items->first()->product;

        $response = $this->actingAs(H::adminUser())
            ->get(route('supplier-orders.create', ['copy_from' => $source->id]))
            ->assertOk()
            ->assertViewIs('supplier-orders.create');

        $copy = $response->viewData('copyFrom');
        expect($copy)->not->toBeNull()
            ->and($copy->id)->toBe($source->id)
            ->and($copy->relationLoaded('counterparty'))->toBeTrue()
            ->and($copy->relationLoaded('items'))->toBeTrue()
            ->and($copy->items)->toHaveCount(1)
            ->and($copy->items->first()->relationLoaded('product'))->toBeTrue();

        // Данные уходят в JS-инициализацию формы.
        $response->assertSee('"counterparty_id":' . json_encode($source->counterparty_id), false)
            ->assertSee('"store_id":' . json_encode($source->store_id), false)
            ->assertSee('"product_id":' . json_encode($product->id), false)
            ->assertSee('"quantity":4.5', false);
    });

    test('несуществующий copy_from — форма пустая, без ошибки', function () {
        $response = $this->actingAs(H::adminUser())
            ->get(route('supplier-orders.create', ['copy_from' => (string) Str::uuid()]))
            ->assertOk();

        expect($response->viewData('copyFrom'))->toBeNull();
    });

    test('пустой copy_from не ищет источник', function () {
        $response = $this->actingAs(H::adminUser())
            ->get(route('supplier-orders.create', ['copy_from' => '']))
            ->assertOk();

        expect($response->viewData('copyFrom'))->toBeNull();
    });

    test('мастер без операции поступлений получает 403', function () {
        $source = soActOrder();

        $this->actingAs(Access::master(Access::department(), 'orders'))
            ->get(route('supplier-orders.create', ['copy_from' => $source->id]))
            ->assertForbidden();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// syncConfirm()
// ══════════════════════════════════════════════════════════════════════════════

describe('SupplierOrderController syncConfirm()', function () {

    test('без данных в сессии — редирект на список', function () {
        $order = soActOrder();

        $this->actingAs(H::adminUser())
            ->get(route('supplier-orders.sync-confirm', $order))
            ->assertRedirect(route('supplier-orders.index'));
    });

    test('коллизия имени Приёмки: показывает предложенное имя и форму suffix_supply', function () {
        $order = soActOrder(['number' => 'DUP-CONF', 'moysklad_id' => 'po-uuid']);

        $response = $this->actingAs(H::adminUser())
            ->withSession(["sync_confirm_{$order->id}" => [
                'issue'          => 'duplicate_supply',
                'suggested_name' => 'DUP-CONF_01',
            ]])
            ->get(route('supplier-orders.sync-confirm', $order))
            ->assertOk()
            ->assertViewIs('supplier-orders.sync-confirm')
            ->assertViewHas('issue', 'duplicate_supply')
            ->assertViewHas('suggested', 'DUP-CONF_01')
            ->assertSee('Приёмка с таким номером уже существует')
            ->assertSee('value="suffix_supply"', false)
            ->assertSee('value="DUP-CONF_01"', false)
            ->assertSee(route('supplier-orders.force-sync', $order), false);

        expect($response->viewData('order')->id)->toBe($order->id);
    });

    test('Заказ поставщику не создан: предлагает recreate и create_order_only', function () {
        $order = soActOrder(['number' => 'NOPO-01']);

        $this->actingAs(H::adminUser())
            ->withSession(["sync_confirm_{$order->id}" => [
                'issue'          => 'order_not_created',
                'suggested_name' => null,
            ]])
            ->get(route('supplier-orders.sync-confirm', $order))
            ->assertOk()
            ->assertSee('Заказ поставщику не создан в МойСклад')
            ->assertSee('value="recreate"', false)
            ->assertSee('value="create_order_only"', false)
            ->assertDontSee('value="suffix_supply"', false);
    });

    test('Заказ поставщику удалён из МойСклад: отдельный текст', function () {
        $order = soActOrder(['moysklad_id' => 'po-gone']);

        $this->actingAs(H::adminUser())
            ->withSession(["sync_confirm_{$order->id}" => [
                'issue'          => 'order_missing',
                'suggested_name' => null,
            ]])
            ->get(route('supplier-orders.sync-confirm', $order))
            ->assertOk()
            ->assertSee('Заказ поставщику удалён из МойСклад');
    });

    test('данные сессии другого поступления не подхватываются', function () {
        $order = soActOrder();
        $other = soActOrder();

        $this->actingAs(H::adminUser())
            ->withSession(["sync_confirm_{$other->id}" => [
                'issue'          => 'duplicate_supply',
                'suggested_name' => 'X_01',
            ]])
            ->get(route('supplier-orders.sync-confirm', $order))
            ->assertRedirect(route('supplier-orders.index'));
    });

    test('полный цикл: sync с коллизией кладёт вопрос в сессию, страница его показывает', function () {
        [$po, $supply] = soActMocks();
        $po->shouldReceive('checkExists')->once()->with('po-uuid')->andReturnTrue();
        $supply->shouldReceive('createSupply')->once()->andReturn(soActFail('Дубль', 'duplicate_name'));

        $order = soActOrder(['number' => 'CYCLE-01', 'moysklad_id' => 'po-uuid']);
        $admin = H::adminUser();

        $this->actingAs($admin)
            ->post(route('supplier-orders.sync', $order))
            ->assertRedirect(route('supplier-orders.sync-confirm', $order))
            ->assertSessionHas("sync_confirm_{$order->id}", [
                'issue'          => 'duplicate_supply',
                'suggested_name' => 'CYCLE-01_01',
            ]);

        $this->actingAs($admin)
            ->get(route('supplier-orders.sync-confirm', $order))
            ->assertOk()
            ->assertViewHas('suggested', 'CYCLE-01_01');
    });

    test('мастер без операции поступлений получает 403', function () {
        $order = soActOrder();

        $this->actingAs(Access::master(Access::department(), 'orders'))
            ->withSession(["sync_confirm_{$order->id}" => ['issue' => 'order_missing', 'suggested_name' => null]])
            ->get(route('supplier-orders.sync-confirm', $order))
            ->assertForbidden();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// forceSync()
// ══════════════════════════════════════════════════════════════════════════════

describe('SupplierOrderController forceSync()', function () {

    // ── create_order_only ────────────────────────────────────────────────────

    test('create_order_only: создаёт Заказ поставщику и возвращает на карточку', function () {
        [$po, $supply] = soActMocks();
        $po->shouldReceive('createPurchaseOrder')->once()
            ->with(Mockery::type(SupplierOrder::class), 'ONLY-01')
            ->andReturn(soActPoOk('po-created'));
        $supply->shouldNotReceive('createSupply');

        $order = soActOrder(['number' => 'ONLY-01', 'sync_error' => 'старая ошибка']);

        $this->actingAs(H::adminUser())
            ->withSession(["sync_confirm_{$order->id}" => ['issue' => 'order_not_created', 'suggested_name' => null]])
            ->post(route('supplier-orders.force-sync', $order), ['mode' => 'create_order_only'])
            ->assertRedirect(route('supplier-orders.show', $order))
            ->assertSessionHas('success', 'Заказ поставщику №ONLY-01 создан в МойСклад. Теперь можно создать Приёмку.')
            ->assertSessionMissing("sync_confirm_{$order->id}");

        $order->refresh();
        expect($order->moysklad_id)->toBe('po-created')
            ->and($order->number)->toBe('ONLY-01')
            ->and($order->status)->toBe(SupplierOrder::STATUS_NEW)
            ->and($order->sync_error)->toBeNull()
            ->and($order->supply_moysklad_id)->toBeNull();
    });

    test('create_order_only: коллизия имени — повтор с суффиксом, номер поступления обновлён', function () {
        [$po] = soActMocks();
        $po->shouldReceive('createPurchaseOrder')->once()
            ->with(Mockery::type(SupplierOrder::class), 'COLL-01')
            ->andReturn(soActFail('Дубль', 'duplicate_name'));
        $po->shouldReceive('createPurchaseOrder')->once()
            ->with(Mockery::type(SupplierOrder::class), 'COLL-01_01')
            ->andReturn(soActPoOk('po-suffixed'));

        $order = soActOrder(['number' => 'COLL-01']);

        $this->actingAs(H::adminUser())
            ->post(route('supplier-orders.force-sync', $order), ['mode' => 'create_order_only'])
            ->assertRedirect(route('supplier-orders.show', $order))
            ->assertSessionHas('success', 'Заказ поставщику №COLL-01_01 создан в МойСклад. Теперь можно создать Приёмку.');

        $order->refresh();
        expect($order->number)->toBe('COLL-01_01')
            ->and($order->moysklad_id)->toBe('po-suffixed');
    });

    test('create_order_only: ошибка МойСклад — на карточку с ошибкой, ошибка записана', function () {
        [$po] = soActMocks();
        $po->shouldReceive('createPurchaseOrder')->once()->andReturn(soActFail('Нет доступа'));

        $order = soActOrder(['number' => 'ONLY-ERR']);

        $this->actingAs(H::adminUser())
            ->post(route('supplier-orders.force-sync', $order), ['mode' => 'create_order_only'])
            ->assertRedirect(route('supplier-orders.show', $order))
            ->assertSessionHas('error', 'Нет доступа');

        $order->refresh();
        expect($order->sync_error)->toBe('Нет доступа')
            ->and($order->moysklad_id)->toBeNull()
            ->and($order->number)->toBe('ONLY-ERR');
    });

    // ── recreate ─────────────────────────────────────────────────────────────

    test('recreate: создаёт Заказ поставщику и Приёмку, статус sent, остатки обновлены', function () {
        [$po, $supply, $stock] = soActMocks();
        $po->shouldReceive('createPurchaseOrder')->once()
            ->with(Mockery::type(SupplierOrder::class), 'RE-01')
            ->andReturn(soActPoOk('po-recreated'));
        $supply->shouldReceive('createSupply')->once()
            ->with(Mockery::type(SupplierOrder::class), 'RE-01')
            ->andReturn(soActSupplyOk('supply-recreated'));

        $order     = soActOrder(['number' => 'RE-01', 'moysklad_id' => 'po-deleted']);
        $productMs = $order->items->first()->product->moysklad_id;

        $stock->shouldReceive('refreshProducts')->once()
            ->withArgs(fn ($ids) => collect($ids)->filter()->values()->all() === [$productMs]);

        $this->actingAs(H::adminUser())
            ->withSession(["sync_confirm_{$order->id}" => ['issue' => 'order_missing', 'suggested_name' => null]])
            ->post(route('supplier-orders.force-sync', $order), ['mode' => 'recreate'])
            ->assertRedirect(route('supplier-orders.index'))
            ->assertSessionHas('success', 'Заказ поставщику и Приёмка №RE-01 созданы в МойСклад.')
            ->assertSessionMissing("sync_confirm_{$order->id}");

        $order->refresh();
        expect($order->moysklad_id)->toBe('po-recreated')
            ->and($order->supply_moysklad_id)->toBe('supply-recreated')
            ->and($order->status)->toBe(SupplierOrder::STATUS_SENT)
            ->and($order->sync_error)->toBeNull();
    });

    test('recreate: коллизия имени Приёмки — повтор с суффиксом', function () {
        [$po, $supply, $stock] = soActMocks();
        $po->shouldReceive('createPurchaseOrder')->once()->andReturn(soActPoOk('po-x'));
        $supply->shouldReceive('createSupply')->once()
            ->with(Mockery::type(SupplierOrder::class), 'RE-02')
            ->andReturn(soActFail('Дубль', 'duplicate_name'));
        $supply->shouldReceive('createSupply')->once()
            ->with(Mockery::type(SupplierOrder::class), 'RE-02_01')
            ->andReturn(soActSupplyOk('supply-x'));
        $stock->shouldReceive('refreshProducts')->once();

        $order = soActOrder(['number' => 'RE-02']);

        $this->actingAs(H::adminUser())
            ->post(route('supplier-orders.force-sync', $order), ['mode' => 'recreate'])
            ->assertRedirect(route('supplier-orders.index'))
            ->assertSessionHas('success', 'Заказ поставщику и Приёмка №RE-02_01 созданы в МойСклад.');

        expect($order->fresh()->number)->toBe('RE-02_01')
            ->and($order->fresh()->status)->toBe(SupplierOrder::STATUS_SENT);
    });

    test('recreate: ошибка Заказа поставщику — Приёмка не создаётся, ошибка на списке', function () {
        [$po, $supply, $stock] = soActMocks();
        $po->shouldReceive('createPurchaseOrder')->once()->andReturn(soActFail('Сервис недоступен'));
        $supply->shouldNotReceive('createSupply');
        $stock->shouldNotReceive('refreshProducts');

        $order = soActOrder(['number' => 'RE-ERR']);

        $this->actingAs(H::adminUser())
            ->post(route('supplier-orders.force-sync', $order), ['mode' => 'recreate'])
            ->assertRedirect(route('supplier-orders.index'))
            ->assertSessionHas('error', 'Не удалось создать Заказ поставщику в МойСклад: Сервис недоступен');

        $order->refresh();
        expect($order->status)->toBe(SupplierOrder::STATUS_NEW)
            ->and($order->moysklad_id)->toBeNull()
            ->and($order->sync_error)->toBe('Сервис недоступен');
    });

    test('recreate: Заказ создан, Приёмка упала — moysklad_id сохранён, статус не sent', function () {
        [$po, $supply, $stock] = soActMocks();
        $po->shouldReceive('createPurchaseOrder')->once()->andReturn(soActPoOk('po-half'));
        $supply->shouldReceive('createSupply')->once()->andReturn(soActFail('Склад закрыт'));
        $stock->shouldNotReceive('refreshProducts');

        $order = soActOrder(['number' => 'RE-HALF']);

        $this->actingAs(H::adminUser())
            ->post(route('supplier-orders.force-sync', $order), ['mode' => 'recreate'])
            ->assertRedirect(route('supplier-orders.index'))
            ->assertSessionHas('error', 'Заказ поставщику создан, но не удалось создать Приёмку: Склад закрыт');

        $order->refresh();
        expect($order->moysklad_id)->toBe('po-half')
            ->and($order->supply_moysklad_id)->toBeNull()
            ->and($order->status)->toBe(SupplierOrder::STATUS_NEW)
            ->and($order->sync_error)->toBe('Склад закрыт');
    });

    // ── suffix_supply ────────────────────────────────────────────────────────

    test('suffix_supply: создаёт Приёмку с предложенным именем и переименовывает поступление', function () {
        [$po, $supply, $stock] = soActMocks();
        $po->shouldNotReceive('createPurchaseOrder');
        $supply->shouldReceive('createSupply')->once()
            ->with(Mockery::type(SupplierOrder::class), 'SUF-01_03')
            ->andReturn(soActSupplyOk('supply-suf'));
        $stock->shouldReceive('refreshProducts')->once();

        $order = soActOrder(['number' => 'SUF-01', 'moysklad_id' => 'po-uuid']);

        $this->actingAs(H::adminUser())
            ->withSession(["sync_confirm_{$order->id}" => ['issue' => 'duplicate_supply', 'suggested_name' => 'SUF-01_03']])
            ->post(route('supplier-orders.force-sync', $order), [
                'mode'           => 'suffix_supply',
                'suggested_name' => 'SUF-01_03',
            ])
            ->assertRedirect(route('supplier-orders.index'))
            ->assertSessionHas('success', 'Приёмка создана в МойСклад с именем «SUF-01_03». Номер поступления обновлён.')
            ->assertSessionMissing("sync_confirm_{$order->id}");

        $order->refresh();
        expect($order->number)->toBe('SUF-01_03')
            ->and($order->supply_moysklad_id)->toBe('supply-suf')
            ->and($order->moysklad_id)->toBe('po-uuid')
            ->and($order->status)->toBe(SupplierOrder::STATUS_SENT);
    });

    test('suffix_supply без suggested_name — имя вычисляется суффиксом от номера', function () {
        [, $supply, $stock] = soActMocks();
        $supply->shouldReceive('createSupply')->once()
            ->with(Mockery::type(SupplierOrder::class), 'SUF-02_01')
            ->andReturn(soActSupplyOk());
        $stock->shouldReceive('refreshProducts')->once();

        $order = soActOrder(['number' => 'SUF-02', 'moysklad_id' => 'po-uuid']);

        $this->actingAs(H::adminUser())
            ->post(route('supplier-orders.force-sync', $order), ['mode' => 'suffix_supply'])
            ->assertRedirect(route('supplier-orders.index'))
            ->assertSessionHas('success');

        expect($order->fresh()->number)->toBe('SUF-02_01');
    });

    test('suffix_supply: ошибка МойСклад — номер не меняется, ошибка на списке', function () {
        [, $supply, $stock] = soActMocks();
        $supply->shouldReceive('createSupply')->once()->andReturn(soActFail('Снова дубль', 'duplicate_name'));
        $stock->shouldNotReceive('refreshProducts');

        $order = soActOrder(['number' => 'SUF-ERR', 'moysklad_id' => 'po-uuid']);

        $this->actingAs(H::adminUser())
            ->post(route('supplier-orders.force-sync', $order), [
                'mode'           => 'suffix_supply',
                'suggested_name' => 'SUF-ERR_01',
            ])
            ->assertRedirect(route('supplier-orders.index'))
            ->assertSessionHas('error', 'Снова дубль');

        $order->refresh();
        expect($order->number)->toBe('SUF-ERR')
            ->and($order->status)->toBe(SupplierOrder::STATUS_NEW)
            ->and($order->sync_error)->toBe('Снова дубль');
    });

    // ── отмена и защита ──────────────────────────────────────────────────────

    test('неизвестный режим — действие отменено, МойСклад не вызывается, вопрос из сессии снят', function () {
        [$po, $supply] = soActMocks();
        $po->shouldNotReceive('createPurchaseOrder');
        $supply->shouldNotReceive('createSupply');

        $order = soActOrder(['number' => 'CANCEL-01']);

        $this->actingAs(H::adminUser())
            ->withSession(["sync_confirm_{$order->id}" => ['issue' => 'order_missing', 'suggested_name' => null]])
            ->post(route('supplier-orders.force-sync', $order), ['mode' => 'something_else'])
            ->assertRedirect(route('supplier-orders.index'))
            ->assertSessionHas('warning', 'Действие отменено.')
            ->assertSessionMissing("sync_confirm_{$order->id}");

        expect($order->fresh()->status)->toBe(SupplierOrder::STATUS_NEW);
    });

    test('запрос без mode — отмена, а не ошибка 500', function () {
        [$po, $supply] = soActMocks();
        $po->shouldNotReceive('createPurchaseOrder');
        $supply->shouldNotReceive('createSupply');

        $order = soActOrder(['number' => 'CANCEL-02']);

        $this->actingAs(H::adminUser())
            ->post(route('supplier-orders.force-sync', $order))
            ->assertRedirect(route('supplier-orders.index'))
            ->assertSessionHas('warning', 'Действие отменено.');
    });

    test('уже отправленное поступление — на карточку с warning, МойСклад не вызывается', function () {
        [$po, $supply] = soActMocks();
        $po->shouldNotReceive('createPurchaseOrder');
        $supply->shouldNotReceive('createSupply');

        $order = soActOrder([
            'number'             => 'SENT-01',
            'status'             => SupplierOrder::STATUS_SENT,
            'moysklad_id'        => 'po-uuid',
            'supply_moysklad_id' => 'supply-uuid',
        ]);

        $this->actingAs(H::adminUser())
            ->post(route('supplier-orders.force-sync', $order), ['mode' => 'recreate'])
            ->assertRedirect(route('supplier-orders.show', $order))
            ->assertSessionHas('warning', 'Приёмка уже создана в МойСклад.');

        $order->refresh();
        expect($order->number)->toBe('SENT-01')
            ->and($order->supply_moysklad_id)->toBe('supply-uuid');
    });

    test('мастер отдела с операцией может выполнить принудительную синхронизацию', function () {
        [$po] = soActMocks();
        $po->shouldReceive('createPurchaseOrder')->once()->andReturn(soActPoOk('po-master'));

        $dept  = Access::department();
        $order = soActOrder(['number' => 'M-01', 'department_id' => $dept->id]);

        $this->actingAs(Access::master($dept, 'supplier-orders'))
            ->post(route('supplier-orders.force-sync', $order), ['mode' => 'create_order_only'])
            ->assertRedirect(route('supplier-orders.show', $order));

        expect($order->fresh()->moysklad_id)->toBe('po-master');
    });

    test('мастер без операции поступлений получает 403, МойСклад не вызывается', function () {
        [$po, $supply] = soActMocks();
        $po->shouldNotReceive('createPurchaseOrder');
        $supply->shouldNotReceive('createSupply');

        $order = soActOrder();

        $this->actingAs(Access::master(Access::department(), 'orders'))
            ->post(route('supplier-orders.force-sync', $order), ['mode' => 'recreate'])
            ->assertForbidden();

        expect($order->fresh()->moysklad_id)->toBeNull();
    });
});
