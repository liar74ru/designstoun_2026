<?php

use App\Models\Department;
use App\Models\DepartmentModifier;
use App\Support\ModifierEngine;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    $this->dept = Department::create(['name' => 'Резка', 'is_active' => true]);
});

function makeRule(Department $dept, array $attrs = []): DepartmentModifier
{
    return $dept->modifiers()->create(array_merge([
        'key'        => 'rule',
        'name'       => 'Правило',
        'trigger'    => DepartmentModifier::TRIGGER_MANUAL,
        'applies_to' => DepartmentModifier::SCOPE_BOTH,
        'sort_order' => 10,
        'is_active'  => true,
    ], $attrs));
}

describe('ModifierEngine::apply() — композиция правил', function () {

    test('без правил коэффициент равен базовому', function () {
        expect(ModifierEngine::apply(2.0, [], DepartmentModifier::ROLE_WORKER))->toBe(2.0);
    });

    test('delta прибавляется к базе', function () {
        $rule = makeRule($this->dept, ['worker_coeff_delta' => -1.5]);

        expect(ModifierEngine::apply(2.0, [$rule], DepartmentModifier::ROLE_WORKER))->toBe(0.5);
    });

    test('replace подменяет базу целиком', function () {
        $rule = makeRule($this->dept, ['worker_coeff_replace' => -2.5]);

        expect(ModifierEngine::apply(2.0, [$rule], DepartmentModifier::ROLE_WORKER))->toBe(-2.5);
    });

    test('replace обнуляет накопленные ранее delta, но не отменяет последующие', function () {
        // Порядок как у боевых правил: маска (10) → торцовка (20) → подкол (30)
        $mask     = makeRule($this->dept, ['key' => 'mask', 'sort_order' => 10, 'worker_coeff_delta' => 2.0]);
        $edging   = makeRule($this->dept, ['key' => 'edg',  'sort_order' => 20, 'worker_coeff_replace' => -2.5]);
        $undercut = makeRule($this->dept, ['key' => 'und',  'sort_order' => 30, 'worker_coeff_delta' => -1.5]);

        // 3 + 2 = 5 → replace → −2.5 → −1.5 = −4.0
        expect(ModifierEngine::apply(3.0, [$mask, $edging, $undercut], DepartmentModifier::ROLE_WORKER))
            ->toBe(-4.0);
    });

    test('порядок применения не зависит от порядка передачи', function () {
        $mask   = makeRule($this->dept, ['key' => 'mask', 'sort_order' => 10, 'worker_coeff_delta' => 2.0]);
        $edging = makeRule($this->dept, ['key' => 'edg',  'sort_order' => 20, 'worker_coeff_replace' => -2.5]);

        expect(ModifierEngine::apply(3.0, [$edging, $mask], DepartmentModifier::ROLE_WORKER))->toBe(-2.5)
            ->and(ModifierEngine::apply(3.0, [$mask, $edging], DepartmentModifier::ROLE_WORKER))->toBe(-2.5);
    });

    test('роли независимы: правило может влиять только на мастера', function () {
        $rule = makeRule($this->dept, ['master_coeff_delta' => 3.0]);

        expect(ModifierEngine::apply(0.0, [$rule], DepartmentModifier::ROLE_WORKER))->toBe(0.0)
            ->and(ModifierEngine::apply(0.0, [$rule], DepartmentModifier::ROLE_MASTER))->toBe(3.0);
    });
});

describe('ModifierEngine::resolve() — какие правила сработали', function () {

    test('sku-правило срабатывает по маске продукта', function () {
        makeRule($this->dept, [
            'key'         => 'mask_tile',
            'trigger'     => DepartmentModifier::TRIGGER_SKU,
            'sku_pattern' => '04-07-*',
        ]);

        $hit  = ModifierEngine::resolve($this->dept->id, 'reception', '04-07-30');
        $miss = ModifierEngine::resolve($this->dept->id, 'reception', '04-01-30');

        expect($hit->pluck('key')->all())->toBe(['mask_tile'])
            ->and($miss)->toBeEmpty();
    });

    test('маска со звёздочкой в середине: *-*-30', function () {
        makeRule($this->dept, [
            'key'         => 'small_tile',
            'trigger'     => DepartmentModifier::TRIGGER_SKU,
            'sku_pattern' => '*-*-30',
        ]);

        expect(ModifierEngine::resolve($this->dept->id, 'reception', '04-07-30'))->toHaveCount(1)
            ->and(ModifierEngine::resolve($this->dept->id, 'reception', '04-07-20'))->toBeEmpty();
    });

    test('ручное правило срабатывает только по переданному ключу', function () {
        makeRule($this->dept, ['key' => 'undercut']);

        expect(ModifierEngine::resolve($this->dept->id, 'reception', null, ['undercut']))->toHaveCount(1)
            ->and(ModifierEngine::resolve($this->dept->id, 'reception', null, []))->toBeEmpty();
    });

    test('условие доступности отсекает правило по SKU партии', function () {
        makeRule($this->dept, ['key' => 'edging', 'available_when_batch_sku' => '04-*']);

        expect(ModifierEngine::resolve($this->dept->id, 'reception', null, ['edging'], '04-01'))->toHaveCount(1)
            ->and(ModifierEngine::resolve($this->dept->id, 'reception', null, ['edging'], '01-02'))->toBeEmpty();
    });

    test('неизвестный SKU партии не блокирует правило', function () {
        // У операций цеха партии нет вовсе, а торцовка там доступна
        makeRule($this->dept, ['key' => 'edging', 'available_when_batch_sku' => '04-*']);

        expect(ModifierEngine::resolve($this->dept->id, 'workshop', null, ['edging'], null))->toHaveCount(1);
    });

    test('applies_to ограничивает область действия', function () {
        makeRule($this->dept, [
            'key'        => 'only_reception',
            'applies_to' => DepartmentModifier::SCOPE_RECEPTION,
        ]);

        expect(ModifierEngine::resolve($this->dept->id, 'reception', null, ['only_reception']))->toHaveCount(1)
            ->and(ModifierEngine::resolve($this->dept->id, 'workshop', null, ['only_reception']))->toBeEmpty();
    });

    test('выключенное правило не срабатывает', function () {
        makeRule($this->dept, ['key' => 'undercut', 'is_active' => false]);

        expect(ModifierEngine::resolve($this->dept->id, 'reception', null, ['undercut']))->toBeEmpty();
    });

    test('без отдела правил нет', function () {
        makeRule($this->dept, ['key' => 'undercut']);

        expect(ModifierEngine::resolve(null, 'reception', null, ['undercut']))->toBeEmpty();
    });

    test('правила чужого отдела не применяются', function () {
        $other = Department::create(['name' => 'Цех', 'is_active' => true]);
        makeRule($this->dept, ['key' => 'undercut']);

        expect(ModifierEngine::resolve($other->id, 'reception', null, ['undercut']))->toBeEmpty();
    });
});

describe('ModifierEngine — кэш', function () {

    test('новое правило подхватывается после сброса кэша отдела', function () {
        expect(ModifierEngine::resolve($this->dept->id, 'reception', null, ['undercut']))->toBeEmpty();

        makeRule($this->dept, ['key' => 'undercut', 'worker_coeff_delta' => -1.5]);
        $this->dept->forgetSettingsCache();

        expect(ModifierEngine::resolve($this->dept->id, 'reception', null, ['undercut']))->toHaveCount(1);
    });
});
