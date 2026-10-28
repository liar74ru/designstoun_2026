<?php

/**
 * Числовые настройки, задаваемые per-department с фолбэком:
 * настройка отдела → глобальный Setting → default в коде.
 * Единая точка чтения — App\Support\DepartmentSettings.
 *
 * Накладные расходы здесь НЕ описываются: у каждого отдела свой произвольный
 * набор строк в таблице `department_expenses`, наследования у них нет
 * (см. DepartmentSettings::overheadPerUnit()).
 *
 * Группы используются как whitelist валидации и как источник меток формы
 * в resources/views/admin/departments/show.blade.php.
 */
return [
    'worker' => [
        'label' => 'Ставки пильщика, ₽/ед',
        'hint'  => 'Базовая ставка масштабируется коэффициентом продукта prod_cost_coeff. '
            . 'Ставка округляется вниз до кратного шагу: 10 — до десятков, 1 — до рубля.',
        'keys'  => [
            'PIECE_RATE'       => ['label' => 'Базовая ставка', 'default' => 390],
            'PIECE_ROUND_STEP' => ['label' => 'Шаг округления', 'default' => 10],
        ],
    ],
    'master' => [
        'label' => 'Ставки мастера, ₽/м²',
        'hint'  => 'Базовая ставка масштабируется коэффициентом продукта master_cost_coeff. '
            . 'Ставка округляется вниз до кратного шагу: 10 — до десятков, 1 — до рубля.',
        'keys'  => [
            'MASTER_BASE_RATE'  => ['label' => 'Базовая ставка', 'default' => 100],
            'MASTER_ROUND_STEP' => ['label' => 'Шаг округления', 'default' => 10],
            // MASTER_UNDERCUT_RATE убрана: подкол мастеру — правило отдела (master_coeff_delta)
        ],
    ],
];
