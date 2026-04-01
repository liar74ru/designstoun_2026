<?php

use App\Models\ProductStock;
use App\Models\RawMaterialBatch;
use App\Models\RawMaterialMovement;
use App\Models\StoneReception;
use Tests\Helpers\ReceptionTestHelper as H;

// ══════════════════════════════════════════════════════════════════════════════
// Статус 'new' — модель
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatch — статус new (модель)', function () {

    test('isNew() возвращает true только для статуса new', function () {
        expect((new RawMaterialBatch(['status' => 'new']))->isNew())->toBeTrue();
        expect((new RawMaterialBatch(['status' => 'in_work']))->isNew())->toBeFalse();
        expect((new RawMaterialBatch(['status' => 'used']))->isNew())->toBeFalse();
        expect((new RawMaterialBatch(['status' => 'archived']))->isNew())->toBeFalse();
    });

    test('isWorkable() возвращает true для new и in_work', function () {
        expect((new RawMaterialBatch(['status' => 'new']))->isWorkable())->toBeTrue();
        expect((new RawMaterialBatch(['status' => 'in_work']))->isWorkable())->toBeTrue();
        expect((new RawMaterialBatch(['status' => 'used']))->isWorkable())->toBeFalse();
        expect((new RawMaterialBatch(['status' => 'returned']))->isWorkable())->toBeFalse();
        expect((new RawMaterialBatch(['status' => 'archived']))->isWorkable())->toBeFalse();
    });

    test('canBeEditedOrDeleted() разрешает только статус new', function () {
        expect((new RawMaterialBatch(['status' => 'new']))->canBeEditedOrDeleted())->toBeTrue();
        expect((new RawMaterialBatch(['status' => 'in_work']))->canBeEditedOrDeleted())->toBeFalse();
        expect((new RawMaterialBatch(['status' => 'used']))->canBeEditedOrDeleted())->toBeFalse();
        expect((new RawMaterialBatch(['status' => 'archived']))->canBeEditedOrDeleted())->toBeFalse();
    });

    test('statusLabel() возвращает правильные названия', function () {
        expect((new RawMaterialBatch(['status' => 'new']))->statusLabel())->toBe('Новая');
        expect((new RawMaterialBatch(['status' => 'in_work']))->statusLabel())->toBe('В работе');
        expect((new RawMaterialBatch(['status' => 'used']))->statusLabel())->toBe('Израсходована');
        expect((new RawMaterialBatch(['status' => 'returned']))->statusLabel())->toBe('Возвращена');
        expect((new RawMaterialBatch(['status' => 'archived']))->statusLabel())->toBe('Архив');
    });

    test('statusBadgeClass() возвращает CSS-классы для всех статусов', function () {
        expect((new RawMaterialBatch(['status' => 'new']))->statusBadgeClass())->toBe('bg-info text-dark');
        expect((new RawMaterialBatch(['status' => 'in_work']))->statusBadgeClass())->toBe('bg-success');
        expect((new RawMaterialBatch(['status' => 'used']))->statusBadgeClass())->toBe('bg-warning text-dark');
        expect((new RawMaterialBatch(['status' => 'returned']))->statusBadgeClass())->toBe('bg-secondary');
        expect((new RawMaterialBatch(['status' => 'archived']))->statusBadgeClass())->toBe('bg-dark');
    });

    test('canBeArchived() не разрешает статус new', function () {
        $batch = new RawMaterialBatch(['status' => 'new', 'remaining_quantity' => 0.0]);
        expect($batch->canBeArchived())->toBeFalse();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Создание партии — получает статус 'new'
// ══════════════════════════════════════════════════════════════════════════════

describe('Создание партии — статус new', function () {

    test('новая партия получает статус new через контроллер', function () {
        $user      = H::adminUser();
        $product   = H::product();
        $fromStore = H::store('Главный склад');
        $toStore   = H::store('Цех');
        $worker    = H::cutter();

        H::stock($product, $fromStore, 50.0);

        $this->actingAs($user)->post('/raw-batches', [
            'product_id'    => $product->id,
            'quantity'      => 10.0,
            'worker_id'     => $worker->id,
            'from_store_id' => $fromStore->id,
            'to_store_id'   => $toStore->id,
        ])->assertRedirect(route('raw-batches.index'));

        $batch = RawMaterialBatch::where('product_id', $product->id)->first();
        expect($batch->status)->toBe('new');
    });

    test('новая партия через RawMovementController получает статус new', function () {
        $user      = H::adminUser();
        $product   = H::product();
        $fromStore = H::store();
        $toStore   = H::store();
        $worker    = H::cutter();

        H::stock($product, $fromStore, 20.0);

        $this->actingAs($user)->post('/raw-batches/create', [
            'product_id'    => $product->id,
            'quantity'      => 5.0,
            'worker_id'     => $worker->id,
            'from_store_id' => $fromStore->id,
            'to_store_id'   => $toStore->id,
        ])->assertRedirect(route('raw-batches.index'));

        $batch = RawMaterialBatch::where('product_id', $product->id)->first();
        expect($batch->status)->toBe('new');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Переход new → in_work при первой приёмке
// ══════════════════════════════════════════════════════════════════════════════

describe('Переход статуса new → in_work', function () {

    test('первая приёмка переводит партию из new в in_work', function () {
        $user     = H::adminUser();
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product();
        $product  = H::product();
        $batch    = H::newBatch($rawProd, $store, $cutter, 50.0);

        expect($batch->status)->toBe('new');

        $this->actingAs($user)->post('/stone-receptions', [
            'receiver_id'           => $receiver->id,
            'cutter_id'             => $cutter->id,
            'store_id'              => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used'     => 5.0,
            'products'              => [
                ['product_id' => $product->id, 'quantity' => 2.0],
            ],
        ])->assertRedirect();

        $batch->refresh();
        expect($batch->status)->toBe('in_work');
    });

    test('при полном расходе из new-партии статус остаётся in_work (авто-смена отключена)', function () {
        $user     = H::adminUser();
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product();
        $product  = H::product();
        $batch    = H::newBatch($rawProd, $store, $cutter, 5.0);

        $this->actingAs($user)->post('/stone-receptions', [
            'receiver_id'           => $receiver->id,
            'cutter_id'             => $cutter->id,
            'store_id'              => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used'     => 5.0,
            'products'              => [
                ['product_id' => $product->id, 'quantity' => 3.0],
            ],
        ])->assertRedirect();

        $batch->refresh();
        expect($batch->status)->toBe('in_work'); // авто-смена в used отключена
        expect((float) $batch->remaining_quantity)->toBe(0.0);
    });

    test('отмена единственной приёмки НЕ возвращает статус в new — остаётся in_work', function () {
        $user     = H::adminUser();
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product();
        $product  = H::product();
        $batch    = H::newBatch($rawProd, $store, $cutter, 50.0);

        // Создаём приёмку
        $this->actingAs($user)->post('/stone-receptions', [
            'receiver_id'           => $receiver->id,
            'cutter_id'             => $cutter->id,
            'store_id'              => $store->id,
            'raw_material_batch_id' => $batch->id,
            'raw_quantity_used'     => 5.0,
            'products'              => [
                ['product_id' => $product->id, 'quantity' => 2.0],
            ],
        ]);

        $batch->refresh();
        expect($batch->status)->toBe('in_work');

        // Удаляем приёмку
        $reception = StoneReception::first();
        $this->actingAs($user)
            ->delete(route('stone-receptions.destroy', $reception))
            ->assertRedirect();

        $batch->refresh();
        // Статус остаётся in_work, не откатывается в new — по правилам ТЗ
        expect($batch->status)->toBe('in_work');
        expect((float) $batch->remaining_quantity)->toBe(50.0);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Партии new/in_work доступны для производства (форма приёмки)
// ══════════════════════════════════════════════════════════════════════════════

describe('Партии new и in_work доступны для производства', function () {

    test('партия со статусом new попадает в список для приёмки', function () {
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::newBatch($product, $store, $cutter, 30.0);

        $user = H::adminUser();

        $response = $this->actingAs($user)
            ->getJson(route('api.worker.batches', $cutter))
            ->assertStatus(200);

        $ids = collect($response->json())->pluck('id')->toArray();
        expect($ids)->toContain($batch->id);
    });

    test('партия со статусом in_work попадает в список для приёмки', function () {
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::batch($product, $store, $cutter, 30.0); // in_work по умолчанию

        $user = H::adminUser();

        $response = $this->actingAs($user)
            ->getJson(route('api.worker.batches', $cutter))
            ->assertStatus(200);

        $ids = collect($response->json())->pluck('id')->toArray();
        expect($ids)->toContain($batch->id);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Редактирование "новой" партии — edit() / update()
// ══════════════════════════════════════════════════════════════════════════════

describe('Редактирование партии [edit() / update()]', function () {

    test('форма редактирования доступна для new-партии', function () {
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::newBatch($product, $store, $cutter, 10.0);

        $this->actingAs(H::adminUser())
            ->get(route('raw-batches.edit', $batch))
            ->assertStatus(200);
    });

    test('форма редактирования недоступна для in_work-партии', function () {
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::batch($product, $store, $cutter, 10.0); // in_work

        $this->actingAs(H::adminUser())
            ->get(route('raw-batches.edit', $batch))
            ->assertRedirect(route('raw-batches.show', $batch))
            ->assertSessionHas('error');
    });

    test('форма редактирования недоступна для archived-партии', function () {
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::batch($product, $store, $cutter, 0.0, ['status' => 'archived']);

        $this->actingAs(H::adminUser())
            ->get(route('raw-batches.edit', $batch))
            ->assertRedirect(route('raw-batches.show', $batch))
            ->assertSessionHas('error');
    });

    test('форма редактирования недоступна без авторизации', function () {
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::newBatch($product, $store, $cutter, 10.0);

        $this->get(route('raw-batches.edit', $batch))
            ->assertRedirect('/login');
    });

    test('обновление количества изменяет initial и remaining quantity', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::newBatch($product, $store, $cutter, 10.0);

        H::stock($product, $store, 10.0);

        $this->actingAs($user)
            ->put(route('raw-batches.update', $batch), [
                'product_id' => $product->id,
                'quantity'   => 15.0,
            ])->assertRedirect(route('raw-batches.show', $batch))
            ->assertSessionHas('success');

        $batch->refresh();
        expect((float) $batch->initial_quantity)->toBe(15.0);
        expect((float) $batch->remaining_quantity)->toBe(15.0);
        expect($batch->status)->toBe('new'); // статус не меняется
    });

    test('обновление продукта корректирует product_stocks', function () {
        $user       = H::adminUser();
        $oldProduct = H::product(['name' => 'Старый продукт']);
        $newProduct = H::product(['name' => 'Новый продукт']);
        $store      = H::store();
        $cutter     = H::cutter();
        $batch      = H::newBatch($oldProduct, $store, $cutter, 8.0);

        H::stock($oldProduct, $store, 8.0);
        H::stock($newProduct, $store, 0.0);

        $this->actingAs($user)
            ->put(route('raw-batches.update', $batch), [
                'product_id' => $newProduct->id,
                'quantity'   => 8.0,
            ])->assertRedirect(route('raw-batches.show', $batch));

        $batch->refresh();
        expect($batch->product_id)->toBe($newProduct->id);

        // Старый продукт списан со склада
        expect((float) ProductStock::where('product_id', $oldProduct->id)
            ->where('store_id', $store->id)->value('quantity'))->toBe(0.0);

        // Новый продукт добавлен на склад
        expect((float) ProductStock::where('product_id', $newProduct->id)
            ->where('store_id', $store->id)->value('quantity'))->toBe(8.0);
    });

    test('обновление количества корректирует product_stocks', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::newBatch($product, $store, $cutter, 10.0);

        H::stock($product, $store, 10.0);

        $this->actingAs($user)
            ->put(route('raw-batches.update', $batch), [
                'product_id' => $product->id,
                'quantity'   => 12.0,
            ]);

        expect((float) ProductStock::where('product_id', $product->id)
            ->where('store_id', $store->id)->value('quantity'))->toBe(12.0);
    });

    test('update отклоняет обновление in_work-партии', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::batch($product, $store, $cutter, 10.0); // in_work

        $this->actingAs($user)
            ->put(route('raw-batches.update', $batch), [
                'product_id' => $product->id,
                'quantity'   => 20.0,
            ])->assertRedirect(route('raw-batches.show', $batch))
            ->assertSessionHas('error');

        $batch->refresh();
        expect((float) $batch->initial_quantity)->toBe(10.0); // не изменилось
    });

    test('update требует обязательные поля', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::newBatch($product, $store, $cutter, 10.0);

        $this->actingAs($user)
            ->put(route('raw-batches.update', $batch), [])
            ->assertSessionHasErrors(['product_id', 'quantity']);
    });

    test('update недоступен без авторизации', function () {
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::newBatch($product, $store, $cutter, 10.0);

        $this->put(route('raw-batches.update', $batch), [
            'product_id' => $product->id,
            'quantity'   => 5.0,
        ])->assertRedirect('/login');
    });

    test('если ничего не изменилось — возвращает info без ошибок', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::newBatch($product, $store, $cutter, 10.0);

        H::stock($product, $store, 10.0);

        $this->actingAs($user)
            ->put(route('raw-batches.update', $batch), [
                'product_id' => $product->id,
                'quantity'   => 10.0, // то же самое
            ])->assertRedirect(route('raw-batches.show', $batch))
            ->assertSessionHas('info');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Удаление "новой" партии — destroyNew()
// ══════════════════════════════════════════════════════════════════════════════

describe('Удаление новой партии [destroyNew()]', function () {

    test('успешно удаляет new-партию', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::newBatch($product, $store, $cutter, 5.0);

        H::stock($product, $store, 5.0);

        $this->actingAs($user)
            ->delete(route('raw-batches.destroy-new', $batch))
            ->assertRedirect(route('raw-batches.index'))
            ->assertSessionHas('success');

        expect(RawMaterialBatch::find($batch->id))->toBeNull();
    });

    test('при удалении возвращает сырьё в product_stocks', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::newBatch($product, $store, $cutter, 7.0);

        H::stock($product, $store, 7.0);

        $this->actingAs($user)
            ->delete(route('raw-batches.destroy-new', $batch));

        expect((float) ProductStock::where('product_id', $product->id)
            ->where('store_id', $store->id)->value('quantity'))->toBe(0.0);
    });

    test('при удалении также удаляются записи movements', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::newBatch($product, $store, $cutter, 5.0);

        // Создаём запись движения вручную
        RawMaterialMovement::create([
            'batch_id'      => $batch->id,
            'movement_type' => 'create',
            'quantity'      => 5.0,
        ]);

        H::stock($product, $store, 5.0);

        $this->actingAs($user)
            ->delete(route('raw-batches.destroy-new', $batch));

        expect(RawMaterialMovement::where('batch_id', $batch->id)->count())->toBe(0);
        expect(RawMaterialBatch::find($batch->id))->toBeNull();
    });

    test('нельзя удалить in_work-партию через destroyNew', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::batch($product, $store, $cutter, 10.0); // in_work

        $this->actingAs($user)
            ->delete(route('raw-batches.destroy-new', $batch))
            ->assertRedirect(route('raw-batches.show', $batch))
            ->assertSessionHas('error');

        expect(RawMaterialBatch::find($batch->id))->not->toBeNull();
    });

    test('нельзя удалить archived-партию через destroyNew', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::batch($product, $store, $cutter, 0.0, ['status' => 'archived']);

        $this->actingAs($user)
            ->delete(route('raw-batches.destroy-new', $batch))
            ->assertRedirect(route('raw-batches.show', $batch))
            ->assertSessionHas('error');

        expect(RawMaterialBatch::find($batch->id))->not->toBeNull();
    });

    test('destroyNew недоступен без авторизации', function () {
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::newBatch($product, $store, $cutter, 5.0);

        $this->delete(route('raw-batches.destroy-new', $batch))
            ->assertRedirect('/login');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Передача / возврат — работают для обоих рабочих статусов
// ══════════════════════════════════════════════════════════════════════════════

describe('Передача и возврат работают для new и in_work', function () {

    test('new-партию можно передать пильщику', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $cutter1 = H::cutter('Пильщик А');
        $cutter2 = H::cutter('Пильщик Б');
        $batch   = H::newBatch($product, $store, $cutter1, 10.0);

        $this->actingAs($user)
            ->post(route('raw-batches.transfer', $batch), [
                'to_worker_id' => $cutter2->id,
            ])->assertRedirect(route('raw-batches.show', $batch));

        $batch->refresh();
        expect($batch->current_worker_id)->toBe($cutter2->id);
    });

    test('форма передачи открывается для new-партии', function () {
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::newBatch($product, $store, $cutter, 10.0);

        $this->actingAs(H::adminUser())
            ->get(route('raw-batches.transfer.form', $batch))
            ->assertStatus(200);
    });

    test('форма возврата открывается для new-партии', function () {
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::newBatch($product, $store, $cutter, 10.0);

        $this->actingAs(H::adminUser())
            ->get(route('raw-batches.return.form', $batch))
            ->assertStatus(200);
    });

    test('archived-партию нельзя передать', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $cutter1 = H::cutter('Пильщик А');
        $cutter2 = H::cutter('Пильщик Б');
        $batch   = H::batch($product, $store, $cutter1, 0.0, ['status' => 'archived']);

        $this->actingAs($user)
            ->post(route('raw-batches.transfer', $batch), [
                'to_worker_id' => $cutter2->id,
            ])->assertSessionHas('error');

        $batch->refresh();
        expect($batch->current_worker_id)->toBe($cutter1->id);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Страница index отображает правильные статусы
// ══════════════════════════════════════════════════════════════════════════════

describe('Index — отображение новых статусов', function () {

    test('index показывает метку «Новая» для new-партии', function () {
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        H::newBatch($product, $store, $cutter, 10.0, ['batch_number' => 'NEW-BATCH-TEST']);

        $this->actingAs(H::adminUser())
            ->get(route('raw-batches.index'))
            ->assertStatus(200)
            ->assertSee('Новая')
            ->assertSee('NEW-BATCH-TEST');
    });

    test('index показывает метку «В работе» для in_work-партии', function () {
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        H::batch($product, $store, $cutter, 10.0, ['batch_number' => 'INWORK-BATCH-TEST']);

        $this->actingAs(H::adminUser())
            ->get(route('raw-batches.index'))
            ->assertStatus(200)
            ->assertSee('В работе')
            ->assertSee('INWORK-BATCH-TEST');
    });

    test('фильтр по статусу new не ломает страницу', function () {
        $this->actingAs(H::adminUser())
            ->get(route('raw-batches.index', ['filter[status]' => 'new']))
            ->assertStatus(200);
    });

    test('фильтр по статусу in_work не ломает страницу', function () {
        $this->actingAs(H::adminUser())
            ->get(route('raw-batches.index', ['filter[status]' => 'in_work']))
            ->assertStatus(200);
    });
});
