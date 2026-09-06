<?php

use App\Models\Department;
use App\Models\DepartmentModifier;
use App\Models\User;
use App\Models\Worker;
use App\Support\ModifierEngine;
use Illuminate\Support\Facades\Cache;

// ══════════════════════════════════════════════════════════════════════════════
// CRUD правил себестоимости: /admin/departments/{department}/modifiers
// ══════════════════════════════════════════════════════════════════════════════

function modifierAdmin(): User
{
    return User::factory()->create(['is_admin' => true, 'worker_id' => null]);
}

/** Минимально валидный набор полей формы. */
function modifierPayload(array $overrides = []): array
{
    return array_merge([
        'key'                => 'bonus',
        'name'               => 'Бонус',
        'color'              => '#FFC107',
        'trigger'            => DepartmentModifier::TRIGGER_MANUAL,
        'applies_to'         => DepartmentModifier::SCOPE_BOTH,
        'worker_coeff_delta' => '1.5',
        'sort_order'         => '50',
        'is_active'          => '1',
    ], $overrides);
}

function storeModifier($test, Department $dept, array $overrides = [])
{
    return $test->actingAs(modifierAdmin())
        ->post(route('admin.departments.modifiers.store', $dept), modifierPayload($overrides));
}

beforeEach(function () {
    Cache::flush();
    $this->dept = Department::create(['name' => 'Отдел правил', 'is_active' => true]);
});

describe('Создание', function () {

    test('администратор заводит правило', function () {
        storeModifier($this, $this->dept)
            ->assertRedirect(route('admin.departments.show', $this->dept))
            ->assertSessionHas('success');

        $rule = $this->dept->modifiers()->first();

        expect($rule->key)->toBe('bonus')
            ->and($rule->name)->toBe('Бонус')
            ->and($rule->color)->toBe('#FFC107')
            ->and((float) $rule->worker_coeff_delta)->toBe(1.5)
            ->and($rule->worker_coeff_replace)->toBeNull()
            ->and($rule->is_active)->toBeTrue();
    });

    test('пустые числовые поля сохраняются как null, а не как ноль', function () {
        storeModifier($this, $this->dept, [
            'worker_coeff_delta' => '',
            'master_coeff_delta' => '',
        ]);

        $rule = $this->dept->modifiers()->first();

        expect($rule->worker_coeff_delta)->toBeNull()
            ->and($rule->master_coeff_delta)->toBeNull();
    });

    test('снятый чекбокс активности выключает правило', function () {
        $payload = modifierPayload();
        unset($payload['is_active']);

        $this->actingAs(modifierAdmin())
            ->post(route('admin.departments.modifiers.store', $this->dept), $payload);

        expect($this->dept->modifiers()->first()->is_active)->toBeFalse();
    });

    test('отрицательный коэффициент проходит — это штраф', function () {
        storeModifier($this, $this->dept, ['worker_coeff_delta' => '-1.5'])
            ->assertSessionHasNoErrors();

        expect((float) $this->dept->modifiers()->first()->worker_coeff_delta)->toBe(-1.5);
    });

    test('правило сразу применяется — кэш отдела сброшен', function () {
        expect(ModifierEngine::rulesFor($this->dept->id, 'reception'))->toBeEmpty();

        storeModifier($this, $this->dept, ['key' => 'undercut']);

        expect(ModifierEngine::rulesFor($this->dept->id, 'reception'))->toHaveCount(1);
    });
});

describe('Валидация', function () {

    test('дубль ключа в том же отделе отклоняется', function () {
        storeModifier($this, $this->dept);

        storeModifier($this, $this->dept, ['name' => 'Другое имя'])
            ->assertSessionHasErrors('key');

        expect($this->dept->modifiers()->count())->toBe(1);
    });

    test('тот же ключ в другом отделе — не конфликт', function () {
        storeModifier($this, $this->dept);
        $other = Department::create(['name' => 'Второй', 'is_active' => true]);

        storeModifier($this, $other)->assertSessionHasNoErrors();

        expect($other->modifiers()->count())->toBe(1);
    });

    test('ключ с недопустимыми символами отклоняется', function () {
        storeModifier($this, $this->dept, ['key' => 'Подкол 80%'])
            ->assertSessionHasErrors('key');
    });

    test('прибавка и замена одновременно отклоняются', function () {
        storeModifier($this, $this->dept, [
            'worker_coeff_delta'   => '2',
            'worker_coeff_replace' => '-2.5',
        ])->assertSessionHasErrors('worker_coeff_replace');
    });

    test('sku-правило без маски отклоняется', function () {
        storeModifier($this, $this->dept, [
            'trigger'     => DepartmentModifier::TRIGGER_SKU,
            'sku_pattern' => '',
        ])->assertSessionHasErrors('sku_pattern');
    });

    test('цвет вне палитры отклоняется', function () {
        storeModifier($this, $this->dept, ['color' => '#123456'])
            ->assertSessionHasErrors('color');
    });

    test('порядок вне диапазона колонки отклоняется', function () {
        storeModifier($this, $this->dept, ['sort_order' => '70000'])
            ->assertSessionHasErrors('sort_order');
    });
});

describe('Правка и удаление', function () {

    beforeEach(function () {
        storeModifier($this, $this->dept, ['key' => 'undercut', 'worker_coeff_delta' => '-1.5']);
        $this->rule = $this->dept->modifiers()->first();
    });

    test('правка меняет значения и применяется сразу', function () {
        $this->actingAs(modifierAdmin())
            ->patch(
                route('admin.departments.modifiers.update', [$this->dept, $this->rule]),
                modifierPayload(['key' => 'undercut', 'worker_coeff_delta' => '-3']),
            )
            ->assertRedirect(route('admin.departments.show', $this->dept))
            ->assertSessionHas('success');

        expect((float) $this->rule->fresh()->worker_coeff_delta)->toBe(-3.0)
            ->and((float) ModifierEngine::rulesFor($this->dept->id, 'reception')->first()->worker_coeff_delta)
            ->toBe(-3.0);
    });

    test('свой ключ при правке не считается дублем', function () {
        $this->actingAs(modifierAdmin())
            ->patch(
                route('admin.departments.modifiers.update', [$this->dept, $this->rule]),
                modifierPayload(['key' => 'undercut', 'name' => 'Подкол > 80%']),
            )
            ->assertSessionHasNoErrors();

        expect($this->rule->fresh()->name)->toBe('Подкол > 80%');
    });

    test('удаление убирает правило и сбрасывает кэш', function () {
        expect(ModifierEngine::rulesFor($this->dept->id, 'reception'))->toHaveCount(1);

        $this->actingAs(modifierAdmin())
            ->delete(route('admin.departments.modifiers.destroy', [$this->dept, $this->rule]))
            ->assertRedirect(route('admin.departments.show', $this->dept));

        expect($this->dept->modifiers()->count())->toBe(0)
            ->and(ModifierEngine::rulesFor($this->dept->id, 'reception'))->toBeEmpty();
    });

    test('правило чужого отдела — 404', function () {
        $other = Department::create(['name' => 'Чужой', 'is_active' => true]);

        $this->actingAs(modifierAdmin())
            ->get(route('admin.departments.modifiers.edit', [$other, $this->rule]))
            ->assertNotFound();
    });
});

describe('Доступ', function () {

    test('не-админ получает 403', function () {
        $worker = Worker::create(['name' => 'Мастер', 'position' => 'Мастер', 'department_id' => $this->dept->id]);
        $user   = User::factory()->create(['is_admin' => false, 'worker_id' => $worker->id]);

        $this->actingAs($user)
            ->get(route('admin.departments.modifiers.create', $this->dept))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.departments.modifiers.store', $this->dept), modifierPayload())
            ->assertForbidden();
    });
});

describe('Карточка отдела', function () {

    test('правила отдела показаны в списке', function () {
        storeModifier($this, $this->dept, ['key' => 'undercut', 'name' => 'Подкол > 80%']);

        $this->actingAs(modifierAdmin())
            ->get(route('admin.departments.show', $this->dept))
            ->assertOk()
            ->assertViewHas('modifiers')
            ->assertSee('Подкол > 80%');
    });

    test('в форме правки отмечен текущий цвет правила', function () {
        storeModifier($this, $this->dept, ['color' => '#FFC107']);
        $rule = $this->dept->modifiers()->first();

        $html = $this->actingAs(modifierAdmin())
            ->get(route('admin.departments.modifiers.edit', [$this->dept, $rule]))
            ->assertOk()
            ->getContent();

        // Выбор палитры не должен зависеть от JS — checked приходит с сервера.
        expect($html)->toMatch('/value="#FFC107"[^>]*checked/')
            ->and($html)->not->toMatch('/value=""[^>]*checked/');
    });

    test('отдел без правил показывает предупреждение', function () {
        $this->actingAs(modifierAdmin())
            ->get(route('admin.departments.show', $this->dept))
            ->assertOk()
            ->assertSee('Правила не заданы', false);
    });
});
