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
 * надбавки пересчитываются пропорционально сами.
 *
 * Порядок (sort_order) значим: правила применяются подряд, `replace` обнуляет
 * накопленное, `delta` прибавляет. Поэтому торцовка отменяет бонус маски,
 * но не отменяет подкол.
 */
return [
    'mask_tile' => [
        'name'                     => 'Плитка-маска',
        'color'                    => '#0DCAF0',
        'trigger'                  => 'sku',
        'sku_pattern'              => '04-07-*',
        'available_when_batch_sku' => null,
        'applies_to'               => 'both',
        'sort_order'               => 10,
        'worker_coeff_delta'       => 2.0,
        'worker_coeff_replace'     => null,
        'master_coeff_delta'       => null,
        'master_coeff_replace'     => null,
    ],
    'edging' => [
        'name'                     => 'Торцовка',
        'color'                    => '#0DCAF0',
        'trigger'                  => 'manual',
        'sku_pattern'              => null,
        // Чекбокс исторически показывался только для партий сырья 04-XX.
        'available_when_batch_sku' => '04-*',
        'applies_to'               => 'both',
        'sort_order'               => 20,
        'worker_coeff_delta'       => null,
        'worker_coeff_replace'     => -2.5,
        'master_coeff_delta'       => null,
        'master_coeff_replace'     => null,
    ],
    'undercut' => [
        'name'                     => 'Подкол > 80%',
        'color'                    => '#FFC107',
        'trigger'                  => 'manual',
        'sku_pattern'              => null,
        'available_when_batch_sku' => null,
        'applies_to'               => 'both',
        'sort_order'               => 30,
        'worker_coeff_delta'       => -1.5,
        'worker_coeff_replace'     => null,
        // Заменяет прежнюю надбавку MASTER_UNDERCUT_RATE = 50 ₽:
        // stepped(base, k) + 50 == stepped(base, k + 3) при базе 100.
        'master_coeff_delta'       => 3.0,
        'master_coeff_replace'     => null,
    ],
    'small_tile' => [
        'name'                     => 'Мелкая плитка',
        'color'                    => '#6C757D',
        'trigger'                  => 'sku',
        'sku_pattern'              => '*-*-30',
        'available_when_batch_sku' => null,
        'applies_to'               => 'both',
        'sort_order'               => 40,
        // Деньги не меняет — правило существует ради плашки в интерфейсе.
        'worker_coeff_delta'       => null,
        'worker_coeff_replace'     => null,
        'master_coeff_delta'       => null,
        'master_coeff_replace'     => null,
    ],
];
