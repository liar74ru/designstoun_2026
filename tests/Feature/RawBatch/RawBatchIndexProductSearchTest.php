<?php

use Tests\Helpers\ReceptionTestHelper as H;

/**
 * Список партий: «Все, где есть …» в фильтре «Сырьё» (filter[product_search]).
 */
beforeEach(function () {
    $store  = H::store();
    $cutter = H::cutter();

    $this->quartzBlock = H::batch(H::product(['name' => 'Кварцит блок']), $store, $cutter);
    $this->quartzSlab  = H::batch(H::product(['name' => 'Слэб кварцитовый']), $store, $cutter);
    $this->granite     = H::batch(H::product(['name' => 'Гранит блок']), $store, $cutter);
});

/** Id партий в списке с данными фильтрами. */
function searchBatchIds($ctx, array $filter): array
{
    return $ctx->actingAs(H::adminUser())
        ->get(route('raw-batches.index', ['filter' => $filter]))
        ->assertOk()
        ->viewData('batches')->pluck('id')->sort()->values()->all();
}

test('поиск по части названия сырья находит партии всех подходящих видов', function () {
    expect(searchBatchIds($this, ['product_search' => 'КВАРЦ']))
        ->toBe(collect([$this->quartzBlock->id, $this->quartzSlab->id])->sort()->values()->all());
});

test('ничего не подошло — пустой список', function () {
    expect(searchBatchIds($this, ['product_search' => 'мрамор']))->toBe([]);
});

test('выбор одного вида сырья работает как раньше', function () {
    expect(searchBatchIds($this, ['product_id' => $this->granite->product_id]))->toBe([$this->granite->id]);
});
