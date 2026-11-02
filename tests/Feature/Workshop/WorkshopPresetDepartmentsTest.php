<?php

use App\Models\Department;
use App\Models\WorkshopItem;
use App\Models\WorkshopPreset;
use App\Services\InternalOrderService;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\ReceptionTestHelper as H;

// ══════════════════════════════════════════════════════════════════════════════
// Один пресет цеха — в нескольких отделах (pivot department_workshop_preset)
// ══════════════════════════════════════════════════════════════════════════════

/** Валидная форма пресета (товары — из beforeEach). */
function sharedPresetPayload($test, array $overrides = []): array
{
    return array_merge([
        'name'          => 'Плитка 300',
        'raw_materials' => [['product_id' => $test->raw->id, 'quantity' => 1]],
        'products'      => [['product_id' => $test->product->id, 'quantity' => 1]],
    ], $overrides);
}

/** Пресет основного отдела $owner, подключённый к $shared. */
function sharedPreset(Department $owner, array $shared = [], string $name = 'Общий'): WorkshopPreset
{
    $preset = WorkshopPreset::create(['department_id' => $owner->id, 'name' => $name]);
    $preset->departments()->syncWithoutDetaching(collect($shared)->pluck('id'));

    return $preset->fresh();
}

beforeEach(function () {
    Cache::flush();
    $this->admin = H::adminUser();
    $this->a     = Department::create(['name' => 'Галтовка', 'is_active' => true]);
    $this->b     = Department::create(['name' => 'Цех', 'is_active' => true]);

    $this->raw     = H::product(['name' => 'Сырьё', 'sku' => '01-01-001']);
    $this->product = H::product(['name' => 'Плитка', 'sku' => '01-01-002']);
});

test('основной отдел всегда среди отделов пресета', function () {
    $preset = WorkshopPreset::create(['department_id' => $this->a->id, 'name' => 'П']);

    expect($preset->departments->pluck('id')->all())->toBe([$this->a->id]);
});

test('пресет создаётся сразу в нескольких отделах и виден в API формы цеха каждого', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.departments.presets.store', $this->a), sharedPresetPayload($this, ['department_ids' => [$this->b->id]]))
        ->assertSessionHasNoErrors();

    $preset = WorkshopPreset::sole();
    expect($preset->department_id)->toBe($this->a->id)
        ->and($preset->departments->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->a->id, $this->b->id])->sort()->values()->all());

    foreach ([$this->a, $this->b] as $dept) {
        $this->actingAs($this->admin)
            ->getJson(route('api.department.workshop-presets', $dept))
            ->assertJsonPath('presets.0.id', $preset->id);
    }
});

test('в каждом отделе свои группы; правка из одного отдела не трогает группы другого', function () {
    $groupA = $this->a->presetGroups()->create(['name' => 'Термо']);
    $groupB = $this->b->presetGroups()->create(['name' => 'Плитка']);
    $preset = sharedPreset($this->a, [$this->b]);
    $preset->groups()->sync([$groupA->id, $groupB->id]);

    $this->actingAs($this->admin)
        ->patch(route('admin.departments.presets.update', [$this->a, $preset]), sharedPresetPayload($this, [
            'name'           => 'Общий',
            'department_ids' => [$this->b->id],
        ]))
        ->assertSessionHasNoErrors();

    expect($preset->fresh()->groups->pluck('id')->all())->toBe([$groupB->id]);

    // API каждого отдела отдаёт только его группы.
    $this->actingAs($this->admin)
        ->getJson(route('api.department.workshop-presets', $this->b))
        ->assertJsonPath('presets.0.group_ids', [$groupB->id]);
});

test('снятый в форме отдел теряет пресет и свои группы, основной снять нельзя', function () {
    $groupB = $this->b->presetGroups()->create(['name' => 'Плитка']);
    $preset = sharedPreset($this->a, [$this->b]);
    $preset->groups()->sync([$groupB->id]);

    // Правят из карточки отдела B, отделы в форме не отмечены: B остаётся (из него правят), A — основной.
    $this->actingAs($this->admin)
        ->patch(route('admin.departments.presets.update', [$this->b, $preset]), sharedPresetPayload($this, ['name' => 'Общий']))
        ->assertSessionHasNoErrors();
    expect($preset->fresh()->departments)->toHaveCount(2);

    // Из карточки A без отметки B — B снимается вместе со своей группой.
    $this->actingAs($this->admin)
        ->patch(route('admin.departments.presets.update', [$this->a, $preset]), sharedPresetPayload($this, ['name' => 'Общий']))
        ->assertSessionHasNoErrors();
    expect($preset->fresh()->departments->pluck('id')->all())->toBe([$this->a->id])
        ->and($preset->fresh()->groups)->toBeEmpty();
});

test('«убрать из отдела» отвязывает отдел и его группы, у основного пресет остаётся', function () {
    $groupB = $this->b->presetGroups()->create(['name' => 'Плитка']);
    $preset = sharedPreset($this->a, [$this->b]);
    $preset->groups()->sync([$groupB->id]);

    $this->actingAs($this->admin)
        ->post(route('admin.departments.presets.detach', [$this->b, $preset]))
        ->assertSessionHas('success');

    expect($preset->fresh())->not->toBeNull()
        ->and($preset->fresh()->departments->pluck('id')->all())->toBe([$this->a->id])
        ->and($preset->fresh()->groups)->toBeEmpty();
});

test('из основного отдела пресет не убрать, только удалить', function () {
    $preset = sharedPreset($this->a, [$this->b]);

    $this->actingAs($this->admin)
        ->post(route('admin.departments.presets.detach', [$this->a, $preset]))
        ->assertSessionHas('error');

    expect($preset->fresh()->departments)->toHaveCount(2);
});

test('удаление в основном отделе убирает пресет отовсюду, из подключённого — запрещено', function () {
    $preset = sharedPreset($this->a, [$this->b]);

    $this->actingAs($this->admin)
        ->delete(route('admin.departments.presets.destroy', [$this->b, $preset]))
        ->assertSessionHas('error');
    expect($preset->fresh())->not->toBeNull();

    $this->actingAs($this->admin)
        ->delete(route('admin.departments.presets.destroy', [$this->a, $preset]))
        ->assertSessionHas('success');
    expect(WorkshopPreset::count())->toBe(0);
});

test('чужой, не подключённый пресет по адресу отдела не открыть', function () {
    $preset = sharedPreset($this->a);

    $this->actingAs($this->admin)
        ->get(route('admin.departments.presets.edit', [$this->b, $preset]))
        ->assertNotFound();
});

test('удаление основного отдела передаёт подключённый пресет другому, неподключённый удаляет', function () {
    $shared = sharedPreset($this->a, [$this->b], 'Общий');
    $own    = sharedPreset($this->a, [], 'Свой');

    $this->actingAs($this->admin)
        ->delete(route('admin.departments.destroy', $this->a))
        ->assertRedirect(route('admin.departments.index'));

    expect($shared->fresh()->department_id)->toBe($this->b->id)
        ->and($shared->fresh()->departments->pluck('id')->all())->toBe([$this->b->id])
        ->and($own->fresh())->toBeNull();
});

test('имя не может совпасть с пресетом любого из выбранных отделов', function () {
    sharedPreset($this->b, [], 'Плитка 300');

    // В B уже есть «Плитка 300» — с отметкой B не пройдёт.
    $this->actingAs($this->admin)
        ->post(route('admin.departments.presets.store', $this->a), sharedPresetPayload($this, ['department_ids' => [$this->b->id]]))
        ->assertSessionHasErrors('name');

    // Только в A — можно.
    $this->actingAs($this->admin)
        ->post(route('admin.departments.presets.store', $this->a), sharedPresetPayload($this))
        ->assertSessionHasNoErrors();
});

test('карточка подключённого отдела помечает пресет отделом-источником', function () {
    sharedPreset($this->a, [$this->b], 'Общий');

    $this->actingAs($this->admin)
        ->get(route('admin.departments.show', $this->b))
        ->assertOk()
        ->assertSee('из отдела «Галтовка»')
        ->assertSee(route('admin.departments.presets.detach', [$this->b, WorkshopPreset::sole()]), false);

    $this->actingAs($this->admin)
        ->get(route('admin.departments.show', $this->a))
        ->assertOk()
        ->assertSee('также в:')
        ->assertDontSee('из отдела «Галтовка»');
});

test('подсказки внутреннего заказа видят пресет, подключённый к отделу', function () {
    $final = H::product(['name' => 'Готовая', 'sku' => '01-01-003']);
    $tile  = H::product(['name' => 'Плитка', 'sku' => '01-01-004']);

    $preset = sharedPreset($this->a, [$this->b], 'Галтовка 30');
    $preset->items()->create(['product_id' => $final->id, 'role' => WorkshopItem::ROLE_PRODUCT, 'quantity' => 1]);
    $preset->items()->create(['product_id' => $tile->id, 'role' => WorkshopItem::ROLE_RAW, 'quantity' => 2]);

    $suggestions = app(InternalOrderService::class)->presetSuggestions([$final->id => 5.0], [$this->b->id]);

    expect($suggestions[$final->id][0])
        ->toMatchArray(['preset' => 'Галтовка 30', 'department_id' => $this->b->id])
        ->and($suggestions[$final->id][0]['items'][0]['quantity'])->toBe(10.0);
});
