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

/** Пресет отдела, привязанный к группам. */
function presetInGroups(Department $dept, string $name, array $groups = []): WorkshopPreset
{
    $preset = WorkshopPreset::create(['department_id' => $dept->id, 'name' => $name]);
    $preset->groups()->sync(collect($groups)->pluck('id'));

    return $preset;
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

    test('удаление группы снимает её с пресетов, но сами пресеты остаются', function () {
        $group  = $this->dept->presetGroups()->create(['name' => 'Термо']);
        $keep   = $this->dept->presetGroups()->create(['name' => 'Мелкая']);
        $preset = presetInGroups($this->dept, 'П', [$group, $keep]);

        $this->actingAs($this->admin)
            ->delete(route('admin.departments.preset-groups.destroy', [$this->dept, $group]));

        expect($preset->fresh())->not->toBeNull()
            ->and($preset->fresh()->groups->pluck('id')->all())->toBe([$keep->id]);
    });
});

describe('Пресет в группах', function () {

    test('пресет сохраняется сразу в нескольких группах своего отдела', function () {
        $termo  = $this->dept->presetGroups()->create(['name' => 'Термо']);
        $small  = $this->dept->presetGroups()->create(['name' => 'Мелкая']);

        $this->actingAs($this->admin)
            ->post(route('admin.departments.presets.store', $this->dept), presetPayload(['group_ids' => [$termo->id, $small->id]]))
            ->assertSessionHasNoErrors();

        expect($this->dept->presets()->first()->groups->pluck('id')->sort()->values()->all())
            ->toBe(collect([$termo->id, $small->id])->sort()->values()->all());
    });

    test('правка без отмеченных групп убирает пресет из всех групп', function () {
        $group  = $this->dept->presetGroups()->create(['name' => 'Термо']);
        $this->actingAs($this->admin)
            ->post(route('admin.departments.presets.store', $this->dept), presetPayload(['group_ids' => [$group->id]]));
        $preset = $this->dept->presets()->first();

        $this->actingAs($this->admin)
            ->patch(route('admin.departments.presets.update', [$this->dept, $preset]), presetPayload())
            ->assertSessionHasNoErrors();

        expect($preset->fresh()->groups)->toBeEmpty();
    });

    test('группу чужого отдела пресету не назначить', function () {
        $other = Department::create(['name' => 'Другой', 'is_active' => true]);
        $group = $other->presetGroups()->create(['name' => 'Чужая']);

        $this->actingAs($this->admin)
            ->post(route('admin.departments.presets.store', $this->dept), presetPayload(['group_ids' => [$group->id]]))
            ->assertSessionHasErrors('group_ids.0');
    });

    test('копия в другой отдел попадает в одноимённые группы, остальные пропускаются', function () {
        $preset = presetInGroups($this->dept, 'П', [
            $this->dept->presetGroups()->create(['name' => 'Термо']),
            $this->dept->presetGroups()->create(['name' => 'Мелкая']),
        ]);

        $withGroup    = Department::create(['name' => 'С группой', 'is_active' => true]);
        $targetGroup  = $withGroup->presetGroups()->create(['name' => 'Термо']);
        $withoutGroup = Department::create(['name' => 'Без групп', 'is_active' => true]);

        foreach ([$withGroup, $withoutGroup] as $target) {
            $this->actingAs($this->admin)
                ->post(route('admin.departments.presets.copy', [$this->dept, $preset]), ['target_department_id' => $target->id]);
        }

        expect($withGroup->presets()->first()->groups->pluck('id')->all())->toBe([$targetGroup->id])
            ->and($withoutGroup->presets()->first()->groups)->toBeEmpty();
    });
});

test('API формы цеха отдаёт группы и group_ids пресетов', function () {
    $termo   = $this->dept->presetGroups()->create(['name' => 'Термо', 'icon' => 'bi-fire']);
    $small   = $this->dept->presetGroups()->create(['name' => 'Мелкая']);
    $product = H::product(['name' => 'Плитка', 'sku' => '01-01-002']);

    $inGroups = presetInGroups($this->dept, 'А', [$termo, $small]);
    $inGroups->items()->create(['product_id' => $product->id, 'role' => WorkshopItem::ROLE_PRODUCT, 'quantity' => 2]);
    presetInGroups($this->dept, 'Б');

    $response = $this->actingAs($this->admin)
        ->getJson(route('api.department.workshop-presets', $this->dept))
        ->assertOk()
        ->assertJsonPath('groups.0.name', 'Мелкая')
        ->assertJsonPath('groups.1.icon', 'bi-fire')
        ->assertJsonPath('presets.0.name', 'А')
        ->assertJsonPath('presets.0.items.0.quantity', 2)
        ->assertJsonPath('presets.1.group_ids', []);

    expect($response->json('presets.0.group_ids'))->toEqualCanonicalizing([$termo->id, $small->id]);
});

test('карточка отдела: пресет из двух групп считается и помечен в каждой', function () {
    $termo = $this->dept->presetGroups()->create(['name' => 'Термо', 'icon' => 'bi-fire']);
    $small = $this->dept->presetGroups()->create(['name' => 'Мелкая']);
    presetInGroups($this->dept, 'Плитка термо', [$termo, $small]);
    presetInGroups($this->dept, 'Бордюр');

    $this->actingAs($this->admin)
        ->get(route('admin.departments.show', $this->dept))
        ->assertOk()
        // Список групп: «Все», группы по имени, «Без группы» — последней; у каждой группы по 1 пресету.
        ->assertSeeInOrder([
            'Пресеты цеха (2)',
            'data-filter-key="all"',
            'data-filter-key="' . $small->id . '"', 'Мелкая', '<span class="pl-count">1</span>',
            'data-filter-key="' . $termo->id . '"', 'Термо', '<span class="pl-count">1</span>',
            'data-filter-key="none"', 'Без группы',
        ], false)
        // Карточки — по имени, ключи групп через пробел (порядок групп — по имени).
        ->assertSeeInOrder([
            'data-group-keys="none"', 'Бордюр',
            'data-group-keys="' . $small->id . ' ' . $termo->id . '"', 'Плитка термо',
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

test('форма правки отмечает все группы пресета', function () {
    $termo  = $this->dept->presetGroups()->create(['name' => 'Термо']);
    $small  = $this->dept->presetGroups()->create(['name' => 'Мелкая']);
    $free   = $this->dept->presetGroups()->create(['name' => 'Прочее']);
    $preset = presetInGroups($this->dept, 'П', [$termo, $small]);

    $html = $this->actingAs($this->admin)
        ->get(route('admin.departments.presets.edit', [$this->dept, $preset]))
        ->assertOk()
        ->assertSee('name="group_ids[]"', false)
        ->getContent();

    $checked = fn($group) => (bool) preg_match(
        '/value="' . $group->id . '"\s+id="presetGroup' . $group->id . '"[^>]*checked/', $html
    );

    expect($checked($termo))->toBeTrue()
        ->and($checked($small))->toBeTrue()
        ->and($checked($free))->toBeFalse();
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
