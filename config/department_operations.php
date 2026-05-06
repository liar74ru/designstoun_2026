<?php

return [
    'master-dashboard' => [
        'label'                    => 'Дашборд',
        'icon'                     => 'bi-bar-chart-line',
        'route'                    => 'master.dashboard',
        'route_pattern'            => 'master.dashboard',
        'positions_always_visible' => ['Мастер', 'Помощник мастера'],
    ],
    'worker-dashboard' => [
        'label'                     => 'Выраб.',
        'icon'                      => 'bi-bar-chart-line',
        'route'                     => 'worker.dashboard',
        'route_pattern'             => 'worker.dashboard',
        'positions_always_visible'  => ['Работник', 'Разнорабочий'],
    ],
    'stone-receptions' => [
        'label'                  => 'Приём',
        'icon'                   => 'bi-journal-text',
        'route'                  => 'stone-receptions.logs',
        'route_pattern'          => 'stone-receptions.*',
        'configurable_positions' => ['Мастер', 'Помощник мастера'],
    ],
    'packagings' => [
        'label'                  => 'Упак.',
        'icon'                   => 'bi-box-seam',
        'route'                  => 'packagings.index',
        'route_pattern'          => 'packagings.*',
        'configurable_positions' => ['Мастер', 'Помощник мастера'],
    ],
    'raw-batches' => [
        'label'                  => 'Сырьё',
        'icon'                   => 'bi-arrow-left-right',
        'route'                  => 'raw-batches.index',
        'route_pattern'          => 'raw-batches.*',
        'configurable_positions' => ['Мастер', 'Помощник мастера'],
    ],
    'supplier-orders' => [
        'label'                  => 'Приход',
        'icon'                   => 'bi-plus-circle',
        'route'                  => 'supplier-orders.index',
        'route_pattern'          => 'supplier-orders.*',
        'configurable_positions' => ['Мастер', 'Помощник мастера'],
    ],
    'products' => [
        'label'                  => 'Товары',
        'icon'                   => 'bi-box-seam',
        'route'                  => 'products.index',
        'route_pattern'          => 'products.*',
        'configurable_positions' => ['Мастер', 'Помощник мастера'],
    ],
    'workers' => [
        'label'                  => 'Раб-ки',
        'icon'                   => 'bi-people',
        'route'                  => 'workers.index',
        'route_pattern'          => 'workers.*',
        'configurable_positions' => ['Мастер', 'Помощник мастера'],
    ],
    'orders' => [
        'label'                  => 'Заказы',
        'icon'                   => 'bi-bag',
        'route'                  => 'orders.index',
        'route_pattern'          => 'orders.*',
        'configurable_positions' => ['Мастер', 'Помощник мастера'],
    ],
    'admin-settings' => [
        'label'         => 'Настройки',
        'icon'          => 'bi-gear',
        'route'         => 'admin.settings.index',
        'route_pattern' => 'admin.settings.*',
        'admin_only'    => true,
    ],
];
