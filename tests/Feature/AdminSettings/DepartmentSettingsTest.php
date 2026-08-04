<?php

use App\Models\Department;
use App\Models\DepartmentExpense;
use App\Models\DepartmentSetting;
use App\Models\Setting;
use App\Support\DepartmentSettings;
use Illuminate\Support\Facades\Cache;

// ══════════════════════════════════════════════════════════════════════════════
// DepartmentSettings — фолбэк ставок и сумма накладных расходов отдела
// ══════════════════════════════════════════════════════════════════════════════

beforeEach(function () {
    Cache::flush();
    $this->dept = Department::create(['name' => 'Отдел А', 'is_active' => true]);
});

describe('DepartmentSettings::get() — фолбэк ставок', function () {

    test('отдел не задан → берётся глобальное значение', function () {
        Setting::set('MASTER_UNDERCUT_RATE', '50');

        expect(DepartmentSettings::float(null, 'MASTER_UNDERCUT_RATE'))->toBe(50.0);
    });

    test('у отдела ключ не задан → берётся глобальное значение', function () {
        Setting::set('MASTER_UNDERCUT_RATE', '50');

        expect(DepartmentSettings::float($this->dept->id, 'MASTER_UNDERCUT_RATE'))->toBe(50.0);
    });

    test('у отдела ключ задан → своё значение перекрывает глобальное', function () {
        Setting::set('MASTER_UNDERCUT_RATE', '50');
        DepartmentSetting::create([
            'department_id' => $this->dept->id,
            'key'           => 'MASTER_UNDERCUT_RATE',
            'value'         => '95',
        ]);

        expect(DepartmentSettings::float($this->dept->id, 'MASTER_UNDERCUT_RATE'))->toBe(95.0);
        expect(DepartmentSettings::float(null, 'MASTER_UNDERCUT_RATE'))->toBe(50.0);
    });

    test('пустая строка в значении отдела трактуется как «не задано»', function () {
        Setting::set('MASTER_UNDERCUT_RATE', '50');
        DepartmentSetting::create([
            'department_id' => $this->dept->id,
            'key'           => 'MASTER_UNDERCUT_RATE',
            'value'         => '',
        ]);

        expect(DepartmentSettings::float($this->dept->id, 'MASTER_UNDERCUT_RATE'))->toBe(50.0);
    });
});

describe('DepartmentSettings::overheadPerUnit()', function () {

    test('суммирует строки расходов отдела', function () {
        DepartmentExpense::insert([
            ['department_id' => $this->dept->id, 'name' => 'Аренда',        'amount' => 35],
            ['department_id' => $this->dept->id, 'name' => 'Электричество', 'amount' => 30.50],
            ['department_id' => $this->dept->id, 'name' => 'Расход пилы',   'amount' => 50],
        ]);

        expect(DepartmentSettings::overheadPerUnit($this->dept->id))->toBe(115.50);
    });

    test('отдел без строк расходов → 0', function () {
        expect(DepartmentSettings::overheadPerUnit($this->dept->id))->toBe(0.0);
    });

    test('документ без отдела → 0, наследования из глобальных настроек нет', function () {
        Setting::set('ELECTRICITY', '999');
        DepartmentExpense::create([
            'department_id' => $this->dept->id,
            'name'          => 'Аренда',
            'amount'        => 35,
        ]);

        expect(DepartmentSettings::overheadPerUnit(null))->toBe(0.0);
    });

    test('расходы одного отдела не влияют на другой', function () {
        $other = Department::create(['name' => 'Отдел Б', 'is_active' => true]);

        DepartmentExpense::insert([
            ['department_id' => $this->dept->id, 'name' => 'Аренда', 'amount' => 35],
            ['department_id' => $other->id,      'name' => 'Кран',   'amount' => 200],
        ]);

        expect(DepartmentSettings::overheadPerUnit($this->dept->id))->toBe(35.0);
        expect(DepartmentSettings::overheadPerUnit($other->id))->toBe(200.0);
    });
});

describe('DepartmentSettings — кэш', function () {

    test('forgetSettingsCache() сбрасывает закэшированную ставку отдела', function () {
        DepartmentSetting::create([
            'department_id' => $this->dept->id,
            'key'           => 'MASTER_UNDERCUT_RATE',
            'value'         => '10',
        ]);
        expect(DepartmentSettings::float($this->dept->id, 'MASTER_UNDERCUT_RATE'))->toBe(10.0);

        DepartmentSetting::where('department_id', $this->dept->id)
            ->where('key', 'MASTER_UNDERCUT_RATE')
            ->update(['value' => '99']);

        // без сброса — значение остаётся из кэша
        expect(DepartmentSettings::float($this->dept->id, 'MASTER_UNDERCUT_RATE'))->toBe(10.0);

        $this->dept->forgetSettingsCache();
        expect(DepartmentSettings::float($this->dept->id, 'MASTER_UNDERCUT_RATE'))->toBe(99.0);
    });

    test('forgetSettingsCache() сбрасывает и закэшированную сумму расходов', function () {
        DepartmentExpense::create([
            'department_id' => $this->dept->id,
            'name'          => 'Аренда',
            'amount'        => 35,
        ]);
        expect(DepartmentSettings::overheadPerUnit($this->dept->id))->toBe(35.0);

        DepartmentExpense::where('department_id', $this->dept->id)->update(['amount' => 100]);
        expect(DepartmentSettings::overheadPerUnit($this->dept->id))->toBe(35.0);

        $this->dept->forgetSettingsCache();
        expect(DepartmentSettings::overheadPerUnit($this->dept->id))->toBe(100.0);
    });

    test('правка глобальной настройки подхватывается без сброса кэша отдела', function () {
        Setting::set('MASTER_UNDERCUT_RATE', '30');
        expect(DepartmentSettings::float($this->dept->id, 'MASTER_UNDERCUT_RATE'))->toBe(30.0);

        Setting::set('MASTER_UNDERCUT_RATE', '77');
        expect(DepartmentSettings::float($this->dept->id, 'MASTER_UNDERCUT_RATE'))->toBe(77.0);
    });
});

describe('DepartmentSettings::keys()', function () {

    test('содержит только ставки мастера — накладные больше не ключи настроек', function () {
        $keys = DepartmentSettings::keys();

        expect($keys)->toHaveCount(2)
            ->and($keys)->toContain('MASTER_BASE_RATE', 'MASTER_UNDERCUT_RATE')
            ->and($keys)->not->toContain('BLADE_WEAR', 'OTHER_COSTS', 'PIECE_RATE');
    });
});
