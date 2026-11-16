<?php

use App\Models\Department;
use App\Models\WorkshopPreset;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\ReceptionTestHelper as H;

// ══════════════════════════════════════════════════════════════════════════════
// Расходы на единицу в шаблоне цеха: cost_per_unit + cost_replaces_total.
// Пусто — накладные из карточки отдела; заполнено — авторасчёт затрат формы цеха
// берёт число из шаблона вместо накладных или вместо всей суммы.
// ══════════════════════════════════════════════════════════════════════════════

/** Минимально валидная форма шаблона с расходами. */
function costPresetPayload(array $overrides = []): array
{
    return array_merge([
        'name'          => 'Панель 3D',
        'raw_materials' => [['product_id' => H::product(['sku' => '04-07-40'])->id, 'quantity' => 1]],
        'products'      => [['product_id' => H::product(['sku' => '04-07-52'])->id, 'quantity' => 1]],
    ], $overrides);
}

beforeEach(function () {
    Cache::flush();
    $this->dept  = Department::create(['name' => 'Цех', 'is_active' => true]);
    $this->admin = H::adminUser();
});

test('шаблон сохраняет расходы и переключатель', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.departments.presets.store', $this->dept),
            costPresetPayload(['cost_per_unit' => '35.50', 'cost_replaces_total' => '1']))
        ->assertSessionHasNoErrors();

    $preset = $this->dept->presets()->first();
    expect((float) $preset->cost_per_unit)->toBe(35.5)
        ->and($preset->cost_replaces_total)->toBeTrue();
});

test('правка меняет расходы, пустое поле — накладные отдела', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.departments.presets.store', $this->dept),
            costPresetPayload(['cost_per_unit' => '20', 'cost_replaces_total' => '0']));
    $preset = $this->dept->presets()->first();

    expect((float) $preset->cost_per_unit)->toBe(20.0)
        ->and($preset->cost_replaces_total)->toBeFalse();

    // «Вся сумма» без числа ничего не значит — сбрасывается вместе с полем
    $this->actingAs($this->admin)
        ->patch(route('admin.departments.presets.update', [$this->dept, $preset]),
            costPresetPayload(['cost_per_unit' => '', 'cost_replaces_total' => '1']))
        ->assertSessionHasNoErrors();

    $preset->refresh();
    expect($preset->cost_per_unit)->toBeNull()
        ->and($preset->cost_replaces_total)->toBeFalse();
});

test('отрицательные расходы — ошибка', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.departments.presets.store', $this->dept), costPresetPayload(['cost_per_unit' => '-1']))
        ->assertSessionHasErrors('cost_per_unit');

    expect($this->dept->presets()->count())->toBe(0);
});

test('API формы цеха отдаёт расходы шаблона', function () {
    WorkshopPreset::create(['department_id' => $this->dept->id, 'name' => 'С расходами', 'cost_per_unit' => 35, 'cost_replaces_total' => true]);
    WorkshopPreset::create(['department_id' => $this->dept->id, 'name' => 'Без расходов']);

    $presets = collect($this->actingAs($this->admin)
        ->getJson(route('api.department.workshop-presets', $this->dept))
        ->assertOk()
        ->json('presets'))->keyBy('name');

    expect($presets['С расходами']['cost_per_unit'])->toEqual(35)
        ->and($presets['С расходами']['cost_replaces_total'])->toBeTrue()
        ->and($presets['Без расходов']['cost_per_unit'])->toBeNull()
        ->and($presets['Без расходов']['cost_replaces_total'])->toBeFalse();
});

test('копия в другой отдел переносит расходы', function () {
    $preset = WorkshopPreset::create(['department_id' => $this->dept->id, 'name' => 'С расходами', 'cost_per_unit' => 12.5, 'cost_replaces_total' => false]);
    $target = Department::create(['name' => 'Другой', 'is_active' => true]);

    $this->actingAs($this->admin)
        ->post(route('admin.departments.presets.copy', [$this->dept, $preset]), ['target_department_id' => $target->id]);

    $copy = $target->ownedPresets()->firstOrFail();
    expect((float) $copy->cost_per_unit)->toBe(12.5)
        ->and($copy->cost_replaces_total)->toBeFalse();
});

test('карточка шаблона в отделе показывает расходы', function () {
    WorkshopPreset::create(['department_id' => $this->dept->id, 'name' => 'С расходами', 'cost_per_unit' => 35, 'cost_replaces_total' => true]);

    $this->actingAs($this->admin)
        ->get(route('admin.departments.show', $this->dept))
        ->assertOk()
        ->assertSee('расходы 35,00 ₽/ед')
        ->assertSee('(вся сумма)');
});
