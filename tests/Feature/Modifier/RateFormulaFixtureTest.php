<?php

use App\Models\Department;
use App\Models\DepartmentModifier;
use App\Support\ModifierEngine;
use App\Support\RateFormula;
use Illuminate\Support\Facades\Cache;

/**
 * Серверный расчёт по общей фикстуре tests/fixtures/rate-formula.json (A6).
 *
 * Те же примеры проверяет tests/js/rate-formula.test.js для превью в формах:
 * поправили формулу в одной копии и забыли в другой — упадёт одна из сторон.
 */
function rateFixture(): array
{
    static $fixture;

    return $fixture ??= json_decode(
        file_get_contents(__DIR__ . '/../../fixtures/rate-formula.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/** Пример фикстуры → набор данных Pest с его названием. */
function rateCases(string $section, callable $name): array
{
    return collect(rateFixture()[$section])
        ->mapWithKeys(fn (array $case) => [$name($case) => [$case]])
        ->all();
}

test('доля коэффициента совпадает с фикстурой', function () {
    expect(RateFormula::COEFF_SHARE)->toBe((float) rateFixture()['coeffShare']);
});

test('stepped()', function (array $case) {
    expect(RateFormula::stepped($case['rate'], $case['coeff'], $case['step']))
        ->toBe((float) $case['expected']);
})->with(rateCases('stepped', fn ($c) => $c['name']));

test('formatCoeff()', function (array $case) {
    // На сервере разделитель — запятая (русские таблицы), в JS — точка
    expect(str_replace(',', '.', RateFormula::formatCoeff($case['value'])))
        ->toBe($case['expected']);
})->with(rateCases('formatCoeff', fn ($c) => var_export($c['value'], true)));

test('matchesPattern()', function (array $case) {
    expect(DepartmentModifier::matchesPattern($case['value'], $case['pattern']))
        ->toBe($case['expected']);
})->with(rateCases('matchesPattern', fn ($c) => var_export($c['value'], true) . ' ~ ' . var_export($c['pattern'], true)));

test('правила отдела: какие сработали и итоговые коэффициенты', function (array $case) {
    Cache::flush();

    $department = Department::create(['name' => 'Фикстура', 'is_active' => true]);

    foreach (rateFixture()['rules'] as $rule) {
        $department->modifiers()->create($rule + ['is_active' => true]);
    }

    $applied = ModifierEngine::resolve(
        ($case['noDepartment'] ?? false) ? null : $department->id,
        $case['scope'],
        $case['sku'] ?? null,
        $case['manualKeys'] ?? [],
        $case['batchSku'] ?? null,
        $case['disabledKeys'] ?? [],
    );

    expect($applied->pluck('key')->all())->toBe($case['expected']['keys'])
        ->and(ModifierEngine::workerCoeff($case['baseCoeff'], $applied))
        ->toBe((float) $case['expected']['workerCoeff'])
        ->and(ModifierEngine::masterCoeff($case['masterBaseCoeff'], $applied))
        ->toBe((float) $case['expected']['masterCoeff']);
})->with(rateCases('resolve', fn ($c) => $c['name']));
