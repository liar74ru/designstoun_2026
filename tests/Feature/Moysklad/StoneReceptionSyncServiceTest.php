<?php

use App\Models\Department;
use App\Models\DepartmentExpense;
use App\Services\Moysklad\StoneReceptionSyncService;
use Illuminate\Support\Facades\Cache;

// ══════════════════════════════════════════════════════════════════════════════
// StoneReceptionSyncService — manualCostPerUnit()
// ══════════════════════════════════════════════════════════════════════════════

beforeEach(fn () => Cache::flush());

describe('StoneReceptionSyncService::manualCostPerUnit()', function () {

    test('суммирует строки накладных расходов отдела', function () {
        $dept = Department::create(['name' => 'Отдел накладных', 'is_active' => true]);
        DepartmentExpense::insert([
            ['department_id' => $dept->id, 'name' => 'Расход пилы',   'amount' => 100],
            ['department_id' => $dept->id, 'name' => 'Приёмка',       'amount' => 50],
            ['department_id' => $dept->id, 'name' => 'Электричество', 'amount' => 80],
        ]);

        expect(app(StoneReceptionSyncService::class)->manualCostPerUnit($dept->id))->toBe(230.0);
    });

    test('отдел без расходов → 0', function () {
        $dept = Department::create(['name' => 'Пустой отдел', 'is_active' => true]);

        expect(app(StoneReceptionSyncService::class)->manualCostPerUnit($dept->id))->toBe(0.0);
    });

    test('документ без отдела → 0, глобального фолбэка больше нет', function () {
        expect(app(StoneReceptionSyncService::class)->manualCostPerUnit())->toBe(0.0);
    });

    test('дробные суммы складываются без потерь', function () {
        $dept = Department::create(['name' => 'Отдел дробей', 'is_active' => true]);
        DepartmentExpense::insert([
            ['department_id' => $dept->id, 'name' => 'Расход пилы', 'amount' => 100.50],
            ['department_id' => $dept->id, 'name' => 'Приёмка',     'amount' => 200.25],
        ]);

        expect(app(StoneReceptionSyncService::class)->manualCostPerUnit($dept->id))->toBe(300.75);
    });

    test('у каждого отдела свой набор расходов', function () {
        $cutting = Department::create(['name' => 'Резка', 'is_active' => true]);
        $quarry  = Department::create(['name' => 'Карьер', 'is_active' => true]);

        DepartmentExpense::insert([
            ['department_id' => $cutting->id, 'name' => 'Расход пилы', 'amount' => 100],
            ['department_id' => $cutting->id, 'name' => 'Аренда цеха', 'amount' => 35],
            ['department_id' => $quarry->id,  'name' => 'Экскаватор',  'amount' => 500],
        ]);

        $service = app(StoneReceptionSyncService::class);

        expect($service->manualCostPerUnit($cutting->id))->toBe(135.0);
        expect($service->manualCostPerUnit($quarry->id))->toBe(500.0);
    });
});
