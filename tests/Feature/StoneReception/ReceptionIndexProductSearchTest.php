<?php

use App\Models\Product;
use App\Models\ReceptionLog;
use App\Models\ReceptionLogItem;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use Tests\Helpers\ReceptionTestHelper as H;

/**
 * Список приёмок: «Все, где есть …» — фильтр по части названия сырья и продукции
 * (filter[raw_product_search], filter[product_search]) на обеих вкладках.
 */
beforeEach(function () {
    $this->store  = H::store();
    $this->cutter = H::cutter();
    $this->master = H::worker();

    $this->quartzRaw  = H::product(['name' => 'Кварцит блок']);
    $this->graniteRaw = H::product(['name' => 'Гранит блок']);
    $this->quartzTile = H::product(['name' => 'Плитка Кварцит 50хL']);
    $this->quartzCube = H::product(['name' => 'Брусчатка кварцит']);
    $this->graniteTile = H::product(['name' => 'Плитка Гранит 30х60']);

    $this->quartzTileReception  = searchReception($this, $this->quartzRaw, $this->quartzTile);
    $this->quartzCubeReception  = searchReception($this, $this->quartzRaw, $this->quartzCube);
    $this->graniteReception     = searchReception($this, $this->graniteRaw, $this->graniteTile);
});

/** Приёмка с позицией и записью журнала: из партии сырья $raw выпущен $tile. */
function searchReception($ctx, Product $raw, Product $tile): StoneReception
{
    $batch     = H::batch($raw, $ctx->store, $ctx->cutter);
    $reception = H::reception($batch, $ctx->master, $ctx->cutter, $ctx->store, 1.0);

    StoneReceptionItem::create([
        'stone_reception_id' => $reception->id,
        'product_id'         => $tile->id,
        'quantity'           => 2.0,
    ]);

    $log = ReceptionLog::create([
        'stone_reception_id'    => $reception->id,
        'raw_material_batch_id' => $batch->id,
        'cutter_id'             => $ctx->cutter->id,
        'receiver_id'           => $ctx->master->id,
        'type'                  => ReceptionLog::TYPE_CREATED,
        'raw_quantity_delta'    => 1.0,
    ]);

    ReceptionLogItem::create([
        'reception_log_id' => $log->id,
        'product_id'       => $tile->id,
        'quantity_delta'   => 2.0,
    ]);

    return $reception;
}

/** Id приёмок и записей журнала в списке с данными фильтрами. */
function searchReceptionIds($ctx, array $filter): array
{
    $response = $ctx->actingAs(H::adminUser())
        ->get(route('stone-receptions.index', ['filter' => $filter]))
        ->assertOk();

    return [
        'receptions' => $response->viewData('receptions')->pluck('id')->sort()->values()->all(),
        'logs'       => $response->viewData('logs')->pluck('stone_reception_id')->sort()->values()->all(),
    ];
}

test('поиск по части названия продукта находит приёмки с любым подходящим товаром', function () {
    $expected = collect([$this->quartzTileReception->id, $this->quartzCubeReception->id])->sort()->values()->all();

    expect(searchReceptionIds($this, ['product_search' => 'КВАРЦ']))
        ->toBe(['receptions' => $expected, 'logs' => $expected]);
});

test('поиск по нескольким словам сужает выборку', function () {
    expect(searchReceptionIds($this, ['product_search' => 'плит кварц'])['receptions'])
        ->toBe([$this->quartzTileReception->id]);
});

test('поиск по части названия сырья — по партии приёмки', function () {
    expect(searchReceptionIds($this, ['raw_product_search' => 'гранит']))
        ->toBe(['receptions' => [$this->graniteReception->id], 'logs' => [$this->graniteReception->id]]);
});

test('ничего не подошло — пустой список, а не все приёмки', function () {
    expect(searchReceptionIds($this, ['product_search' => 'мрамор']))
        ->toBe(['receptions' => [], 'logs' => []]);
});

test('выбор одного товара работает как раньше', function () {
    expect(searchReceptionIds($this, ['product_id' => $this->quartzCube->id])['receptions'])
        ->toBe([$this->quartzCubeReception->id]);
});

test('партии без приёмок фильтруются поиском по сырью', function () {
    $freeQuartz  = H::batch($this->quartzRaw, $this->store, $this->cutter);
    $freeGranite = H::batch($this->graniteRaw, $this->store, $this->cutter);

    $ids = $this->actingAs(H::adminUser())
        ->get(route('stone-receptions.index', ['filter' => ['raw_product_search' => 'кварц']]))
        ->assertOk()
        ->viewData('batchesWithoutReceptions')->pluck('id')->all();

    expect($ids)->toBe([$freeQuartz->id])->not->toContain($freeGranite->id);
});

test('текст поиска остаётся в поле после применения', function () {
    $this->actingAs(H::adminUser())
        ->get(route('stone-receptions.index', ['filter' => ['product_search' => 'кварц']]))
        ->assertOk()
        ->assertSee('name="filter[product_search]"', false)
        ->assertSee('value="кварц"', false);
});
