<?php

/**
 * Набор правил-модификаторов себестоимости «по умолчанию».
 *
 * Используется для заполнения нового отдела стандартными правилами. Сам по себе
 * ни на что не влияет: правила действуют, только когда записаны в
 * `department_modifiers` конкретного отдела — наследования у них нет.
 *
 * Миграция 2026_09_06_000004_seed_department_modifiers держит собственную
 * замороженную копию этого набора и на конфиг не смотрит: миграция обязана
 * оставаться самодостаточной и не ломаться при эволюции конфигурации.
 *
 * Эффект задаётся коэффициентами, не рублями: при подъёме базовой ставки
 * надбавки пересчитываются пропорционально сами. Значения складываются с
 * коэффициентом продукта, поэтому сработать может несколько правил сразу
 * и порядок на результат не влияет.
 */
return [
    'mask_tile' => [
        'name'                     => 'Плитка-маска',
        'color'                    => '#0DCAF0',
        'trigger'                  => 'sku',
        'sku_pattern'              => '04-07-*',
        'available_when_batch_sku' => null,
        'applies_to'               => 'both',
        'worker_coeff_delta'       => 2.0,
        'master_coeff_delta'       => null,
    ],
    'edging' => [
        'name'                     => 'Торцовка',
        'color'                    => '#0DCAF0',
        'trigger'                  => 'manual',
        'sku_pattern'              => null,
        // Чекбокс исторически показывался только для партий сырья 04-XX.
        'available_when_batch_sku' => '04-*',
        'applies_to'               => 'both',
        'worker_coeff_delta'       => -2.5,
        'master_coeff_delta'       => null,
    ],
    'undercut' => [
        'name'                     => 'Подкол > 80%',
        'color'                    => '#FFC107',
        'trigger'                  => 'manual',
        'sku_pattern'              => null,
        'available_when_batch_sku' => null,
        'applies_to'               => 'both',
        'worker_coeff_delta'       => -1.5,
        // Заменяет прежнюю надбавку MASTER_UNDERCUT_RATE = 50 ₽:
        // stepped(base, k) + 50 == stepped(base, k + 3) при базе 100.
        'master_coeff_delta'       => 3.0,
    ],
    'small_tile' => [
        'name'                     => 'Мелкая плитка',
        'color'                    => '#6C757D',
        'trigger'                  => 'sku',
        'sku_pattern'              => '*-*-30',
        'available_when_batch_sku' => null,
        'applies_to'               => 'both',
        // Деньги не меняет — правило существует ради плашки в интерфейсе.
        'worker_coeff_delta'       => null,
        'master_coeff_delta'       => null,
    ],
];
