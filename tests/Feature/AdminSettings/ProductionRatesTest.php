<?php

use App\Models\Setting;
use App\Support\ProductionRates;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

describe('ProductionRates — чтение значений', function () {

    test('без записи в БД возвращает default из конфига', function () {
        expect(ProductionRates::undercutPenalty())->toBe(1.5)
            ->and(ProductionRates::edgingCoeff())->toBe(-2.5)
            ->and(ProductionRates::maskTileBonus())->toBe(2.0);
    });

    test('значение из БД переопределяет default', function () {
        Setting::set('UNDERCUT_PENALTY', '3');
        Setting::set('EDGING_COEFF', '-4.25');
        Setting::set('MASK_TILE_COEFF_BONUS', '0');

        expect(ProductionRates::undercutPenalty())->toBe(3.0)
            ->and(ProductionRates::edgingCoeff())->toBe(-4.25)
            ->and(ProductionRates::maskTileBonus())->toBe(0.0);
    });

    test('правка настройки подхватывается сразу (кэш Setting сбрасывается)', function () {
        expect(ProductionRates::undercutPenalty())->toBe(1.5);

        Setting::set('UNDERCUT_PENALTY', '2');

        expect(ProductionRates::undercutPenalty())->toBe(2.0);
    });

    test('неизвестный ключ → 0, без исключения', function () {
        expect(ProductionRates::get('NO_SUCH_KEY'))->toBe(0.0);
    });
});

describe('ProductionRates — реестр', function () {

    test('keys() содержит все три коэффициента и не содержит ставок отдела', function () {
        $keys = ProductionRates::keys();

        expect($keys)->toHaveCount(3)
            ->and($keys)->toContain('UNDERCUT_PENALTY', 'EDGING_COEFF', 'MASK_TILE_COEFF_BONUS')
            ->and($keys)->not->toContain('PIECE_RATE', 'MASTER_BASE_RATE');
    });

    test('all() отдаёт карту ключ → значение по всему реестру', function () {
        Setting::set('EDGING_COEFF', '-1');

        expect(ProductionRates::all())->toBe([
            'UNDERCUT_PENALTY'      => 1.5,
            'EDGING_COEFF'          => -1.0,
            'MASK_TILE_COEFF_BONUS' => 2.0,
        ]);
    });

    test('отрицательные значения разрешены только торцовке', function () {
        expect(ProductionRates::allowsNegative('EDGING_COEFF'))->toBeTrue()
            ->and(ProductionRates::allowsNegative('UNDERCUT_PENALTY'))->toBeFalse()
            ->and(ProductionRates::allowsNegative('MASK_TILE_COEFF_BONUS'))->toBeFalse()
            ->and(ProductionRates::allowsNegative('NO_SUCH_KEY'))->toBeFalse();
    });
});
