<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Удаление фиксированных накладных расходов из глобальных настроек
 * и из переопределений отдела — они перенесены в department_expenses
 * миграцией 2026_08_05_000002 (обязана отработать раньше).
 *
 * PACKAGING_COST удаляется как мёртвый ключ: он не участвует в расчётах
 * и отсутствует и в конфиге, и в сидере.
 */
return new class extends Migration
{
    /** Значения/метки для отката — копия того, что было в SettingsSeeder. */
    private const SEEDED = [
        ['key' => 'BLADE_WEAR',     'value' => '50',  'label' => 'Расход пилы, ₽/м²',      'description' => 'Затраты на износ пильного диска за единицу продукции.'],
        ['key' => 'RECEPTION_COST', 'value' => '170', 'label' => 'Приёмка, ₽/м²',          'description' => 'Стоимость приёмки продукции.'],
        ['key' => 'WASTE_REMOVAL',  'value' => '30',  'label' => 'Вывоз мусора, ₽/м²',     'description' => null],
        ['key' => 'ELECTRICITY',    'value' => '30',  'label' => 'Электричество, ₽/м²',    'description' => null],
        ['key' => 'PPE_COST',       'value' => '15',  'label' => 'СИЗ, ₽/м²',              'description' => 'Средства индивидуальной защиты.'],
        ['key' => 'FORKLIFT_COST',  'value' => '30',  'label' => 'Кара/погрузчик, ₽/м²',   'description' => null],
        ['key' => 'MACHINE_COST',   'value' => '50',  'label' => 'Станок/камнекол, ₽/м²',  'description' => null],
        ['key' => 'RENT_COST',      'value' => '35',  'label' => 'Аренда, ₽/м²',           'description' => null],
        ['key' => 'OTHER_COSTS',    'value' => '150', 'label' => 'Прочее, ₽/м²',           'description' => null],
    ];

    private function keys(): array
    {
        return array_merge(array_column(self::SEEDED, 'key'), ['PACKAGING_COST']);
    }

    public function up(): void
    {
        $keys = $this->keys();

        DB::table('department_settings')->whereIn('key', $keys)->delete();
        DB::table('settings')->whereIn('key', $keys)->delete();

        foreach ($keys as $key) {
            Cache::forget('setting.' . $key);
        }

        // В карте настроек отдела могли остаться удалённые ключи.
        foreach (DB::table('departments')->pluck('id') as $departmentId) {
            Cache::forget("dept.{$departmentId}.settings");
        }
    }

    public function down(): void
    {
        $now = now();

        foreach (self::SEEDED as $row) {
            DB::table('settings')->updateOrInsert(
                ['key' => $row['key']],
                [
                    'value'       => $row['value'],
                    'label'       => $row['label'],
                    'description' => $row['description'],
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
            );
            Cache::forget('setting.' . $row['key']);
        }
    }
};
