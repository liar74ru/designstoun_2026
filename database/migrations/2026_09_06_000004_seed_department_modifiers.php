<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Перенос захардкоженных правил себестоимости в строки department_modifiers.
 *
 * Ключи, метки, цвета и порядок захардкожены намеренно: миграция обязана быть
 * самодостаточной и не зависеть от текущего состояния кода. Работаем только
 * через DB::table(), без моделей.
 *
 * Числовые значения берутся из настроек — если админ менял EDGING_COEFF или
 * UNDERCUT_PENALTY, правила заводятся с фактическими значениями, и расчёт не
 * поедет.
 *
 * Надбавка мастера за подкол переводится из рублей в коэффициент 3:
 * stepped(base, k) + 50 == stepped(base, k + 3). На боевой базе
 * master_cost_coeff принимает значения только 0 и 3 — для обоих равенство
 * точное. Плюс надбавка теперь растёт вместе с базовой ставкой сама.
 *
 * Правила заводятся ТОЛЬКО существующим отделам: наследования у модификаторов
 * нет, новый отдел стартует с пустым списком (как у накладных расходов).
 *
 * small_tile деньги не меняет и заводится ради плашки — раньше она была
 * захардкожена в шести шаблонах.
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'UNDERCUT_PENALTY'      => 1.5,
        'EDGING_COEFF'          => -2.5,
        'MASK_TILE_COEFF_BONUS' => 2.0,
    ];

    /** Коэффициент, заменяющий MASTER_UNDERCUT_RATE (50 ₽ при базе 100). */
    private const MASTER_UNDERCUT_COEFF = 3.0;

    public function up(): void
    {
        $undercut = $this->setting('UNDERCUT_PENALTY');
        $edging   = $this->setting('EDGING_COEFF');
        $mask     = $this->setting('MASK_TILE_COEFF_BONUS');
        $now      = now();

        $rules = [
            [
                'key'                      => 'mask_tile',
                'name'                     => 'Плитка-маска',
                'color'                    => '#0DCAF0',
                'trigger'                  => 'sku',
                'sku_pattern'              => '04-07-*',
                'available_when_batch_sku' => null,
                'sort_order'               => 10,
                'worker_coeff_delta'       => $mask,
                'worker_coeff_replace'     => null,
                'master_coeff_delta'       => null,
                'master_coeff_replace'     => null,
            ],
            [
                'key'                      => 'edging',
                'name'                     => 'Торцовка',
                'color'                    => '#0DCAF0',
                'trigger'                  => 'manual',
                'sku_pattern'              => null,
                // Чекбокс показывался только для партий сырья 04-XX — раньше это
                // жило в JS формы приёмки и нигде не настраивалось.
                'available_when_batch_sku' => '04-*',
                'sort_order'               => 20,
                'worker_coeff_delta'       => null,
                'worker_coeff_replace'     => $edging,
                'master_coeff_delta'       => null,
                'master_coeff_replace'     => null,
            ],
            [
                'key'                      => 'undercut',
                'name'                     => 'Подкол > 80%',
                'color'                    => '#FFC107',
                'trigger'                  => 'manual',
                'sku_pattern'              => null,
                'available_when_batch_sku' => null,
                'sort_order'               => 30,
                'worker_coeff_delta'       => -$undercut,
                'worker_coeff_replace'     => null,
                'master_coeff_delta'       => self::MASTER_UNDERCUT_COEFF,
                'master_coeff_replace'     => null,
            ],
            [
                'key'                      => 'small_tile',
                'name'                     => 'Мелкая плитка',
                'color'                    => '#6C757D',
                'trigger'                  => 'sku',
                'sku_pattern'              => '*-*-30',
                'available_when_batch_sku' => null,
                'sort_order'               => 40,
                'worker_coeff_delta'       => null,
                'worker_coeff_replace'     => null,
                'master_coeff_delta'       => null,
                'master_coeff_replace'     => null,
            ],
        ];

        // Все отделы, включая неактивные: включённый позже отдел не должен
        // остаться без правил.
        $departmentIds = DB::table('departments')->pluck('id');
        $rows = [];

        foreach ($departmentIds as $departmentId) {
            foreach ($rules as $rule) {
                $rows[] = array_merge($rule, [
                    'department_id' => $departmentId,
                    'applies_to'    => 'both',
                    'is_active'     => true,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            }
        }

        if ($rows) {
            DB::table('department_modifiers')->insert($rows);
        }

        $this->forgetCaches($departmentIds);
    }

    public function down(): void
    {
        $departmentIds = DB::table('departments')->pluck('id');

        DB::table('department_modifiers')
            ->whereIn('key', ['mask_tile', 'edging', 'undercut', 'small_tile'])
            ->delete();

        $this->forgetCaches($departmentIds);
    }

    private function setting(string $key): float
    {
        $value = DB::table('settings')->where('key', $key)->value('value');

        return ($value === null || $value === '')
            ? self::DEFAULTS[$key]
            : (float) $value;
    }

    private function forgetCaches(iterable $departmentIds): void
    {
        foreach ($departmentIds as $departmentId) {
            Cache::forget("dept.{$departmentId}.modifiers");
        }
    }
};
