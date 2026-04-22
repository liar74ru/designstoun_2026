<?php

use App\Models\Counterparty;
use App\Models\Product;
use App\Models\Store;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderItem;
use App\Services\MoySkladPurchaseOrderService;
use App\Services\MoySkladSupplyService;
use App\Services\StockSyncService;
use Tests\Helpers\ReceptionTestHelper as H;

// ─── Моки сервисов ───────────────────────────────────────────────────────────

function mockPurchaseOrderService(bool $success = true, string $moyskladId = 'ms-po-uuid'): void
{
    $mock = Mockery::mock(MoySkladPurchaseOrderService::class);
    $mock->shouldReceive('createPurchaseOrder')->andReturn([
        'success'     => $success,
        'moysklad_id' => $success ? $moyskladId : null,
        'message'     => $success ? 'OK' : 'Ошибка API',
        'code'        => $success ? null : 'api_error',
    ]);
    $mock->shouldReceive('updatePurchaseOrder')->andReturn([
        'success' => $success,
        'message' => $success ? 'OK' : 'Ошибка API',
    ]);
    $mock->shouldReceive('deletePurchaseOrder')->andReturn([
        'success' => $success,
        'message' => $success ? 'OK' : 'Ошибка API',
    ]);
    $mock->shouldReceive('checkExists')->andReturn(true);
    app()->instance(MoySkladPurchaseOrderService::class, $mock);
}

function mockSupplyService(bool $success = true, ?string $code = null): void
{
    $mock = Mockery::mock(MoySkladSupplyService::class);
    $mock->shouldReceive('createSupply')->andReturn([
        'success'            => $success,
        'supply_moysklad_id' => $success ? 'ms-supply-uuid' : null,
        'message'            => $success ? 'OK' : 'Ошибка',
        'code'               => $code,
    ]);
    app()->instance(MoySkladSupplyService::class, $mock);
}

function mockStockSync(): void
{
    $mock = Mockery::mock(StockSyncService::class);
    $mock->shouldReceive('updateProductStocksByMoyskladId')->andReturn([
        'success' => true,
        'updated' => 1,
    ]);
    app()->instance(StockSyncService::class, $mock);
}

// ─── Фабрики данных ──────────────────────────────────────────────────────────

function makeCounterparty(): Counterparty
{
    return Counterparty::create([
        'name'        => 'Тест Поставщик',
        'moysklad_id' => (string) \Illuminate\Support\Str::uuid(),
    ]);
}

function makeSupplierOrderData(Store $store, Counterparty $counterparty, array $products): array
{
    return [
        'store_id'        => $store->id,
        'counterparty_id' => $counterparty->id,
        'number'          => 'TEST-01',
        'note'            => '',
        'products'        => $products,
    ];
}

// ══════════════════════════════════════════════════════════════════════════════
// store()
// ══════════════════════════════════════════════════════════════════════════════

describe('SupplierOrderController store()', function () {

    test('создаёт поступление и синхронизирует с МойСклад', function () {
        $user    = H::adminUser();
        $store   = H::store();
        $cp      = makeCounterparty();
        $product = H::product(['moysklad_id' => (string) \Illuminate\Support\Str::uuid()]);

        mockPurchaseOrderService(true, 'po-uuid-123');

        $this->actingAs($user)->post(route('supplier-orders.store'), makeSupplierOrderData($store, $cp, [
            ['product_id' => $product->id, 'quantity' => 5.5],
        ]))->assertRedirect(route('supplier-orders.index'));

        $order = SupplierOrder::first();
        expect($order)->not->toBeNull();
        expect($order->number)->toBe('TEST-01');
        expect($order->status)->toBe(SupplierOrder::STATUS_NEW);
        expect($order->moysklad_id)->toBe('po-uuid-123');
        expect(SupplierOrderItem::where('supplier_order_id', $order->id)->count())->toBe(1);
    });

    test('сохраняет поступление даже при ошибке МойСклад, статус error', function () {
        $user    = H::adminUser();
        $store   = H::store();
        $cp      = makeCounterparty();
        $product = H::product();

        mockPurchaseOrderService(false);

        $this->actingAs($user)->post(route('supplier-orders.store'), makeSupplierOrderData($store, $cp, [
            ['product_id' => $product->id, 'quantity' => 2.0],
        ]))->assertRedirect(route('supplier-orders.index'));

        $order = SupplierOrder::first();
        expect($order)->not->toBeNull();
        expect($order->status)->toBe(SupplierOrder::STATUS_ERROR);
    });

    test('недоступно без авторизации', function () {
        $this->post(route('supplier-orders.store'), [])->assertRedirect('/login');
    });

    test('отклоняет без обязательных полей', function () {
        $user = H::adminUser();
        mockPurchaseOrderService();

        $this->actingAs($user)
            ->post(route('supplier-orders.store'), [])
            ->assertSessionHasErrors(['store_id', 'counterparty_id', 'number', 'products']);
    });

    test('отклоняет без позиций товаров', function () {
        $user = H::adminUser();
        $store = H::store();
        $cp   = makeCounterparty();
        mockPurchaseOrderService();

        $this->actingAs($user)->post(route('supplier-orders.store'), [
            'store_id'        => $store->id,
            'counterparty_id' => $cp->id,
            'number'          => 'TEST-NO-ITEMS',
            'products'        => [],
        ])->assertSessionHasErrors(['products']);
    });

    test('отклоняет позицию с несуществующим product_id', function () {
        $user  = H::adminUser();
        $store = H::store();
        $cp    = makeCounterparty();
        mockPurchaseOrderService();

        $this->actingAs($user)->post(route('supplier-orders.store'), makeSupplierOrderData($store, $cp, [
            ['product_id' => 'non-existing-uuid', 'quantity' => 1.0],
        ]))->assertSessionHasErrors(['products.0.product_id']);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// update()
// ══════════════════════════════════════════════════════════════════════════════

describe('SupplierOrderController update()', function () {

    test('обновляет поступление и синхронизирует с МойСклад', function () {
        $user    = H::adminUser();
        $store   = H::store();
        $cp      = makeCounterparty();
        $product = H::product();

        mockPurchaseOrderService();

        $order = SupplierOrder::create([
            'number'          => 'ORIG-01',
            'store_id'        => $store->id,
            'counterparty_id' => $cp->id,
            'status'          => SupplierOrder::STATUS_NEW,
        ]);
        SupplierOrderItem::create([
            'supplier_order_id' => $order->id,
            'product_id'        => $product->id,
            'quantity'          => 1.0,
        ]);

        $newProduct = H::product();

        $this->actingAs($user)->put(route('supplier-orders.update', $order), [
            'store_id'        => $store->id,
            'counterparty_id' => $cp->id,
            'number'          => 'UPDT-01',
            'products'        => [
                ['product_id' => $newProduct->id, 'quantity' => 3.0],
            ],
        ])->assertRedirect(route('supplier-orders.index'));

        $order->refresh();
        expect($order->number)->toBe('UPDT-01');
        expect($order->items()->where('product_id', $newProduct->id)->exists())->toBeTrue();
    });

    test('нельзя редактировать поступление не в статусе new', function () {
        $user  = H::adminUser();
        $store = H::store();
        $cp    = makeCounterparty();

        mockPurchaseOrderService();

        $order = SupplierOrder::create([
            'number'          => 'SENT-01',
            'store_id'        => $store->id,
            'counterparty_id' => $cp->id,
            'status'          => SupplierOrder::STATUS_SENT,
        ]);

        $this->actingAs($user)->put(route('supplier-orders.update', $order), [
            'store_id'        => $store->id,
            'counterparty_id' => $cp->id,
            'number'          => 'CHANGED',
            'products'        => [['product_id' => H::product()->id, 'quantity' => 1.0]],
        ])->assertRedirect(route('supplier-orders.index'))
          ->assertSessionHas('warning');

        $order->refresh();
        expect($order->number)->toBe('SENT-01');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// destroy()
// ══════════════════════════════════════════════════════════════════════════════

describe('SupplierOrderController destroy()', function () {

    test('удаляет поступление в статусе new', function () {
        $user  = H::adminUser();
        $store = H::store();
        $cp    = makeCounterparty();

        mockPurchaseOrderService();

        $order = SupplierOrder::create([
            'number'          => 'DEL-01',
            'store_id'        => $store->id,
            'counterparty_id' => $cp->id,
            'status'          => SupplierOrder::STATUS_NEW,
        ]);

        $this->actingAs($user)
            ->delete(route('supplier-orders.destroy', $order))
            ->assertRedirect(route('supplier-orders.index'))
            ->assertSessionHas('success');

        expect(SupplierOrder::find($order->id))->toBeNull();
    });

    test('нельзя удалить поступление в статусе sent', function () {
        $user  = H::adminUser();
        $store = H::store();
        $cp    = makeCounterparty();

        $order = SupplierOrder::create([
            'number'          => 'SENT-DEL',
            'store_id'        => $store->id,
            'counterparty_id' => $cp->id,
            'status'          => SupplierOrder::STATUS_SENT,
        ]);

        $this->actingAs($user)
            ->delete(route('supplier-orders.destroy', $order))
            ->assertRedirect(route('supplier-orders.index'))
            ->assertSessionHas('warning');

        expect(SupplierOrder::find($order->id))->not->toBeNull();
    });

    test('удаляет позиции вместе с поступлением', function () {
        $user    = H::adminUser();
        $store   = H::store();
        $cp      = makeCounterparty();
        $product = H::product();

        mockPurchaseOrderService();

        $order = SupplierOrder::create([
            'number'          => 'DEL-ITEMS',
            'store_id'        => $store->id,
            'counterparty_id' => $cp->id,
            'status'          => SupplierOrder::STATUS_NEW,
        ]);
        SupplierOrderItem::create([
            'supplier_order_id' => $order->id,
            'product_id'        => $product->id,
            'quantity'          => 1.0,
        ]);

        $this->actingAs($user)->delete(route('supplier-orders.destroy', $order));

        expect(SupplierOrderItem::where('supplier_order_id', $order->id)->count())->toBe(0);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// sync()
// ══════════════════════════════════════════════════════════════════════════════

describe('SupplierOrderController sync()', function () {

    test('создаёт приёмку в МойСклад и обновляет остатки', function () {
        $user    = H::adminUser();
        $store   = H::store();
        $cp      = makeCounterparty();
        $product = H::product(['moysklad_id' => (string) \Illuminate\Support\Str::uuid()]);

        mockPurchaseOrderService();
        mockSupplyService(true);
        mockStockSync();

        $order = SupplierOrder::create([
            'number'          => 'SYNC-01',
            'store_id'        => $store->id,
            'counterparty_id' => $cp->id,
            'status'          => SupplierOrder::STATUS_NEW,
            'moysklad_id'     => 'po-uuid',
        ]);
        SupplierOrderItem::create([
            'supplier_order_id' => $order->id,
            'product_id'        => $product->id,
            'quantity'          => 2.0,
        ]);

        $this->actingAs($user)
            ->post(route('supplier-orders.sync', $order))
            ->assertRedirect(route('supplier-orders.index'))
            ->assertSessionHas('success');

        $order->refresh();
        expect($order->status)->toBe(SupplierOrder::STATUS_SENT);
        expect($order->supply_moysklad_id)->toBe('ms-supply-uuid');
    });

    test('нельзя синхронизировать поступление не в статусе new', function () {
        $user  = H::adminUser();
        $store = H::store();
        $cp    = makeCounterparty();

        $order = SupplierOrder::create([
            'number'          => 'SYNC-SENT',
            'store_id'        => $store->id,
            'counterparty_id' => $cp->id,
            'status'          => SupplierOrder::STATUS_SENT,
        ]);

        $this->actingAs($user)
            ->post(route('supplier-orders.sync', $order))
            ->assertRedirect(route('supplier-orders.show', $order))
            ->assertSessionHas('warning');
    });

    test('коллизия имени → редирект на sync-confirm', function () {
        $user    = H::adminUser();
        $store   = H::store();
        $cp      = makeCounterparty();
        $product = H::product();

        mockPurchaseOrderService();
        mockSupplyService(false, 'duplicate_name');

        $order = SupplierOrder::create([
            'number'          => 'DUP-01',
            'store_id'        => $store->id,
            'counterparty_id' => $cp->id,
            'status'          => SupplierOrder::STATUS_NEW,
            'moysklad_id'     => 'po-uuid',
        ]);
        SupplierOrderItem::create([
            'supplier_order_id' => $order->id,
            'product_id'        => $product->id,
            'quantity'          => 1.0,
        ]);

        $this->actingAs($user)
            ->post(route('supplier-orders.sync', $order))
            ->assertRedirect(route('supplier-orders.sync-confirm', $order));
    });

    test('ошибка API → редирект на index с danger-сообщением', function () {
        $user    = H::adminUser();
        $store   = H::store();
        $cp      = makeCounterparty();
        $product = H::product();

        mockPurchaseOrderService();
        mockSupplyService(false, 'api_error');

        $order = SupplierOrder::create([
            'number'          => 'ERR-01',
            'store_id'        => $store->id,
            'counterparty_id' => $cp->id,
            'status'          => SupplierOrder::STATUS_NEW,
            'moysklad_id'     => 'po-uuid',
        ]);
        SupplierOrderItem::create([
            'supplier_order_id' => $order->id,
            'product_id'        => $product->id,
            'quantity'          => 1.0,
        ]);

        $this->actingAs($user)
            ->post(route('supplier-orders.sync', $order))
            ->assertRedirect(route('supplier-orders.index'))
            ->assertSessionHas('danger');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// nextOrderNumber()
// ══════════════════════════════════════════════════════════════════════════════

describe('SupplierOrderController nextOrderNumber()', function () {

    test('возвращает номер в формате ГГ-НН-ПРОГ-ПП', function () {
        $user = H::adminUser();

        $response = $this->actingAs($user)
            ->getJson(route('api.supplier-orders.next-number'));

        $response->assertStatus(200);
        expect($response->json('number'))->toMatch('/^\d{2}-\d{2}-ПРОГ-\d{2}$/u');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// index() / create() / edit()
// ══════════════════════════════════════════════════════════════════════════════

describe('SupplierOrderController страницы', function () {

    test('index доступна авторизованному', function () {
        $this->actingAs(H::adminUser())
            ->get(route('supplier-orders.index'))
            ->assertStatus(200);
    });

    test('index недоступна без авторизации', function () {
        $this->get(route('supplier-orders.index'))
            ->assertRedirect('/login');
    });

    test('create доступна авторизованному', function () {
        $this->actingAs(H::adminUser())
            ->get(route('supplier-orders.create'))
            ->assertStatus(200);
    });

    test('edit доступна для поступления в статусе new', function () {
        $user  = H::adminUser();
        $store = H::store();
        $cp    = makeCounterparty();

        $order = SupplierOrder::create([
            'number'          => 'EDIT-01',
            'store_id'        => $store->id,
            'counterparty_id' => $cp->id,
            'status'          => SupplierOrder::STATUS_NEW,
        ]);

        $this->actingAs($user)
            ->get(route('supplier-orders.edit', $order))
            ->assertStatus(200);
    });

    test('edit редиректит для поступления в статусе sent', function () {
        $user  = H::adminUser();
        $store = H::store();
        $cp    = makeCounterparty();

        $order = SupplierOrder::create([
            'number'          => 'EDIT-SENT',
            'store_id'        => $store->id,
            'counterparty_id' => $cp->id,
            'status'          => SupplierOrder::STATUS_SENT,
        ]);

        $this->actingAs($user)
            ->get(route('supplier-orders.edit', $order))
            ->assertRedirect(route('supplier-orders.index'));
    });
});
