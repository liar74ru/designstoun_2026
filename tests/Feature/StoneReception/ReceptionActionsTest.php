<?php

use App\Models\RawMaterialBatch;
use App\Models\ReceptionLog;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use Tests\Helpers\ReceptionTestHelper as H;

// ══════════════════════════════════════════════════════════════════════════════
// logs()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionController logs()', function () {

    test('страница доступна авторизованному', function () {
        $this->actingAs(H::adminUser())
            ->get(route('stone-receptions.logs'))
            ->assertStatus(200);
    });

    test('недоступна без авторизации', function () {
        $this->get(route('stone-receptions.logs'))
            ->assertRedirect('/login');
    });

    test('отображает записи журнала', function () {
        $user     = H::adminUser();
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product();
        $product  = H::product();
        $batch    = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0);

        ReceptionLog::create([
            'stone_reception_id' => $reception->id,
            'receiver_id'        => $receiver->id,
            'cutter_id'          => $cutter->id,
            'type'               => ReceptionLog::TYPE_CREATED,
            'raw_quantity_delta' => 5.0,
        ]);

        $this->actingAs($user)
            ->get(route('stone-receptions.logs'))
            ->assertStatus(200);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// markCompleted()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionController markCompleted()', function () {

    test('завершает активную приёмку', function () {
        $user     = H::adminUser();
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product();
        $batch    = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0);

        $this->actingAs($user)
            ->patch(route('stone-receptions.mark-completed', $reception))
            ->assertRedirect();

        $reception->refresh();
        expect($reception->status)->toBe(StoneReception::STATUS_COMPLETED);
    });

    test('закрывает партию если остаток равен нулю', function () {
        $user     = H::adminUser();
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product();
        $batch    = H::batch($rawProd, $store, $cutter, 10.0);
        // Списываем всё сырьё — remaining_quantity станет 0
        $reception = H::reception($batch, $receiver, $cutter, $store, 10.0);

        $this->actingAs($user)
            ->patch(route('stone-receptions.mark-completed', $reception));

        $batch->refresh();
        expect($batch->status)->toBe(RawMaterialBatch::STATUS_USED);
    });

    test('не закрывает партию если остаток больше нуля', function () {
        $user     = H::adminUser();
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product();
        $batch    = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0);

        $this->actingAs($user)
            ->patch(route('stone-receptions.mark-completed', $reception));

        $batch->refresh();
        expect($batch->status)->toBe(RawMaterialBatch::STATUS_IN_WORK);
    });

    test('403 если приёмка не активна', function () {
        $user     = H::adminUser();
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product();
        $batch    = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0, [
            'status' => StoneReception::STATUS_COMPLETED,
        ]);

        $this->actingAs($user)
            ->patch(route('stone-receptions.mark-completed', $reception))
            ->assertStatus(403);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// updateItemCoeff()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionController updateItemCoeff()', function () {

    test('обновляет effective_cost_coeff позиции', function () {
        $user     = H::adminUser();
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product();
        $product  = H::product(['prod_cost_coeff' => 1.0]);
        $batch    = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0);

        $item = StoneReceptionItem::create([
            'stone_reception_id'   => $reception->id,
            'product_id'           => $product->id,
            'quantity'             => 2.0,
            'effective_cost_coeff' => 1.0,
            'is_undercut'          => false,
        ]);

        $this->actingAs($user)->post(route('stone-receptions.update-item-coeff', $reception), [
            'items' => [
                [
                    'item_id'    => $item->id,
                    'base_coeff' => 1.5,
                    'is_undercut'=> false,
                ],
            ],
        ])->assertRedirect();

        $item->refresh();
        expect((float) $item->effective_cost_coeff)->toBe(1.5);
    });

    test('учитывает флаг is_undercut при расчёте коэффициента', function () {
        $user     = H::adminUser();
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product();
        $product  = H::product(['prod_cost_coeff' => 1.0]);
        $batch    = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0);

        $item = StoneReceptionItem::create([
            'stone_reception_id'   => $reception->id,
            'product_id'           => $product->id,
            'quantity'             => 2.0,
            'effective_cost_coeff' => 1.0,
            'is_undercut'          => false,
        ]);

        $this->actingAs($user)->post(route('stone-receptions.update-item-coeff', $reception), [
            'items' => [
                [
                    'item_id'    => $item->id,
                    'base_coeff' => 1.0,
                    'is_undercut'=> true,
                ],
            ],
        ]);

        $item->refresh();
        $expected = StoneReceptionItem::computeEffectiveCoeff(1.0, true);
        expect((float) $item->effective_cost_coeff)->toBe((float) $expected);
        expect($item->is_undercut)->toBeTrue();
    });

    test('отклоняет без items', function () {
        $user     = H::adminUser();
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product();
        $batch    = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0);

        $this->actingAs($user)
            ->post(route('stone-receptions.update-item-coeff', $reception), [])
            ->assertSessionHasErrors(['items']);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// copy()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionController copy()', function () {

    test('редиректит на create с copy_from и cutter_id', function () {
        $user     = H::adminUser();
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product();
        $batch    = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0);

        $this->actingAs($user)
            ->post(route('stone-receptions.copy', $reception))
            ->assertRedirect(
                route('stone-receptions.create', [
                    'copy_from' => $reception->id,
                    'cutter_id' => $reception->cutter_id,
                ])
            );
    });
});
