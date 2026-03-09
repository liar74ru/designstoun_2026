<?php

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\RawMaterialBatch;
use App\Models\RawMaterialMovement;
use App\Models\Store;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use App\Models\Worker;
use Tests\Helpers\ReceptionTestHelper as H;

// ══════════════════════════════════════════════════════════════════════════════
// ManagesStock трейт — adjustStock()
// ══════════════════════════════════════════════════════════════════════════════

describe('ManagesStock::adjustStock()', function () {

    function makeStockManagerForWorker() {
        return new class {
            use \App\Traits\ManagesStock;
            public function run($productId, $storeId, $change) {
                $this->adjustStock($productId, $storeId, $change);
            }
        };
    }

    test('создаёт ProductStock если его не было', function () {
        $product  = H::product();
        $newStore = H::store('Новый склад');

        makeStockManagerForWorker()->run($product->id, $newStore->id, 5.0);

        $stock = ProductStock::where('product_id', $product->id)
            ->where('store_id', $newStore->id)
            ->first();

        expect($stock)->not->toBeNull();
        expect((float) $stock->quantity)->toBe(5.0);
    });

    test('обновляет существующий ProductStock', function () {
        $product = H::product();
        $store   = H::store();
        H::stock($product, $store, 10.0);

        makeStockManagerForWorker()->run($product->id, $store->id, -3.0);

        expect((float) ProductStock::where('product_id', $product->id)
            ->where('store_id', $store->id)->value('quantity'))->toBe(7.0);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// StoneReception модель — методы markAs*
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReception::markAs*()', function () {

    test('markAsProcessed() устанавливает статус и processing_id', function () {
        $receiver  = H::worker();
        $cutter    = H::cutter();
        $store     = H::store();
        $rawProd   = H::product();
        $batch     = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0);

        $reception->markAsProcessed('test-uuid-123');

        $reception->refresh();
        expect($reception->status)->toBe('processed');
        expect($reception->moysklad_processing_id)->toBe('test-uuid-123');
        expect($reception->synced_at)->not->toBeNull();
    });

    test('markAsActive() сбрасывает статус и очищает processing_id', function () {
        $receiver  = H::worker();
        $cutter    = H::cutter();
        $store     = H::store();
        $rawProd   = H::product();
        $batch     = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0, [
            'status'                 => 'processed',
            'moysklad_processing_id' => 'some-id',
            'synced_at'              => now(),
        ]);

        $reception->markAsActive();

        $reception->refresh();
        expect($reception->status)->toBe('active');
        expect($reception->moysklad_processing_id)->toBeNull();
        expect($reception->synced_at)->toBeNull();
    });

    test('markAsError() устанавливает статус error', function () {
        $receiver  = H::worker();
        $cutter    = H::cutter();
        $store     = H::store();
        $rawProd   = H::product();
        $batch     = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0);

        $reception->markAsError();

        $reception->refresh();
        expect($reception->status)->toBe('error');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// StoneReception — скоупы
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReception скоупы [scopeActive, scopeProcessed]', function () {

    test('scopeActive возвращает только активные приёмки', function () {
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product();
        $batch    = H::batch($rawProd, $store, $cutter, 100.0);

        H::reception($batch, $receiver, $cutter, $store, 5.0, ['status' => 'active']);
        H::reception($batch, $receiver, $cutter, $store, 5.0, ['status' => 'processed']);
        H::reception($batch, $receiver, $cutter, $store, 5.0, ['status' => 'error']);

        $active = StoneReception::active()->get();
        expect($active->count())->toBe(1);
        expect($active->first()->status)->toBe('active');
    });

    test('scopeProcessed возвращает только обработанные приёмки', function () {
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product();
        $batch    = H::batch($rawProd, $store, $cutter, 100.0);

        H::reception($batch, $receiver, $cutter, $store, 5.0, ['status' => 'active']);
        H::reception($batch, $receiver, $cutter, $store, 5.0, ['status' => 'processed', 'moysklad_processing_id' => 'x']);
        H::reception($batch, $receiver, $cutter, $store, 5.0, ['status' => 'processed', 'moysklad_processing_id' => 'y']);

        expect(StoneReception::processed()->count())->toBe(2);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// StoneReception — total_quantity атрибут
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReception::getTotalQuantityAttribute()', function () {

    test('суммирует количество по всем позициям', function () {
        $receiver  = H::worker();
        $cutter    = H::cutter();
        $store     = H::store();
        $rawProd   = H::product();
        $product1  = H::product();
        $product2  = H::product();
        $batch     = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0);

        $reception->items()->create(['product_id' => $product1->id, 'quantity' => 3.0]);
        $reception->items()->create(['product_id' => $product2->id, 'quantity' => 7.5]);

        $reception->load('items');
        expect((float) $reception->total_quantity)->toBe(10.5);
    });

    test('возвращает 0 если нет позиций', function () {
        $receiver  = H::worker();
        $cutter    = H::cutter();
        $store     = H::store();
        $rawProd   = H::product();
        $batch     = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0);

        $reception->load('items');
        expect((float) $reception->total_quantity)->toBe(0.0);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Страницы списка приёмок
// ══════════════════════════════════════════════════════════════════════════════

describe('Доступность страниц приёмок', function () {

    test('список приёмок доступен авторизованному', function () {
        $user = H::adminUser();
        $this->actingAs($user)->get(route('stone-receptions.index'))->assertStatus(200);
    });

    test('список приёмок недоступен без авторизации', function () {
        $this->get(route('stone-receptions.index'))->assertRedirect('/login');
    });

    test('форма создания приёмки доступна авторизованному', function () {
        $user = H::adminUser();
        $this->actingAs($user)->get(route('stone-receptions.create'))->assertStatus(200);
    });

    test('форма редактирования доступна авторизованному', function () {
        $user      = H::adminUser();
        $receiver  = H::worker();
        $cutter    = H::cutter();
        $store     = H::store();
        $rawProd   = H::product();
        $batch     = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0);

        $this->actingAs($user)
            ->get(route('stone-receptions.edit', $reception))
            ->assertStatus(200);
    });
});
