<?php

use App\Models\Department;
use App\Models\DepartmentSetting;
use App\Models\Product;
use App\Models\Setting;
use App\Support\DepartmentSettings;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    $this->dept = Department::create(['name' => 'Резка', 'is_active' => true]);
});

function pieceRateFor(Department $dept, ?string $value): void
{
    if ($value === null) {
        DepartmentSetting::where('department_id', $dept->id)->where('key', 'PIECE_RATE')->delete();
    } else {
        DepartmentSetting::updateOrCreate(
            ['department_id' => $dept->id, 'key' => 'PIECE_RATE'],
            ['value' => $value],
        );
    }
    $dept->forgetSettingsCache();
}

describe('DepartmentSettings::pieceRate()', function () {

    test('отдел без своей ставки наследует глобальную', function () {
        Setting::set('PIECE_RATE', '390');

        expect(DepartmentSettings::pieceRate($this->dept->id))->toBe(390.0);
    });

    test('ставка отдела переопределяет глобальную', function () {
        Setting::set('PIECE_RATE', '390');
        pieceRateFor($this->dept, '420');

        expect(DepartmentSettings::pieceRate($this->dept->id))->toBe(420.0)
            ->and(DepartmentSettings::pieceRate(null))->toBe(390.0);
    });

    test('пустая строка трактуется как «не задано» и наследует глобальную', function () {
        Setting::set('PIECE_RATE', '390');
        pieceRateFor($this->dept, '');

        expect(DepartmentSettings::pieceRate($this->dept->id))->toBe(390.0);
    });

    test('без отдела и без глобальной настройки — default из конфига', function () {
        expect(DepartmentSettings::pieceRate(null))->toBe(390.0);
    });

    test('отделы изолированы друг от друга', function () {
        $other = Department::create(['name' => 'Цех', 'is_active' => true]);
        Setting::set('PIECE_RATE', '390');
        pieceRateFor($this->dept, '500');

        expect(DepartmentSettings::pieceRate($this->dept->id))->toBe(500.0)
            ->and(DepartmentSettings::pieceRate($other->id))->toBe(390.0);
    });

    test('сброс кэша отдела подхватывает новое значение', function () {
        Setting::set('PIECE_RATE', '390');
        pieceRateFor($this->dept, '420');
        expect(DepartmentSettings::pieceRate($this->dept->id))->toBe(420.0);

        pieceRateFor($this->dept, '450');
        expect(DepartmentSettings::pieceRate($this->dept->id))->toBe(450.0);
    });
});

describe('Product::prodCost() со ставкой отдела', function () {

    test('ставка отдела применяется к формуле, коэффициент 0 → базовая ставка', function () {
        Setting::set('PIECE_RATE', '390');
        pieceRateFor($this->dept, '420');

        $product = Product::factory()->create(['prod_cost_coeff' => 0]);

        expect($product->prodCost(0, $this->dept->id))->toBe(420.0)
            ->and($product->prodCost(0))->toBe(390.0);
    });

    test('коэффициент масштабирует ставку отдела: floor((420 + 420×0.17×1)/10)×10 = 490', function () {
        pieceRateFor($this->dept, '420');

        $product = Product::factory()->create(['prod_cost_coeff' => 1.0]);

        expect($product->prodCost(1.0, $this->dept->id))->toBe(490.0);
    });

    test('calculateWorkerPay умножает ставку отдела на количество', function () {
        pieceRateFor($this->dept, '420');

        $product = Product::factory()->create(['prod_cost_coeff' => 0]);

        expect($product->calculateWorkerPay(2.5, $this->dept->id))->toBe(1050.0);
    });

    test('Product::pieceRate() без отдела остаётся глобальной', function () {
        Setting::set('PIECE_RATE', '500');
        pieceRateFor($this->dept, '420');

        expect(Product::pieceRate())->toBe(500.0)
            ->and(Product::pieceRate($this->dept->id))->toBe(420.0);
    });
});
