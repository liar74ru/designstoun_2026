<?php

use App\Models\Product;
use App\Models\RawMaterialBatch;
use App\Models\ReceptionLog;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use Tests\Helpers\ReceptionTestHelper as H;

// ══════════════════════════════════════════════════════════════════════════════
// ШАГ 2: Создание приёмки — StoneReceptionController::store()
// ══════════════════════════════════════════════════════════════════════════════

describe('Создание приёмки [store()]', function () {

    test('успешно создаёт приёмку с продуктами', function () {
        $user     = H::adminUser();
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product(['name' => 'Гранит сырой']);
        $product  = H::product(['name' => 'Плитка']);
        $batch    = H::batch($rawProd, $store, $cutter, 50.0);

        $this->actingAs($user)->post('/stone-receptions', [
            'receiver_id'           => $receiver->id,
            'cutter_id'             => $cutter->id,
            'store_id'              => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used'     => 5.0,
            'products'              => [
                ['product_id' => $product->id, 'quantity' => 2.5],
            ],
        ])->assertRedirect();

        // Приёмка создана
        $reception = StoneReception::first();
        expect($reception)->not->toBeNull();
        expect($reception->status)->toBe('active');
        expect((float) $reception->raw_quantity_used)->toBe(5.0);

        // Позиции созданы
        expect(StoneReceptionItem::where('stone_reception_id', $reception->id)->count())->toBe(1);
        expect((float) $reception->items->first()->quantity)->toBe(2.5);
    });

    test('пишет ReceptionLog типа created при создании', function () {
        $user     = H::adminUser();
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product();
        $product  = H::product();
        $batch    = H::batch($rawProd, $store, $cutter, 50.0);

        $this->actingAs($user)->post('/stone-receptions', [
            'receiver_id'           => $receiver->id,
            'cutter_id'             => $cutter->id,
            'store_id'              => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used'     => 3.0,
            'products'              => [
                ['product_id' => $product->id, 'quantity' => 1.0],
            ],
        ]);

        $log = ReceptionLog::first();
        expect($log)->not->toBeNull();
        expect($log->type)->toBe(ReceptionLog::TYPE_CREATED);
        expect((float) $log->raw_quantity_delta)->toBe(3.0);
        expect($log->items->count())->toBe(1);
    });

    test('отклоняет если партии не хватает сырья', function () {
        $user     = H::adminUser();
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product();
        $product  = H::product();
        $batch    = H::batch($rawProd, $store, $cutter, 2.0); // только 2 м³

        $this->actingAs($user)->post('/stone-receptions', [
            'receiver_id'           => $receiver->id,
            'cutter_id'             => $cutter->id,
            'store_id'              => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used'     => 10.0, // хотим 10 — нельзя
            'products'              => [
                ['product_id' => $product->id, 'quantity' => 1.0],
            ],
        ])->assertSessionHasErrors('raw_quantity_used');

        expect(StoneReception::count())->toBe(0);
    });

    test('требует хотя бы один продукт', function () {
        $user     = H::adminUser();
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product();
        $batch    = H::batch($rawProd, $store, $cutter, 50.0);

        $this->actingAs($user)->post('/stone-receptions', [
            'receiver_id'           => $receiver->id,
            'cutter_id'             => $cutter->id,
            'store_id'              => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used'     => 5.0,
            'products'              => [],
        ])->assertSessionHasErrors('products');
    });

    test('требует авторизацию', function () {
        $this->post('/stone-receptions', [])->assertRedirect('/login');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// ШАГ 4: Редактирование приёмки — StoneReceptionController::update()
// ══════════════════════════════════════════════════════════════════════════════

describe('Редактирование приёмки [update()]', function () {

    test('обновляет продукты и пишет лог типа updated', function () {
        $user     = H::adminUser();
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product();
        $product  = H::product();
        $batch    = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0);

        // Добавляем позицию к приёмке
        $reception->items()->create(['product_id' => $product->id, 'quantity' => 2.0]);

        $this->actingAs($user)->put(route('stone-receptions.update', $reception), [
            'receiver_id'           => $receiver->id,
            'cutter_id'             => $cutter->id,
            'store_id'              => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_delta'    => 0,
            'products'              => [
                ['product_id' => $product->id, 'quantity' => 4.0], // было 2, стало 4
            ],
        ])->assertRedirect(route('stone-receptions.index'));

        // Количество обновилось
        $reception->refresh();
        expect((float) $reception->items->first()->quantity)->toBe(4.0);

        // Лог записан
        $log = ReceptionLog::where('stone_reception_id', $reception->id)
            ->where('type', ReceptionLog::TYPE_UPDATED)->first();
        expect($log)->not->toBeNull();

        // Дельта = +2.0
        $logItem = $log->items->first();
        expect((float) $logItem->quantity_delta)->toBe(2.0);
    });

    test('не пишет лог если ничего не изменилось', function () {
        $user     = H::adminUser();
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product();
        $product  = H::product();
        $batch    = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0);
        $reception->items()->create(['product_id' => $product->id, 'quantity' => 2.0]);

        $this->actingAs($user)->put(route('stone-receptions.update', $reception), [
            'receiver_id'           => $receiver->id,
            'cutter_id'             => $cutter->id,
            'store_id'              => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_delta'    => 0,
            'products'              => [
                ['product_id' => $product->id, 'quantity' => 2.0], // то же самое
            ],
        ]);

        expect(ReceptionLog::where('type', ReceptionLog::TYPE_UPDATED)->count())->toBe(0);
    });

    test('при смене партии возвращает сырьё в старую и списывает из новой', function () {
        $user     = H::adminUser();
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product();
        $product  = H::product();

        $oldBatch = H::batch($rawProd, $store, $cutter, 50.0);
        $newBatch = H::batch($rawProd, $store, $cutter, 30.0);

        $reception = H::reception($oldBatch, $receiver, $cutter, $store, 5.0);
        // После создания через H::reception oldBatch->remaining = 45
        $reception->items()->create(['product_id' => $product->id, 'quantity' => 1.0]);

        $this->actingAs($user)->put(route('stone-receptions.update', $reception), [
            'receiver_id'           => $receiver->id,
            'cutter_id'             => $cutter->id,
            'store_id'              => $store->id,
            'raw_material_batch_id' => $newBatch->id, // меняем партию
            'raw_quantity_delta'    => 0,
            'products'              => [
                ['product_id' => $product->id, 'quantity' => 1.0],
            ],
        ]);

        // В старую вернулось 5 → должно быть 50.0 (начальное)
        $oldBatch->refresh();
        expect((float) $oldBatch->remaining_quantity)->toBe(50.0);

        // Из новой списалось 5.0
        $newBatch->refresh();
        expect((float) $newBatch->remaining_quantity)->toBe(25.0);
    });

    test('delta сырья обновляет raw_quantity_used', function () {
        $user     = H::adminUser();
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product();
        $product  = H::product();
        $batch    = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0);
        $reception->items()->create(['product_id' => $product->id, 'quantity' => 1.0]);

        $this->actingAs($user)->put(route('stone-receptions.update', $reception), [
            'receiver_id'           => $receiver->id,
            'cutter_id'             => $cutter->id,
            'store_id'              => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_delta'    => 2.5, // добавляем 2.5 к текущим 5.0
            'products'              => [
                ['product_id' => $product->id, 'quantity' => 1.0],
            ],
        ]);

        $reception->refresh();
        expect((float) $reception->raw_quantity_used)->toBe(7.5);
    });

    test('нельзя редактировать если в новой партии недостаточно сырья', function () {
        $user     = H::adminUser();
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product();
        $product  = H::product();
        $scarce   = H::batch($rawProd, $store, $cutter, 1.0); // только 1 м³
        $batch    = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0);
        $reception->items()->create(['product_id' => $product->id, 'quantity' => 1.0]);

        $this->actingAs($user)->put(route('stone-receptions.update', $reception), [
            'receiver_id'           => $receiver->id,
            'cutter_id'             => $cutter->id,
            'store_id'              => $store->id,
            'raw_material_batch_id' => $scarce->id,
            'raw_quantity_delta'    => 0,
            'products'              => [
                ['product_id' => $product->id, 'quantity' => 1.0],
            ],
        ])->assertSessionHasErrors('error');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Удаление приёмки
// ══════════════════════════════════════════════════════════════════════════════

describe('Удаление приёмки [destroy()]', function () {

    test('удаляет приёмку', function () {
        $user      = H::adminUser();
        $receiver  = H::worker();
        $cutter    = H::cutter();
        $store     = H::store();
        $rawProd   = H::product();
        $batch     = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0);

        $this->actingAs($user)
            ->delete(route('stone-receptions.destroy', $reception))
            ->assertRedirect(route('stone-receptions.index'));

        expect(StoneReception::find($reception->id))->toBeNull();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Сброс статуса
// ══════════════════════════════════════════════════════════════════════════════

describe('Сброс статуса приёмки [resetStatus()]', function () {

    test('сбрасывает статус на active и очищает moysklad_processing_id', function () {
        $user      = H::adminUser();
        $receiver  = H::worker();
        $cutter    = H::cutter();
        $store     = H::store();
        $rawProd   = H::product();
        $batch     = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0, [
            'status'                 => 'processed',
            'moysklad_processing_id' => 'some-uuid',
            'synced_at'              => now(),
        ]);

        $this->actingAs($user)
            ->patch(route('stone-receptions.reset-status', $reception));

        $reception->refresh();
        expect($reception->status)->toBe('active');
        expect($reception->moysklad_processing_id)->toBeNull();
    });
});
