<?php

use App\Models\Product;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use App\Services\MoySkladProcessingService;
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

// ══════════════════════════════════════════════════════════════════════════════
// ШАГ 5: Отправка в МойСклад — StoneReceptionBatchController::sendToProcessing()
// Мокаем MoySkladProcessingService — не делаем реальных HTTP-запросов
// ══════════════════════════════════════════════════════════════════════════════

describe('Отправка приёмок в МойСклад [sendToProcessing()]', function () {

    beforeEach(function () {
        // Мок сервиса подменяется перед каждым тестом
    });

    test('успешно отправляет приёмки и ставит статус processed', function () {
        $user      = H::adminUser();
        $receiver  = H::worker();
        $cutter    = H::cutter();
        $store     = H::store();
        $rawProd   = H::product(['name' => 'Сырьё']);
        $product   = H::product(['name' => 'Готовая плитка', 'moysklad_id' => 'prod-uuid-1']);
        $rawProd->update(['moysklad_id' => 'raw-uuid-1']);

        $batch     = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0);
        $reception->items()->create(['product_id' => $product->id, 'quantity' => 2.0]);

        // Подменяем сервис — возвращаем успех без HTTP-запроса
        $this->mock(MoySkladProcessingService::class, function ($mock) {
            $mock->shouldReceive('createProcessing')
                ->once()
                ->andReturn(['success' => true, 'processing_id' => 'fake-processing-uuid', 'message' => 'OK']);
        });

        $this->actingAs($user)
            ->postJson(route('stone-receptions.batch.send-to-processing'), [
                'reception_ids' => [$reception->id],
            ])->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('processing_id', 'fake-processing-uuid');

        $reception->refresh();
        expect($reception->status)->toBe('processed');
        expect($reception->moysklad_processing_id)->toBe('fake-processing-uuid');
        expect($reception->synced_at)->not->toBeNull();
    });

    test('при ошибке сервиса ставит статус error', function () {
        $user      = H::adminUser();
        $receiver  = H::worker();
        $cutter    = H::cutter();
        $store     = H::store();
        $rawProd   = H::product(['moysklad_id' => 'raw-uuid-2']);
        $product   = H::product(['moysklad_id' => 'prod-uuid-2']);
        $batch     = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0);
        $reception->items()->create(['product_id' => $product->id, 'quantity' => 1.0]);

        $this->mock(MoySkladProcessingService::class, function ($mock) {
            $mock->shouldReceive('createProcessing')
                ->once()
                ->andReturn(['success' => false, 'processing_id' => null, 'message' => 'API Error']);
        });

        $this->actingAs($user)
            ->postJson(route('stone-receptions.batch.send-to-processing'), [
                'reception_ids' => [$reception->id],
            ])->assertStatus(500)
            ->assertJsonPath('success', false);

        $reception->refresh();
        expect($reception->status)->toBe('error');
    });

    test('возвращает 400 если все приёмки уже processed', function () {
        $user      = H::adminUser();
        $receiver  = H::worker();
        $cutter    = H::cutter();
        $store     = H::store();
        $rawProd   = H::product();
        $batch     = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0, [
            'status' => 'processed',
        ]);

        $this->actingAs($user)
            ->postJson(route('stone-receptions.batch.send-to-processing'), [
                'reception_ids' => [$reception->id],
            ])->assertStatus(400);
    });

    test('возвращает 422 если reception_ids не переданы', function () {
        $user = H::adminUser();

        $this->actingAs($user)
            ->postJson(route('stone-receptions.batch.send-to-processing'), [])
            ->assertStatus(422);
    });

    test('возвращает 400 если приёмки на разных складах', function () {
        $user      = H::adminUser();
        $receiver  = H::worker();
        $cutter    = H::cutter();
        $store1    = H::store('Склад 1');
        $store2    = H::store('Склад 2');
        $rawProd   = H::product();
        $batch     = H::batch($rawProd, $store1, $cutter, 50.0);

        $r1 = H::reception($batch, $receiver, $cutter, $store1, 3.0);
        $r2 = H::reception($batch, $receiver, $cutter, $store2, 3.0);
        $r1->items()->create(['product_id' => H::product()->id, 'quantity' => 1.0]);
        $r2->items()->create(['product_id' => H::product()->id, 'quantity' => 1.0]);

        $this->actingAs($user)
            ->postJson(route('stone-receptions.batch.send-to-processing'), [
                'reception_ids' => [$r1->id, $r2->id],
            ])->assertStatus(400)
            ->assertJsonPath('success', false);
    });

    test('недоступен без авторизации', function () {
        $this->postJson(route('stone-receptions.batch.send-to-processing'), [
            'reception_ids' => [1],
        ])->assertStatus(401);
    });
});
