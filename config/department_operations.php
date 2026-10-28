<?php

/*
 * Реестр операций: иконки шапки и матрица прав отдела.
 * Порядок ключей — порядок плиток в шапке. `group` (1–5) — цвет группы плиток
 * (layouts/partials/header.blade.php), `short_label` — подпись плитки, если `label`
 * не влезает в 52px.
 */
return [
    'home' => [
        'label'         => 'Главная',
        'icon'          => 'bi-house-door-fill',
        'group'         => 1,
        'route'         => 'home',
        'route_pattern' => 'home',
        'admin_only'    => true,
    ],
    'enterprise-dashboard' => [
        'label'                  => 'Итоги',
        'icon'                   => 'bi-graph-up',
        'group'                  => 1,
        'route'                  => 'admin.enterprise-dashboard',
        'route_pattern'          => 'admin.enterprise-dashboard',
        'configurable_positions' => ['Мастер', 'Помощник мастера'],
    ],
    'worker-dashboard' => [
        'label'                     => 'Выраб.',
        'icon'                      => 'bi-bar-chart-line',
        'group'                     => 1,
        'route'                     => 'worker.dashboard',
        'route_pattern'             => 'worker.dashboard',
        // Одна страница на обе роли: выработка работника и мастера по отделам.
        'positions_always_visible'  => ['Работник', 'Разнорабочий', 'Мастер', 'Помощник мастера'],
        'hide_for_admin'            => true,
    ],
    'stone-receptions' => [
        'label'                  => 'Приём',
        'icon'                   => 'bi-journal-text',
        'group'                  => 2,
        'route'                  => 'stone-receptions.logs',
        'route_pattern'          => 'stone-receptions.*',
        'configurable_positions' => ['Мастер', 'Помощник мастера'],
    ],
    'workshops' => [
        'label'                  => 'Цех',
        'icon'                   => 'bi-box-seam',
        'group'                  => 2,
        'route'                  => 'workshops.index',
        'route_pattern'          => 'workshops.*',
        'configurable_positions' => ['Мастер', 'Помощник мастера'],
    ],
    'raw-batches' => [
        'label'                  => 'Сырьё',
        'icon'                   => 'bi-arrow-left-right',
        'group'                  => 2,
        'route'                  => 'raw-batches.index',
        'route_pattern'          => 'raw-batches.*',
        'configurable_positions' => ['Мастер', 'Помощник мастера'],
    ],
    'supplier-orders' => [
        'label'                  => 'Приход',
        'icon'                   => 'bi-plus-circle',
        'group'                  => 3,
        'route'                  => 'supplier-orders.index',
        'route_pattern'          => 'supplier-orders.*',
        'configurable_positions' => ['Мастер', 'Помощник мастера'],
    ],
    'products' => [
        'label'                  => 'Товары',
        'icon'                   => 'bi-grid',
        'group'                  => 4,
        'route'                  => 'products.index',
        'route_pattern'          => 'products.*',
        'configurable_positions' => ['Мастер', 'Помощник мастера'],
    ],
    'orders' => [
        'label'                  => 'Заказы',
        'icon'                   => 'bi-bag',
        'group'                  => 4,
        'route'                  => 'orders.index',
        'route_pattern'          => 'orders.*',
        'configurable_positions' => ['Мастер', 'Помощник мастера'],
    ],
    'workers' => [
        'label'                  => 'Раб-ки',
        'icon'                   => 'bi-people',
        'group'                  => 5,
        'route'                  => 'workers.index',
        'route_pattern'          => 'workers.*',
        'configurable_positions' => ['Мастер', 'Помощник мастера'],
    ],
    'admin-settings' => [
        'label'         => 'Настройки',
        'short_label'   => 'Настр.',
        'icon'          => 'bi-gear',
        'group'         => 5,
        'route'         => 'admin.settings.index',
        'route_pattern' => ['admin.settings.*', 'admin.departments.*', 'admin.order-states.*'],
        'admin_only'    => true,
    ],
];
