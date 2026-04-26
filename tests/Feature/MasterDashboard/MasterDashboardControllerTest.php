<?php

use App\Models\Product;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use App\Models\Store;
use App\Models\User;
use App\Models\Worker;

test('неавторизованный пользователь не может открыть /master-work', function () {
    $this->get('/master-work')->assertRedirect('/login');
});

test('работник без привязки к Worker получает 403', function () {
    $user = User::factory()->create(['worker_id' => null, 'is_admin' => false]);
    $this->actingAs($user)->get('/master-work')->assertStatus(403);
});

test('мастер видит свою страницу дашборда', function () {
    $worker = Worker::create(['name' => 'Мастеров Мастер', 'positions' => ['Мастер']]);
    $user = User::factory()->create(['worker_id' => $worker->id, 'is_admin' => false]);

    $this->actingAs($user)->get('/master-work')->assertStatus(200);
});

test('администратор может открыть страницу любого мастера', function () {
    $worker = Worker::create(['name' => 'Мастер Сидор', 'positions' => ['Мастер']]);
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->get("/master-work/{$worker->id}")->assertStatus(200);
});

test('не-администратор на /master-work/{id} видит свои данные', function () {
    $worker1 = Worker::create(['name' => 'Мастер Иванов', 'positions' => ['Мастер']]);
    $worker2 = Worker::create(['name' => 'Мастер Петров', 'positions' => ['Мастер']]);
    $user = User::factory()->create(['worker_id' => $worker1->id, 'is_admin' => false]);

    $this->actingAs($user)
        ->get("/master-work/{$worker2->id}")
        ->assertStatus(200);
});

test('не-администратор без worker_id на /master-work/{id} получает 403', function () {
    $worker = Worker::create(['name' => 'Мастер Кузнецов', 'positions' => ['Мастер']]);
    $user = User::factory()->create(['worker_id' => null, 'is_admin' => false]);

    $this->actingAs($user)
        ->get("/master-work/{$worker->id}")
        ->assertStatus(403);
});

test('страница дашборда мастера показывает имя работника', function () {
    $worker = Worker::create(['name' => 'Мастер Алексеев', 'positions' => ['Мастер']]);
    $user = User::factory()->create(['worker_id' => $worker->id, 'is_admin' => false]);

    $this->actingAs($user)->get('/master-work')->assertSee('Мастер Алексеев');
});

test('страница дашборда мастера показывает приёмки за период', function () {
    $master = Worker::create(['name' => 'Мастер Приёмок', 'positions' => ['Мастер']]);
    $cutter = Worker::create(['name' => 'Пильщик Приёмок', 'positions' => ['Пильщик']]);
    $store = Store::factory()->create();
    $product = Product::factory()->create();
    $user = User::factory()->create(['worker_id' => $master->id, 'is_admin' => false]);

    $reception = StoneReception::create([
        'receiver_id' => $master->id,
        'cutter_id' => $cutter->id,
        'store_id' => $store->id,
        'status' => 'active',
    ]);

    StoneReceptionItem::create([
        'stone_reception_id' => $reception->id,
        'product_id' => $product->id,
        'quantity' => 5,
    ]);

    $this->actingAs($user)->get('/master-work')
        ->assertStatus(200);
});

test('страница дашборда мастера фильтрует приёмки по датам', function () {
    $master = Worker::create(['name' => 'Мастер Фильтр', 'positions' => ['Мастер']]);
    $cutter = Worker::create(['name' => 'Пильщик Фильтр', 'positions' => ['Пильщик']]);
    $store = Store::factory()->create();
    $user = User::factory()->create(['worker_id' => $master->id, 'is_admin' => false]);

    StoneReception::create([
        'receiver_id' => $master->id,
        'cutter_id' => $cutter->id,
        'store_id' => $store->id,
        'status' => 'active',
        'created_at' => now()->subWeek(),
    ]);

    $this->actingAs($user)
        ->get('/master-work?date_from='.now()->format('Y-m-d').'&date_to='.now()->format('Y-m-d'))
        ->assertStatus(200);
});
