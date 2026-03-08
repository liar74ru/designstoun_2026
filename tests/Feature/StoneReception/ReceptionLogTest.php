<?php

use App\Models\ReceptionLog;
use App\Models\ReceptionLogItem;
use Tests\Helpers\ReceptionTestHelper as H;

// ══════════════════════════════════════════════════════════════════════════════
// ReceptionLog — журнал изменений приёмок
// ══════════════════════════════════════════════════════════════════════════════

describe('ReceptionLog — запись логов', function () {

    test('лог создания содержит все необходимые поля', function () {
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product();
        $product  = H::product();
        $batch    = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0);
        $reception->items()->create(['product_id' => $product->id, 'quantity' => 2.0]);

        $log = ReceptionLog::create([
            'stone_reception_id'    => $reception->id,
            'raw_material_batch_id' => $batch->id,
            'cutter_id'             => $cutter->id,
            'receiver_id'           => $receiver->id,
            'type'                  => ReceptionLog::TYPE_CREATED,
            'raw_quantity_delta'    => 5.0,
        ]);

        ReceptionLogItem::create([
            'reception_log_id' => $log->id,
            'product_id'       => $product->id,
            'quantity_delta'   => 2.0,
        ]);

        expect($log->type)->toBe('created');
        expect((float) $log->raw_quantity_delta)->toBe(5.0);
        expect($log->items->count())->toBe(1);
        expect((float) $log->items->first()->quantity_delta)->toBe(2.0);
    });

    test('лог редактирования сохраняет отрицательную дельту', function () {
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product();
        $product  = H::product();
        $batch    = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0);
        $reception->items()->create(['product_id' => $product->id, 'quantity' => 3.0]);

        $log = ReceptionLog::create([
            'stone_reception_id'    => $reception->id,
            'raw_material_batch_id' => $batch->id,
            'cutter_id'             => $cutter->id,
            'receiver_id'           => $receiver->id,
            'type'                  => ReceptionLog::TYPE_UPDATED,
            'raw_quantity_delta'    => -1.5,
        ]);

        ReceptionLogItem::create([
            'reception_log_id' => $log->id,
            'product_id'       => $product->id,
            'quantity_delta'   => -1.0, // убрали 1 м²
        ]);

        expect($log->type)->toBe('updated');
        expect((float) $log->raw_quantity_delta)->toBe(-1.5);
        expect((float) $log->items->first()->quantity_delta)->toBe(-1.0);
    });

    test('несколько логов для одной приёмки привязываются корректно', function () {
        $receiver = H::worker();
        $cutter   = H::cutter();
        $store    = H::store();
        $rawProd  = H::product();
        $batch    = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0);

        ReceptionLog::create([
            'stone_reception_id'    => $reception->id,
            'raw_material_batch_id' => $batch->id,
            'cutter_id'             => $cutter->id,
            'receiver_id'           => $receiver->id,
            'type'                  => ReceptionLog::TYPE_CREATED,
            'raw_quantity_delta'    => 5.0,
        ]);

        ReceptionLog::create([
            'stone_reception_id'    => $reception->id,
            'raw_material_batch_id' => $batch->id,
            'cutter_id'             => $cutter->id,
            'receiver_id'           => $receiver->id,
            'type'                  => ReceptionLog::TYPE_UPDATED,
            'raw_quantity_delta'    => 1.0,
        ]);

        expect($reception->receptionLogs()->count())->toBe(2);
        expect($reception->receptionLogs()->where('type', 'created')->count())->toBe(1);
        expect($reception->receptionLogs()->where('type', 'updated')->count())->toBe(1);
    });

    test('константы типов логов заданы корректно', function () {
        expect(ReceptionLog::TYPE_CREATED)->toBe('created');
        expect(ReceptionLog::TYPE_UPDATED)->toBe('updated');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Страница журнала логов — доступность
// ══════════════════════════════════════════════════════════════════════════════

describe('Страница журнала логов', function () {

    test('страница логов доступна авторизованному', function () {
        $user = H::adminUser();
        $this->actingAs($user)->get(route('stone-receptions.logs'))
            ->assertStatus(200);
    });

    test('страница логов требует авторизацию', function () {
        $this->get(route('stone-receptions.logs'))
            ->assertRedirect('/login');
    });
});
