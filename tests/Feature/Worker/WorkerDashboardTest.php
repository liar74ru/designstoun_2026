<?php

use App\Models\User;
use App\Models\Worker;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use App\Models\ReceptionLog;
use App\Models\ReceptionLogItem;

// ──────────────────────────────────────────────────────────────────────────────
// Доступ к странице
// ──────────────────────────────────────────────────────────────────────────────

test('неавторизованный пользователь не может открыть /my-work', function () {
    $this->get('/my-work')->assertRedirect('/login');
});

test('работник без привязки к Worker получает 403', function () {
    $user = User::factory()->create(['worker_id' => null, 'is_admin' => false]);
    $this->actingAs($user)->get('/my-work')->assertStatus(403);
});

test('работник видит свою страницу выработки', function () {
    $worker = Worker::create(['name' => 'Петров Пётр', 'position' => 'Пильщик']);
    $user   = User::factory()->create(['worker_id' => $worker->id, 'is_admin' => false]);
    $this->actingAs($user)->get('/my-work')->assertStatus(200);
});

test('администратор может открыть страницу любого работника', function () {
    $worker = Worker::create(['name' => 'Сидоров Сидор', 'position' => 'Пильщик']);
    $admin  = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin)->get("/workers/{$worker->id}/dashboard")->assertStatus(200);
});

test('не-администратор на /workers/{id}/dashboard видит свои данные (не 403)', function () {
    // Контроллер: если !isAdmin() — игнорирует workerId и показывает данные
    // самого пользователя. 403 только если у него нет worker_id.
    $worker1 = Worker::create(['name' => 'Иванов', 'position' => 'Пильщик']);
    $worker2 = Worker::create(['name' => 'Петров', 'position' => 'Пильщик']);
    $user    = User::factory()->create(['worker_id' => $worker1->id, 'is_admin' => false]);

    // Видит страницу (свои данные), а не 403
    $this->actingAs($user)
        ->get("/workers/{$worker2->id}/dashboard")
        ->assertStatus(200);
});

test('не-администратор без worker_id на /workers/{id}/dashboard получает 403', function () {
    $worker = Worker::create(['name' => 'Кузнецов', 'position' => 'Пильщик']);
    $user   = User::factory()->create(['worker_id' => null, 'is_admin' => false]);

    $this->actingAs($user)
        ->get("/workers/{$worker->id}/dashboard")
        ->assertStatus(403);
});

// ──────────────────────────────────────────────────────────────────────────────
// Данные на странице
// ──────────────────────────────────────────────────────────────────────────────

test('страница выработки показывает имя работника', function () {
    $worker = Worker::create(['name' => 'Кузнецов Алексей', 'position' => 'Пильщик']);
    $user   = User::factory()->create(['worker_id' => $worker->id, 'is_admin' => false]);

    $this->actingAs($user)->get('/my-work')->assertSee('Кузнецов Алексей');
});

test('страница выработки считает зарплату по приёмкам', function () {
    $worker   = Worker::create(['name' => 'Тестов', 'position' => 'Пильщик']);
    $receiver = Worker::create(['name' => 'Мастер', 'position' => 'Мастер']);
    $store    = Store::factory()->create();
    $product  = Product::factory()->create(['prod_cost_coeff' => 1.0]);
    $user     = User::factory()->create(['worker_id' => $worker->id, 'is_admin' => false]);

    $reception = StoneReception::create([
        'cutter_id'   => $worker->id,
        'receiver_id' => $receiver->id,
        'store_id'    => $store->id,
        'status'      => 'active',
    ]);

    $log = ReceptionLog::create([
        'stone_reception_id' => $reception->id,
        'cutter_id'          => $worker->id,
        'receiver_id'        => $receiver->id,
        'type'               => ReceptionLog::TYPE_CREATED,
        'raw_quantity_delta' => 0,
    ]);
    ReceptionLogItem::create([
        'reception_log_id' => $log->id,
        'product_id'       => $product->id,
        'quantity_delta'   => 10,
    ]);

    // 10 × 1.0 × 390 = 3900 руб
    $this->actingAs($user)->get('/my-work')
        ->assertStatus(200)
        ->assertSee('3 900'); // Laravel форматирует с пробелом-разделителем тысяч
});
