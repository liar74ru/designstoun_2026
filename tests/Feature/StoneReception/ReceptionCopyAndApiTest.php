<?php

use App\Models\Product;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use Tests\Helpers\ReceptionTestHelper as H;

// ══════════════════════════════════════════════════════════════════════════════
// AJAX эндпоинт — getBatchesJson()
// ══════════════════════════════════════════════════════════════════════════════

describe('AJAX партии для пильщика [getBatchesJson()]', function () {

    test('возвращает JSON список активных партий пильщика', function () {
        $user    = H::adminUser();
        $product = H::product(['name' => 'Гранит блок']);
        $store   = H::store();
        $cutter  = H::cutter();

        $batch1 = H::batch($product, $store, $cutter, 10.0, ['batch_number' => 'TEST-01']);
        $batch2 = H::batch($product, $store, $cutter, 5.0,  ['batch_number' => 'TEST-02']);

        // Другой пильщик — его партии не должны попасть в ответ
        $otherCutter = H::cutter('Другой Пильщик');
        H::batch($product, $store, $otherCutter, 20.0);

        $response = $this->actingAs($user)
            ->getJson(route('api.worker.batches', $cutter))
            ->assertStatus(200)
            ->assertJsonCount(2);

        $data = $response->json();
        $ids = array_column($data, 'id');
        expect($ids)->toContain($batch1->id);
        expect($ids)->toContain($batch2->id);
    });

    test('у каждой партии в JSON есть id, label, remaining_quantity', function () {
        $user    = H::adminUser();
        $product = H::product(['name' => 'Мрамор']);
        $store   = H::store();
        $cutter  = H::cutter();
        H::batch($product, $store, $cutter, 7.5);

        $this->actingAs($user)
            ->getJson(route('api.worker.batches', $cutter))
            ->assertJsonStructure([['id', 'label', 'remaining_quantity']]);
    });

    test('возвращает пустой массив если у пильщика нет активных партий', function () {
        $user   = H::adminUser();
        $cutter = H::cutter();

        $this->actingAs($user)
            ->getJson(route('api.worker.batches', $cutter))
            ->assertStatus(200)
            ->assertExactJson([]);
    });

    test('не возвращает партии со статусом used или archived', function () {
        $user    = H::adminUser();
        $product = H::product();
        $store   = H::store();
        $cutter  = H::cutter();

        H::batch($product, $store, $cutter, 0.0, ['status' => 'used']);
        H::batch($product, $store, $cutter, 0.0, ['status' => 'archived']);

        $this->actingAs($user)
            ->getJson(route('api.worker.batches', $cutter))
            ->assertExactJson([]);
    });

    test('AJAX недоступен без авторизации', function () {
        $cutter = H::cutter();
        $this->getJson(route('api.worker.batches', $cutter))->assertStatus(401);
    });
});
