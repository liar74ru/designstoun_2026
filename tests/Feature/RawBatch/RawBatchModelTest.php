<?php

use App\Models\Product;
use App\Models\RawMaterialBatch;
use App\Models\Store;
use App\Models\Worker;
use Tests\Helpers\ReceptionTestHelper as H;

// ══════════════════════════════════════════════════════════════════════════════
// RawMaterialBatch — методы модели
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatch модель', function () {

    test('isActive() возвращает true для статуса active', function () {
        $batch = new RawMaterialBatch(['status' => 'in_work']);
        expect($batch->isActive())->toBeTrue();
    });

    test('isActive() возвращает false для других статусов', function () {
        foreach (['used', 'returned', 'archived'] as $status) {
            $batch = new RawMaterialBatch(['status' => $status]);
            expect($batch->isActive())->toBeFalse();
        }
    });

    test('isArchived() верно определяет архив', function () {
        expect((new RawMaterialBatch(['status' => 'archived']))->isArchived())->toBeTrue();
        expect((new RawMaterialBatch(['status' => 'in_work']))->isArchived())->toBeFalse();
    });

    test('canBeArchived() требует used/returned И нулевой остаток', function () {
        $b1 = new RawMaterialBatch(['status' => 'used',    'remaining_quantity' => 0.0]);
        $b2 = new RawMaterialBatch(['status' => 'returned','remaining_quantity' => 0.0]);
        $b3 = new RawMaterialBatch(['status' => 'used',    'remaining_quantity' => 1.5]);
        $b4 = new RawMaterialBatch(['status' => 'in_work',  'remaining_quantity' => 0.0]);

        expect($b1->canBeArchived())->toBeTrue();
        expect($b2->canBeArchived())->toBeTrue();
        expect($b3->canBeArchived())->toBeFalse();
        expect($b4->canBeArchived())->toBeFalse();
    });

    test('canBeEdited() запрещает редактирование архивной партии', function () {
        expect((new RawMaterialBatch(['status' => 'archived']))->canBeEdited())->toBeFalse();
        expect((new RawMaterialBatch(['status' => 'in_work']))->canBeEdited())->toBeTrue();
        expect((new RawMaterialBatch(['status' => 'used']))->canBeEdited())->toBeTrue();
    });

    test('remaining_quantity приводится к decimal:3', function () {
        $product = H::product();
        $store   = H::store();
        $worker  = H::cutter();
        $batch   = H::batch($product, $store, $worker, 10.123456);

        $batch->refresh();
        expect($batch->remaining_quantity)->toBe('10.123');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Страница показа партии
// ══════════════════════════════════════════════════════════════════════════════

describe('Просмотр партии [show()]', function () {

    test('страница партии доступна авторизованному пользователю', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $worker  = H::cutter();
        $batch   = H::batch($product, $store, $worker, 20.0);

        $this->actingAs($user)
            ->get(route('raw-batches.show', $batch))
            ->assertStatus(200);
    });

    test('страница партии недоступна без авторизации', function () {
        $product = H::product();
        $store   = H::store();
        $worker  = H::cutter();
        $batch   = H::batch($product, $store, $worker, 20.0);

        $this->get(route('raw-batches.show', $batch))
            ->assertRedirect('/login');
    });
});
