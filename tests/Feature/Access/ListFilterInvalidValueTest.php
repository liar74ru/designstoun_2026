<?php

use Tests\Helpers\ReceptionTestHelper as H;

/*
 * Фильтры списков из адресной строки: ссылку правят руками, и строка в числовой или uuid-колонке
 * на PostgreSQL роняет запрос (500). Неверное значение считается незаданным — QueryParam.
 * На SQLite тест проходит и без защиты; ловит регрессию прогон на PostgreSQL в CI.
 */

dataset('списки с неверным фильтром', function () {
    $lists = [
        'stone-receptions.index' => ['cutter_id', 'raw_product_id', 'product_id', 'department_id'],
        'workshops.index'        => ['packer_id', 'raw_product_id', 'product_id', 'package_product_id', 'department_id'],
        'raw-batches.index'      => ['current_worker_id', 'product_id', 'department_id'],
        'orders.index'           => ['counterparty_id', 'department_id'],
        'supplier-orders.index'  => ['counterparty_id', 'department_id'],
        'workers.index'          => ['department_id'],
    ];

    $cases = [];
    foreach ($lists as $route => $keys) {
        foreach ($keys as $key) {
            $cases["$route filter[$key]"] = [$route, ['filter' => [$key => 'abc']]];
        }
        $cases["$route date_from"] = [$route, ['date_from' => 'abc', 'date_to' => 'abc']];
    }

    // Журнал приёмок — тот же адрес с view=logs, свой запрос (getFilteredLogs)
    foreach (['cutter_id', 'raw_product_id', 'product_id', 'department_id'] as $key) {
        $cases["журнал приёмок filter[$key]"] = ['stone-receptions.index', ['view' => 'logs', 'filter' => [$key => 'abc']]];
    }
    $cases['журнал приёмок date_from'] = ['stone-receptions.index', ['view' => 'logs', 'date_from' => 'abc', 'date_to' => 'abc']];

    return $cases;
});

test('неверный id или дата в фильтре списка не роняет страницу', function (string $route, array $query) {
    $this->actingAs(H::adminUser())
        ->get(route($route, $query))
        ->assertOk();
})->with('списки с неверным фильтром');
