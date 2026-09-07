<?php

use App\Models\Department;
use App\Models\Product;
use App\Models\Setting;
use App\Support\DepartmentSettings;
use App\Support\ItemCost;
use App\Support\ModifierEngine;
use App\Support\RateFormula;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\ReceptionTestHelper as H;

/**
 * Расчёт по правилам обязан совпадать с прежней захардкоженной формулой.
 * Это главная защита этапа: модель данных сменилась, суммы — нет.
 */

beforeEach(function () {
    Cache::flush();
    Setting::updateOrCreate(['key' => 'PIECE_RATE'],            ['value' => '390']);
    Setting::updateOrCreate(['key' => 'MASTER_BASE_RATE'],      ['value' => '100']);
    Setting::updateOrCreate(['key' => 'UNDERCUT_PENALTY'],      ['value' => '1.5']);
    Setting::updateOrCreate(['key' => 'EDGING_COEFF'],          ['value' => '-2.5']);
    Setting::updateOrCreate(['key' => 'MASK_TILE_COEFF_BONUS'], ['value' => '2.0']);

    $this->dept = H::departmentWithModifiers();
});

/**
 * Ожидаемый коэффициент пильщика: база плюс сумма сработавших правил.
 *
 * Для подкола и плитки-маски совпадает с прежней захардкоженной формулой.
 * Торцовка раньше заменяла коэффициент на −2.5 при любом продукте, а теперь
 * тоже слагаемое — смена поведения осознанная, см. миграцию
 * 2026_09_07_000001_simplify_department_modifiers.
 */
function expectedWorkerCoeff(float $base, bool $undercut, bool $edging, bool $maskTile): float
{
    return $base
        + ($maskTile ? 2.0 : 0.0)
        + ($edging ? -2.5 : 0.0)
        + ($undercut ? -1.5 : 0.0);
}

describe('Коэффициент пильщика — сумма правил', function () {

    test('все комбинации флагов и SKU', function () {
        foreach ([0.0, 1.0, 3.0, 5.0] as $base) {
            foreach ([false, true] as $undercut) {
                foreach ([false, true] as $edging) {
                    foreach (['04-01-20', '04-07-20'] as $sku) {
                        $product = Product::factory()->create([
                            'sku'             => $sku,
                            'prod_cost_coeff' => $base,
                        ]);

                        $cost = ItemCost::compute(
                            $product,
                            $this->dept->id,
                            'reception',
                            ModifierEngine::manualKeysFromLegacyFlags($undercut, $edging),
                            '04-01',
                        );

                        $expected = expectedWorkerCoeff($base, $undercut, $edging, str_starts_with($sku, '04-07-'));

                        expect((float) $cost['attributes']['effective_cost_coeff'])
                            ->toBe($expected, "base={$base} u={$undercut} e={$edging} sku={$sku}");
                    }
                }
            }
        }
    });
});

describe('Ставка мастера: коэффициент 3 заменяет надбавку 50 ₽', function () {

    test('на реальных значениях master_cost_coeff (0 и 3) суммы прежние', function () {
        // Прежняя формула: stepped(100, k) + 50 при подколе
        foreach ([0.0 => [100.0, 150.0], 3.0 => [150.0, 200.0]] as $k => [$plain, $withUndercut]) {
            $product = Product::factory()->create([
                'sku'               => '04-01-20',
                'prod_cost_coeff'   => 1.0,
                'master_cost_coeff' => $k,
            ]);

            $without = ItemCost::compute($product, $this->dept->id, 'reception', []);
            $with    = ItemCost::compute($product, $this->dept->id, 'reception', ['undercut'], '04-01');

            expect((float) $without['attributes']['master_cost_per_m2'])->toBe($plain)
                ->and((float) $with['attributes']['master_cost_per_m2'])->toBe($withUndercut);
        }
    });

    test('надбавка растёт вместе с базовой ставкой — ради этого и переходили на коэффициент', function () {
        $product = Product::factory()->create(['master_cost_coeff' => 0.0, 'sku' => '04-01-20']);

        $atBase100 = ItemCost::compute($product, $this->dept->id, 'reception', ['undercut'], '04-01');

        Setting::set('MASTER_BASE_RATE', '110');
        Cache::flush();

        $atBase110 = ItemCost::compute($product, $this->dept->id, 'reception', ['undercut'], '04-01');

        // База 100 → 150 (надбавка 50); база 110 → 160 (надбавка 50, пропорционально)
        expect((float) $atBase100['attributes']['master_cost_per_m2'])->toBe(150.0)
            ->and((float) $atBase110['attributes']['master_cost_per_m2'])->toBe(160.0);
    });
});

describe('Задвоение бонуса маски вылечено', function () {

    test('повторный пересчёт от сохранённой базы не сдвигает коэффициент', function () {
        $product = Product::factory()->create([
            'sku'             => '04-07-20',
            'prod_cost_coeff' => 5.0,
        ]);

        // Первый расчёт: база 5, бонус маски +2 → 7
        $first = ItemCost::compute($product, $this->dept->id, 'reception', []);
        expect((float) $first['attributes']['base_cost_coeff'])->toBe(5.0)
            ->and((float) $first['attributes']['effective_cost_coeff'])->toBe(7.0);

        // Правка формой: база уходит и возвращается как есть — бонус не задваивается
        $second = ItemCost::computeFromBase(
            $product,
            (float) $first['attributes']['base_cost_coeff'],
            $this->dept->id,
            'reception',
            [],
        );

        expect((float) $second['attributes']['effective_cost_coeff'])->toBe(7.0);

        $third = ItemCost::computeFromBase(
            $product,
            (float) $second['attributes']['base_cost_coeff'],
            $this->dept->id,
            'reception',
            [],
        );

        expect((float) $third['attributes']['effective_cost_coeff'])->toBe(7.0);
    });
});

describe('Отдел без правил', function () {

    test('коэффициент равен базовому, надбавок нет', function () {
        $bare    = Department::create(['name' => 'Без правил', 'is_active' => true]);
        $product = Product::factory()->create(['sku' => '04-07-20', 'prod_cost_coeff' => 5.0]);

        $cost = ItemCost::compute($product, $bare->id, 'reception', ['undercut', 'edging'], '04-01');

        expect((float) $cost['attributes']['effective_cost_coeff'])->toBe(5.0)
            ->and($cost['modifiers'])->toBeEmpty();
    });
});

describe('Бонус маски теперь работает и в цехе', function () {

    test('одинаковый коэффициент в приёмке и в цехе', function () {
        $product = Product::factory()->create(['sku' => '04-07-20', 'prod_cost_coeff' => 5.0]);

        $reception = ItemCost::compute($product, $this->dept->id, 'reception', []);
        $workshop  = ItemCost::compute($product, $this->dept->id, 'workshop', []);

        expect((float) $workshop['attributes']['effective_cost_coeff'])
            ->toBe((float) $reception['attributes']['effective_cost_coeff'])
            ->toBe(7.0);
    });
});

describe('Ставка пильщика считается по отделу', function () {

    test('своя ставка отдела применяется к коэффициенту правил', function () {
        $this->dept->settings()->create(['key' => 'PIECE_RATE', 'value' => '420']);
        $this->dept->forgetSettingsCache();

        $product = Product::factory()->create(['sku' => '04-01-20', 'prod_cost_coeff' => 1.0]);
        $cost    = ItemCost::compute($product, $this->dept->id, 'reception', ['undercut'], '04-01');

        $expected = RateFormula::stepped(
            DepartmentSettings::pieceRate($this->dept->id),
            (float) $cost['attributes']['effective_cost_coeff'],
        );

        expect((float) $cost['attributes']['worker_cost_per_m2'])->toBe($expected);
    });
});
