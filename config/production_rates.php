<?php

/**
 * Глобальные коэффициенты-модификаторы себестоимости.
 * Единая точка чтения — App\Support\ProductionRates.
 *
 * Здесь только значения, действующие одинаково во всех отделах. Ставки,
 * задаваемые per-department (PIECE_RATE, MASTER_BASE_RATE, MASTER_UNDERCUT_RATE),
 * описаны в config/department_settings.php и читаются через DepartmentSettings.
 *
 * Реестр используется как источник дефолтов, whitelist валидации и меток формы
 * в resources/views/admin/settings/index.blade.php, а также передаётся в JS
 * партиалом partials/production-rates-js.blade.php — чтобы превью в формах
 * считалось по тем же числам, что и сервер.
 */
return [
    'UNDERCUT_PENALTY' => [
        'label'          => 'Штраф за подкол > 80%',
        'hint'           => 'Вычитается из коэффициента продукта до округления ставки.',
        'default'        => 1.5,
        'allow_negative' => false,
    ],
    'EDGING_COEFF' => [
        'label'          => 'Коэффициент «Торцовка»',
        'hint'           => 'Полностью заменяет коэффициент продукта. Доступен для партий 04-XX. Может быть отрицательным.',
        'default'        => -2.5,
        'allow_negative' => true,
    ],
    'MASK_TILE_COEFF_BONUS' => [
        'label'          => 'Бонус за плитку-маску',
        'hint'           => 'Прибавляется к коэффициенту продукта для SKU 04-07-XX. Не применяется вместе с торцовкой.',
        'default'        => 2.0,
        'allow_negative' => false,
    ],
];
