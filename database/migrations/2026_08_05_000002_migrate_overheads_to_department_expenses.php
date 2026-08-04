<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Перенос фиксированных накладных расходов в произвольные строки отдела.
 *
 * Ключи и метки захардкожены намеренно: до этой миграции они жили в
 * config/department_settings.php, откуда группа overheads удалена. Миграция
 * обязана быть самодостаточной и не зависеть от текущего состояния кода,
 * поэтому здесь же — работа через DB::table(), без моделей Setting/DepartmentSetting.
 *
 * Эффективное значение считается той же цепочкой, что была в
 * DepartmentSettings::get(): переопределение отдела → глобальная настройка.
 * Поэтому суммарная себестоимость каждого отдела не меняется.
 *
 * Нулевые и незаданные статьи не переносятся: на сумму они не влияют,
 * но захламили бы список пустышками.
 *
 * down() восстанавливает department_settings по совпадению имени строки с меткой.
 * Строки, добавленные админом вручную ПОСЛЕ миграции, в старую модель
 * не восстанавливаются — это принципиально невозможно.
 */
return new class extends Migration
{
    private const OVERHEADS = [
        'BLADE_WEAR'     => 'Расход пилы',
        'RECEPTION_COST' => 'Приёмка',
        'WASTE_REMOVAL'  => 'Вывоз мусора',
        'ELECTRICITY'    => 'Электричество',
        'PPE_COST'       => 'СИЗ',
        'FORKLIFT_COST'  => 'Кара/погрузчик',
        'MACHINE_COST'   => 'Станок/камнекол',
        'RENT_COST'      => 'Аренда',
        'OTHER_COSTS'    => 'Прочее',
    ];

    public function up(): void
    {
        $keys = array_keys(self::OVERHEADS);
        $now  = now();

        $globals = DB::table('settings')
            ->whereIn('key', $keys)
            ->pluck('value', 'key');

        $overrides = DB::table('department_settings')
            ->whereIn('key', $keys)
            ->get(['department_id', 'key', 'value'])
            ->groupBy('department_id');

        // По всем отделам, включая неактивные: иначе включённый позже отдел
        // остался бы без себестоимости.
        $departmentIds = DB::table('departments')->pluck('id');
        $rows = [];

        foreach ($departmentIds as $departmentId) {
            $deptOverrides = ($overrides[$departmentId] ?? collect())->keyBy('key');

            foreach (self::OVERHEADS as $key => $label) {
                $override = $deptOverrides[$key]->value ?? null;
                $value    = ($override !== null && $override !== '')
                    ? $override
                    : ($globals[$key] ?? null);

                if ((float) $value <= 0) {
                    continue;
                }

                $rows[] = [
                    'department_id' => $departmentId,
                    'name'          => $label,
                    'amount'        => (float) $value,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];
            }
        }

        if ($rows) {
            DB::table('department_expenses')->insert($rows);
        }

        $this->forgetCaches($departmentIds);
    }

    public function down(): void
    {
        $labelToKey = array_flip(self::OVERHEADS);

        $expenses = DB::table('department_expenses')->get(['department_id', 'name', 'amount']);

        foreach ($expenses as $expense) {
            $key = $labelToKey[$expense->name] ?? null;
            if ($key === null) {
                continue;
            }

            DB::table('department_settings')->updateOrInsert(
                ['department_id' => $expense->department_id, 'key' => $key],
                ['value' => (string) (float) $expense->amount, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        DB::table('department_expenses')->delete();

        $this->forgetCaches(DB::table('departments')->pluck('id'));
    }

    private function forgetCaches(iterable $departmentIds): void
    {
        foreach ($departmentIds as $departmentId) {
            Cache::forget("dept.{$departmentId}.settings");
            Cache::forget("dept.{$departmentId}.expenses");
        }
    }
};
