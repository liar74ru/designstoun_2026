<?php

use App\Models\Department;
use App\Models\Product;
use App\Models\ReceptionLog;
use App\Models\ReceptionLogItem;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use App\Models\Store;
use App\Models\User;
use App\Models\Worker;
use App\Services\WorkerDashboardService;
use Carbon\Carbon;

/**
 * Фильтр «Продукт» на странице выработки: режет только сводку по отделам.
 * «Итого начислено», копилка и выплата считаются за весь период без фильтра.
 */

// В пятницу период по умолчанию — прошлая неделя, а записи создаются «сейчас»
beforeEach(function () {
    if (now()->isFriday()) {
        $this->travel(-1)->days();
    }

    $this->dept   = Department::create(['name' => 'Отдел выработки', 'is_active' => true]);
    $this->cutter = Worker::create(['name' => 'Пильщик Фильтра', 'position' => 'Работник', 'department_id' => $this->dept->id]);
    $this->master = Worker::create(['name' => 'Мастер Фильтра', 'position' => 'Мастер', 'department_id' => $this->dept->id]);

    $this->quartzTile = Product::factory()->create(['name' => 'Плитка Кварцит 50хL']);
    $this->quartzCube = Product::factory()->create(['name' => 'Брусчатка кварцит']);
    $this->granite    = Product::factory()->create(['name' => 'Плитка Гранит']);

    // Ставка пильщика 100 ₽/м²: 10 + 5 + 4 м²
    dashboardFilterReception($this, $this->quartzTile, 10.0);
    dashboardFilterReception($this, $this->quartzCube, 5.0);
    dashboardFilterReception($this, $this->granite, 4.0);
});

function dashboardFilterReception($ctx, Product $tile, float $qty): void
{
    $reception = StoneReception::create([
        'receiver_id'   => $ctx->master->id,
        'cutter_id'     => $ctx->cutter->id,
        'store_id'      => Store::factory()->create()->id,
        'department_id' => $ctx->dept->id,
        'status'        => 'active',
    ]);

    StoneReceptionItem::create([
        'stone_reception_id' => $reception->id,
        'product_id'         => $tile->id,
        'quantity'           => $qty,
        'worker_cost_per_m2' => 100,
        'master_cost_per_m2' => 50,
    ]);

    $log = ReceptionLog::create([
        'stone_reception_id' => $reception->id,
        'cutter_id'          => $ctx->cutter->id,
        'receiver_id'        => $ctx->master->id,
        'type'               => ReceptionLog::TYPE_CREATED,
        'raw_quantity_delta' => 0,
    ]);

    ReceptionLogItem::create([
        'reception_log_id' => $log->id,
        'product_id'       => $tile->id,
        'quantity_delta'   => $qty,
    ]);
}

function dashboardFilterData($ctx, ?array $productIds): array
{
    return app(WorkerDashboardService::class)->getDashboardData(
        $ctx->cutter->id,
        Carbon::now()->subDay()->startOfDay(),
        Carbon::now()->addDay()->endOfDay(),
        $productIds,
    );
}

/** Товары строк сводки работника во всех отделах. */
function dashboardFilterProducts(array $data): array
{
    return $data['departments']
        ->flatMap(fn ($dept) => $dept['worker']['summary']->pluck('product.id'))
        ->all();
}

test('без фильтра — вся выработка, варианты фильтра — товары с выработкой', function () {
    $data = dashboardFilterData($this, null);

    expect(dashboardFilterProducts($data))
        ->toEqualCanonicalizing([$this->quartzTile->id, $this->quartzCube->id, $this->granite->id])
        ->and((float) $data['totalPay'])->toEqual(1900.0)
        ->and($data['filteredTotalPay'])->toBeNull()
        ->and($data['filterProducts']->pluck('id')->all())
        ->toEqualCanonicalizing([$this->quartzTile->id, $this->quartzCube->id, $this->granite->id]);
});

test('фильтр режет сводку, но не итог начисленного', function () {
    $data = dashboardFilterData($this, [$this->quartzTile->id, $this->quartzCube->id]);

    expect(dashboardFilterProducts($data))->toEqualCanonicalizing([$this->quartzTile->id, $this->quartzCube->id])
        ->and((float) $data['totalPay'])->toEqual(1900.0)
        ->and((float) $data['filteredTotalPay'])->toEqual(1500.0)
        // Варианты фильтра не сужаются вместе со сводкой
        ->and($data['filterProducts'])->toHaveCount(3);
});

test('поиск по части названия в адресе — сводка по всем подходящим товарам', function () {
    $response = $this->actingAs(User::factory()->create(['worker_id' => $this->cutter->id, 'is_admin' => false]))
        ->get(route('worker.dashboard', ['filter' => ['product_search' => 'КВАРЦ']]))
        ->assertOk()
        ->assertSee('по фильтру', false)
        ->assertSee('name="filter[product_search]"', false)
        ->assertSee('value="КВАРЦ"', false);

    expect(dashboardFilterProducts(['departments' => $response->viewData('departments')]))
        ->toEqualCanonicalizing([$this->quartzTile->id, $this->quartzCube->id])
        ->and((float) $response->viewData('totalPay'))->toEqual(1900.0)
        ->and((float) $response->viewData('filteredTotalPay'))->toEqual(1500.0);
});

test('ничего не подошло — пустая сводка с пояснением, итог не меняется', function () {
    $response = $this->actingAs(User::factory()->create(['worker_id' => $this->cutter->id, 'is_admin' => false]))
        ->get(route('worker.dashboard', ['filter' => ['product_search' => 'мрамор']]))
        ->assertOk()
        ->assertSee('По выбранному продукту за период выработки нет');

    expect($response->viewData('departments'))->toBeEmpty()
        ->and((float) $response->viewData('totalPay'))->toEqual(1900.0);
});

test('пикер работника получает список товаров в разметке, без API каталога', function () {
    $this->actingAs(User::factory()->create(['worker_id' => $this->cutter->id, 'is_admin' => false]))
        ->get(route('worker.dashboard'))
        ->assertOk()
        ->assertSee('data-products=', false)
        ->assertSee('Брусчатка кварцит');
});

test('мастер на чужой странице тоже может фильтровать', function () {
    $this->actingAs(User::factory()->create(['worker_id' => $this->master->id, 'is_admin' => false]))
        ->get(route('worker.dashboard.by-id', [$this->cutter->id, 'filter' => ['product_id' => $this->granite->id]]))
        ->assertOk()
        ->assertViewHas('departments', fn ($departments) => $departments->flatMap(
            fn ($dept) => $dept['worker']['summary']->pluck('product.id')
        )->all() === [$this->granite->id]);
});
