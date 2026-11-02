<?php

use App\Models\Department;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkshopItem;
use App\Models\WorkshopPreset;
use App\Models\WorkshopPresetGroup;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\ReceptionTestHelper as H;

// ══════════════════════════════════════════════════════════════════════════════
// Группы пресетов цеха: /admin/departments/{department}/preset-groups
// ══════════════════════════════════════════════════════════════════════════════

/** Минимально валидный набор полей формы пресета. */
function presetPayload(array $overrides = []): array
{
    $raw     = H::product(['name' => 'Сырьё', 'sku' => '01-01-001']);
    $product = H::product(['name' => 'Плитка', 'sku' => '01-01-002']);

    return array_merge([
        'name'          => 'Плитка 300',
        'raw_materials' => [['product_id' => $raw->id, 'quantity' => 1]],
        'products'      => [['product_id' => $product->id, 'quantity' => 1]],
    ], $overrides);
}

beforeEach(function () {
    Cache::flush();
    $this->dept  = Department::create(['name' => 'Цех', 'is_active' => true]);
    $this->admin = H::adminUser();
});

describe('CRUD групп', function () {

    test('администратор создаёт, правит и удаляет группу', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.departments.preset-groups.store', $this->dept), ['name' => 'Термо', 'icon' => 'bi-fire'])
            ->assertRedirect(route('admin.departments.show', $this->dept) . '#workshop');

        $group = $this->dept->presetGroups()->first();
        expect($group->name)->toBe('Термо')->and($group->icon)->toBe('bi-fire');

        $this->actingAs($this->admin)
            ->patch(route('admin.departments.preset-groups.update', [$this->dept, $group]), ['name' => 'Термообработка', 'icon' => ''])
            ->assertRedirect();
        expect($group->fresh()->name)->toBe('Термообработка')->and($group->fresh()->icon)->toBeNull();

        $this->actingAs($this->admin)
            ->delete(route('admin.departments.preset-groups.destroy', [$this->dept, $group]))
            ->assertRedirect();
        expect(WorkshopPresetGroup::count())->toBe(0);
    });

    test('название уникально в отделе, но может повторяться в другом', function () {
        $other = Department::create(['name' => 'Другой', 'is_active' => true]);
        $other->presetGroups()->create(['name' => 'Термо']);

        $this->actingAs($this->admin)
            ->post(route('admin.departments.preset-groups.store', $this->dept), ['name' => 'Термо'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('admin.departments.preset-groups.store', $this->dept), ['name' => 'Термо'])
            ->assertSessionHasErrors('name');
    });

    test('иконка — только из набора', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.departments.preset-groups.store', $this->dept), ['name' => 'Х', 'icon' => 'bi-evil'])
            ->assertSessionHasErrors('icon');
    });

    test('группу чужого отдела через адрес отдела не открыть', function () {
        $other = Department::create(['name' => 'Другой', 'is_active' => true]);
        $group = $other->presetGroups()->create(['name' => 'Чужая']);

        $this->actingAs($this->admin)
            ->get(route('admin.departments.preset-groups.edit', [$this->dept, $group]))
            ->assertNotFound();
    });

    test('не-админ получает 403', function () {
        $worker = Worker::create(['name' => 'Мастер', 'position' => 'Мастер', 'department_id' => $this->dept->id]);
        $user   = User::factory()->create(['is_admin' => false, 'worker_id' => $worker->id]);

        $this->actingAs($user)
            ->post(route('admin.departments.preset-groups.store', $this->dept), ['name' => 'Термо'])
            ->assertForbidden();
    });

    test('удаление группы оставляет её пресеты без группы', function () {
        $group  = $this->dept->presetGroups()->create(['name' => 'Термо']);
        $preset = WorkshopPreset::create([
            'department_id' => $this->dept->id, 'workshop_preset_group_id' => $group->id, 'name' => 'П',
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.departments.preset-groups.destroy', [$this->dept, $group]));

        expect($preset->fresh())->not->toBeNull()
            ->and($preset->fresh()->workshop_preset_group_id)->toBeNull();
    });
});

describe('Пресет в группе', function () {

    test('пресет сохраняется с группой своего отдела', function () {
        $group = $this->dept->presetGroups()->create(['name' => 'Термо']);

        $this->actingAs($this->admin)
            ->post(route('admin.departments.presets.store', $this->dept), presetPayload(['workshop_preset_group_id' => $group->id]))
            ->assertSessionHasNoErrors();

        expect($this->dept->presets()->first()->workshop_preset_group_id)->toBe($group->id);
    });

    test('группу чужого отдела пресету не назначить', function () {
        $other = Department::create(['name' => 'Другой', 'is_active' => true]);
        $group = $other->presetGroups()->create(['name' => 'Чужая']);

        $this->actingAs($this->admin)
            ->post(route('admin.departments.presets.store', $this->dept), presetPayload(['workshop_preset_group_id' => $group->id]))
            ->assertSessionHasErrors('workshop_preset_group_id');
    });

    test('копия в другой отдел попадает в одноимённую группу, иначе — без группы', function () {
        $group  = $this->dept->presetGroups()->create(['name' => 'Термо']);
        $preset = WorkshopPreset::create([
            'department_id' => $this->dept->id, 'workshop_preset_group_id' => $group->id, 'name' => 'П',
        ]);

        $withGroup    = Department::create(['name' => 'С группой', 'is_active' => true]);
        $targetGroup  = $withGroup->presetGroups()->create(['name' => 'Термо']);
        $withoutGroup = Department::create(['name' => 'Без групп', 'is_active' => true]);

        foreach ([$withGroup, $withoutGroup] as $target) {
            $this->actingAs($this->admin)
                ->post(route('admin.departments.presets.copy', [$this->dept, $preset]), ['target_department_id' => $target->id]);
        }

        expect($withGroup->presets()->first()->workshop_preset_group_id)->toBe($targetGroup->id)
            ->and($withoutGroup->presets()->first()->workshop_preset_group_id)->toBeNull();
    });
});

test('API формы цеха отдаёт группы и group_id пресетов', function () {
    $group   = $this->dept->presetGroups()->create(['name' => 'Термо', 'icon' => 'bi-fire']);
    $product = H::product(['name' => 'Плитка', 'sku' => '01-01-002']);

    $inGroup = WorkshopPreset::create(['department_id' => $this->dept->id, 'workshop_preset_group_id' => $group->id, 'name' => 'А']);
    $inGroup->items()->create(['product_id' => $product->id, 'role' => WorkshopItem::ROLE_PRODUCT, 'quantity' => 2]);
    WorkshopPreset::create(['department_id' => $this->dept->id, 'name' => 'Б']);

    $this->actingAs($this->admin)
        ->getJson(route('api.department.workshop-presets', $this->dept))
        ->assertOk()
        ->assertJsonPath('groups.0.name', 'Термо')
        ->assertJsonPath('groups.0.icon', 'bi-fire')
        ->assertJsonPath('presets.0.name', 'А')
        ->assertJsonPath('presets.0.group_id', $group->id)
        ->assertJsonPath('presets.0.items.0.quantity', 2)
        ->assertJsonPath('presets.1.group_id', null);
});

test('карточка отдела: группы слева, карточки пресетов помечены своей группой', function () {
    $group = $this->dept->presetGroups()->create(['name' => 'Термо', 'icon' => 'bi-fire']);
    WorkshopPreset::create(['department_id' => $this->dept->id, 'workshop_preset_group_id' => $group->id, 'name' => 'Плитка термо']);
    WorkshopPreset::create(['department_id' => $this->dept->id, 'name' => 'Бордюр']);

    $this->actingAs($this->admin)
        ->get(route('admin.departments.show', $this->dept))
        ->assertOk()
        // Список групп: «Все», группы по имени, «Без группы» — последней.
        ->assertSeeInOrder([
            'Пресеты цеха (2)',
            'data-filter-key="all"',
            'data-filter-key="' . $group->id . '"', 'Термо',
            'data-filter-key="none"', 'Без группы',
        ], false)
        // Карточки — по имени, у каждой ключ группы для фильтра.
        ->assertSeeInOrder([
            'data-group-key="none"', 'Бордюр',
            'data-group-key="' . $group->id . '"', 'Плитка термо',
        ], false);
});

test('без групп список групп слева содержит только «Все пресеты»', function () {
    WorkshopPreset::create(['department_id' => $this->dept->id, 'name' => 'Бордюр']);

    $this->actingAs($this->admin)
        ->get(route('admin.departments.show', $this->dept))
        ->assertOk()
        ->assertSee('Все пресеты')
        ->assertDontSee('data-filter-key="none"', false);
});

test('форма пресета показывает выбор группы', function () {

    $this->actingAs($this->admin)
        ->get(route('admin.departments.presets.create', $this->dept))
        ->assertOk()
        ->assertSee('— без группы —');
});

test('«+ Пресет» из группы подставляет её в форму, чужую группу — нет', function () {
    $group = $this->dept->presetGroups()->create(['name' => 'Термо']);
    $other = Department::create(['name' => 'Другой', 'is_active' => true])
        ->presetGroups()->create(['name' => 'Чужая']);

    $this->actingAs($this->admin)
        ->get(route('admin.departments.presets.create', [$this->dept, 'group' => $group->id]))
        ->assertOk()
        ->assertViewHas('defaultGroupId', $group->id);

    $this->actingAs($this->admin)
        ->get(route('admin.departments.presets.create', [$this->dept, 'group' => $other->id]))
        ->assertOk()
        ->assertViewHas('defaultGroupId', null);
});
