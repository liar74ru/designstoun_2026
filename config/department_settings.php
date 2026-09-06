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
        'hint'  => 'Базовая ставка масштабируется коэффициентом продукта prod_cost_coeff.',
        'keys'  => [
            'PIECE_RATE' => ['label' => 'Базовая ставка', 'default' => 390],
        ],
    ],
    'master' => [
        'label' => 'Ставки мастера, ₽/м²',
        'hint'  => 'Базовая ставка масштабируется коэффициентом продукта master_cost_coeff.',
        'keys'  => [
            'MASTER_BASE_RATE'     => ['label' => 'Базовая ставка', 'default' => 100],
            'MASTER_UNDERCUT_RATE' => ['label' => 'Надбавка за подкол > 80%', 'default' => 50],
        ],
    ],
];
