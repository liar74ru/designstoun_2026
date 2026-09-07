<?php

use App\Models\Department;
use App\Models\DepartmentModifier;
use App\Services\DepartmentModifierService;
use App\Support\ModifierEngine;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    $this->service = app(DepartmentModifierService::class);
    $this->dept    = Department::create(['name' => 'Резка', 'is_active' => true]);
});

describe('applyDefaults()', function () {

    test('новый отдел стартует без правил', function () {
        expect($this->dept->modifiers()->count())->toBe(0);
    });

    test('заполняет отдел стандартным набором из конфига', function () {
        $added = $this->service->applyDefaults($this->dept);

        expect($added)->toBe(4)
            ->and($this->dept->modifiers()->pluck('key')->all())
            ->toBe(['mask_tile', 'edging', 'undercut', 'small_tile']);
    });

    test('повторный вызов не создаёт дублей', function () {
        $this->service->applyDefaults($this->dept);
        $added = $this->service->applyDefaults($this->dept);

        expect($added)->toBe(0)
            ->and($this->dept->modifiers()->count())->toBe(4);
    });

    test('уже настроенное правило не перезаписывается', function () {
        $this->dept->modifiers()->create([
            'key'                => 'undercut',
            'name'               => 'Свой подкол',
            'trigger'            => DepartmentModifier::TRIGGER_MANUAL,
            'applies_to'         => DepartmentModifier::SCOPE_BOTH,
            'worker_coeff_delta' => -9.0,
        ]);

        $this->service->applyDefaults($this->dept);

        $undercut = $this->dept->modifiers()->where('key', 'undercut')->first();

        expect((float) $undercut->worker_coeff_delta)->toBe(-9.0)
            ->and($undercut->name)->toBe('Свой подкол');
    });

    test('сбрасывает кэш — правила видны сразу', function () {
        expect(ModifierEngine::rulesFor($this->dept->id, 'reception'))->toBeEmpty();

        $this->service->applyDefaults($this->dept);

        expect(ModifierEngine::rulesFor($this->dept->id, 'reception'))->toHaveCount(4);
    });
});

describe('copyToDepartment()', function () {

    test('копирует правила в другой отдел', function () {
        $this->service->applyDefaults($this->dept);
        $target = Department::create(['name' => 'Цех', 'is_active' => true]);

        $copied = $this->service->copyToDepartment($this->dept, $target);

        expect($copied)->toBe(4)
            ->and($target->modifiers()->pluck('key')->all())
            ->toBe(['mask_tile', 'edging', 'undercut', 'small_tile']);
    });

    test('значения копируются, а не берутся из конфига', function () {
        $this->service->applyDefaults($this->dept);
        $this->dept->modifiers()->where('key', 'undercut')->update(['worker_coeff_delta' => -7.5]);

        $target = Department::create(['name' => 'Цех', 'is_active' => true]);
        $this->service->copyToDepartment($this->dept->fresh(), $target);

        expect((float) $target->modifiers()->where('key', 'undercut')->first()->worker_coeff_delta)
            ->toBe(-7.5);
    });

    test('существующие правила целевого отдела сохраняются', function () {
        $this->service->applyDefaults($this->dept);
        $target = Department::create(['name' => 'Цех', 'is_active' => true]);
        $target->modifiers()->create([
            'key'                => 'undercut',
            'name'               => 'Свой',
            'trigger'            => DepartmentModifier::TRIGGER_MANUAL,
            'applies_to'         => DepartmentModifier::SCOPE_BOTH,
            'worker_coeff_delta' => -9.0,
        ]);

        $copied = $this->service->copyToDepartment($this->dept, $target);

        expect($copied)->toBe(3)
            ->and($target->modifiers()->where('key', 'undercut')->first()->name)->toBe('Свой');
    });
});

describe('delete()', function () {

    test('удаляет правило и сбрасывает кэш', function () {
        $this->service->applyDefaults($this->dept);
        expect(ModifierEngine::rulesFor($this->dept->id, 'reception'))->toHaveCount(4);

        $this->service->delete($this->dept->modifiers()->where('key', 'undercut')->first());

        expect(ModifierEngine::rulesFor($this->dept->id, 'reception'))->toHaveCount(3);
    });
});
