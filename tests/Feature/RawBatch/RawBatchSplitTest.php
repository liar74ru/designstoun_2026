<?php

use App\Models\RawMaterialBatch;
use App\Models\RawMaterialMovement;
use App\Services\RawMaterialBatchService;
use Tests\Helpers\ReceptionTestHelper as H;

describe('RawMaterialBatchService::split()', function () {

    test('сжимает родителя до фактически использованного объёма и создаёт дочернюю с остатком', function () {
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        // initial = 10, remaining = 6 → used = 4
        $parent = H::batch($product, $store, $cutter, 10.0, [
            'remaining_quantity' => 6.0,
            'status'             => RawMaterialBatch::STATUS_IN_WORK,
            'batch_number'       => 'B-01',
        ]);

        $service = app(RawMaterialBatchService::class);
        $result  = $service->split($parent);

        $parent->refresh();
        expect((float) $parent->initial_quantity)->toBe(4.0);
        expect((float) $parent->remaining_quantity)->toBe(0.0);
        expect($parent->status)->toBe(RawMaterialBatch::STATUS_USED);

        $child = $result['newBatch'];
        expect((float) $child->initial_quantity)->toBe(6.0);
        expect((float) $child->remaining_quantity)->toBe(6.0);
        expect($child->status)->toBe(RawMaterialBatch::STATUS_NEW);
        expect($child->current_worker_id)->toBe($cutter->id);
        expect($child->current_store_id)->toBe($store->id);
        expect($child->batch_number)->toBe('B-01/' . $parent->id);
        expect($child->notes)->toContain('B-01');

        $movement = RawMaterialMovement::where('batch_id', $child->id)
            ->where('movement_type', 'create')
            ->first();
        expect($movement)->not->toBeNull();
        expect((float) $movement->quantity)->toBe(6.0);
    });

    test('бросает исключение, если у партии нет остатка', function () {
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $parent  = H::batch($product, $store, $cutter, 10.0, [
            'remaining_quantity' => 0.0,
        ]);

        $service = app(RawMaterialBatchService::class);

        expect(fn() => $service->split($parent))
            ->toThrow(\RuntimeException::class, 'не имеет остатка');
    });

    test('бросает исключение, если у партии нет работника', function () {
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();
        $parent  = H::batch($product, $store, $cutter, 10.0, [
            'remaining_quantity'  => 5.0,
            'current_worker_id'   => null,
        ]);

        $service = app(RawMaterialBatchService::class);

        expect(fn() => $service->split($parent))
            ->toThrow(\RuntimeException::class, 'не назначен работник');
    });
});
