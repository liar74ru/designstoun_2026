<?php

use App\Models\RawMaterialBatch;
use App\Models\Worker;
use Tests\Helpers\ReceptionTestHelper as H;

// ══════════════════════════════════════════════════════════════════════════════
// index()
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchController index()', function () {

    test('страница списка партий доступна авторизованному', function () {
        $this->actingAs(H::adminUser())
            ->get(route('raw-batches.index'))
            ->assertStatus(200);
    });

    test('недоступна без авторизации', function () {
        $this->get(route('raw-batches.index'))
            ->assertRedirect('/login');
    });

    test('показывает созданную партию', function () {
        $product = H::product(['name' => 'Уникальный мрамор XYZ']);
        $store   = H::store();
        $cutter  = H::cutter();
        H::batch($product, $store, $cutter, 10.0, ['batch_number' => 'TEST-BATCH-001']);

        $this->actingAs(H::adminUser())
            ->get(route('raw-batches.index'))
            ->assertStatus(200)
            ->assertSee('TEST-BATCH-001');
    });

    test('фильтр по статусу не ломает страницу', function () {
        $this->actingAs(H::adminUser())
            ->get(route('raw-batches.index', ['filter[status]' => 'in_work']))
            ->assertStatus(200);
    });

    test('фильтр по работнику не ломает страницу', function () {
        $cutter = H::cutter();

        $this->actingAs(H::adminUser())
            ->get(route('raw-batches.index', ['filter[current_worker_id]' => $cutter->id]))
            ->assertStatus(200);
    });

    test('фильтр по номеру партии не ломает страницу', function () {
        $this->actingAs(H::adminUser())
            ->get(route('raw-batches.index', ['filter[batch_number]' => 'TEST']))
            ->assertStatus(200);
    });

    test('фильтр по продукту не ломает страницу', function () {
        $product = H::product();

        $this->actingAs(H::adminUser())
            ->get(route('raw-batches.index', ['filter[product_id]' => $product->id]))
            ->assertStatus(200);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// create()
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchController create()', function () {

    test('форма создания доступна авторизованному', function () {
        $this->actingAs(H::adminUser())
            ->get(route('raw-batches.create'))
            ->assertStatus(200);
    });

    test('форма создания недоступна без авторизации', function () {
        $this->get(route('raw-batches.create'))
            ->assertRedirect('/login');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// adjustForm()
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchController adjustForm()', function () {

    test('форма корректировки доступна для активной партии', function () {
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::batch($product, $store, $cutter, 10.0);

        $this->actingAs(H::adminUser())
            ->get(route('raw-batches.adjust.form', $batch))
            ->assertStatus(200);
    });

    test('форма корректировки недоступна для архивной партии', function () {
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::batch($product, $store, $cutter, 0.0, ['status' => 'archived']);

        $this->actingAs(H::adminUser())
            ->get(route('raw-batches.adjust.form', $batch))
            ->assertRedirect(route('raw-batches.show', $batch))
            ->assertSessionHas('error');
    });

    test('недоступна без авторизации', function () {
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::batch($product, $store, $cutter, 5.0);

        $this->get(route('raw-batches.adjust.form', $batch))
            ->assertRedirect('/login');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// archive() — непокрытые ветки
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchController archive() — дополнительные ветки', function () {

    test('нельзя архивировать уже архивную партию', function () {
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::batch($product, $store, $cutter, 0.0, ['status' => 'archived']);

        $this->actingAs(H::adminUser())
            ->post(route('raw-batches.archive', $batch))
            ->assertRedirect()
            ->assertSessionHas('error');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// transferForm()
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchController transferForm()', function () {

    test('форма передачи доступна для активной партии', function () {
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::batch($product, $store, $cutter, 10.0);

        $this->actingAs(H::adminUser())
            ->get(route('raw-batches.transfer.form', $batch))
            ->assertStatus(200);
    });

    test('форма передачи недоступна без авторизации', function () {
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::batch($product, $store, $cutter, 10.0);

        $this->get(route('raw-batches.transfer.form', $batch))
            ->assertRedirect('/login');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// returnForm()
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchController returnForm()', function () {

    test('форма возврата доступна для активной партии', function () {
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::batch($product, $store, $cutter, 10.0);

        $this->actingAs(H::adminUser())
            ->get(route('raw-batches.return.form', $batch))
            ->assertStatus(200);
    });

    test('форма возврата редиректит для неактивной партии', function () {
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::batch($product, $store, $cutter, 0.0, ['status' => 'used']);

        $this->actingAs(H::adminUser())
            ->get(route('raw-batches.return.form', $batch))
            ->assertRedirect(route('raw-batches.show', $batch))
            ->assertSessionHas('error');
    });

    test('недоступна без авторизации', function () {
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $batch   = H::batch($product, $store, $cutter, 10.0);

        $this->get(route('raw-batches.return.form', $batch))
            ->assertRedirect('/login');
    });
});
