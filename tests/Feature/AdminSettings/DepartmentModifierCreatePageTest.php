<?php

use App\Models\DepartmentModifier;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

// ══════════════════════════════════════════════════════════════════════════════
// Форма нового правила: GET /admin/departments/{department}/modifiers/create
// ══════════════════════════════════════════════════════════════════════════════

beforeEach(function () {
    Cache::flush();
    $this->dept = Access::department('Отдел распиловки');
});

describe('DepartmentModifierController create()', function () {

    test('админ видит пустую форму правила для отдела', function () {
        $response = $this->actingAs(H::adminUser())
            ->get(route('admin.departments.modifiers.create', $this->dept))
            ->assertOk()
            ->assertViewIs('admin.departments.modifiers.create')
            ->assertSee('Новое правило себестоимости')
            ->assertSee('Новое правило — Отдел распиловки', false)
            // форма шлёт POST в store этого отдела, без подмены метода
            ->assertSee('action="' . route('admin.departments.modifiers.store', $this->dept) . '"', false)
            ->assertDontSee('name="_method"', false)
            ->assertSee('data-submit-guard', false)
            // кнопка «назад» ведёт в карточку отдела
            ->assertSee(route('admin.departments.show', $this->dept), false)
            ->assertSee('name="key"', false)
            ->assertSee('name="worker_coeff_delta"', false)
            ->assertSee('name="master_coeff_delta"', false);

        expect($response->viewData('department')->id)->toBe($this->dept->id)
            ->and($response->getContent())
            ->toMatch('/id="modName" name="name"\s+value=""/')
            ->toMatch('/id="modKey" name="key"\s+value=""/');
    });

    test('палитра цветов и набор иконок берутся из констант модели', function () {
        $response = $this->actingAs(H::adminUser())
            ->get(route('admin.departments.modifiers.create', $this->dept))
            ->assertOk();

        foreach (array_keys(DepartmentModifier::COLORS) as $hex) {
            $response->assertSee('name="color" value="' . $hex . '"', false);
        }
        foreach (array_keys(DepartmentModifier::ICONS) as $icon) {
            $response->assertSee('name="icon" value="' . $icon . '"', false);
        }
    });

    test('по умолчанию: ручное правило, для приёмки и цеха, активно', function () {
        $html = $this->actingAs(H::adminUser())
            ->get(route('admin.departments.modifiers.create', $this->dept))
            ->assertOk()
            ->getContent();

        expect($html)
            ->toContain("x-data=\"{ trigger: '" . DepartmentModifier::TRIGGER_MANUAL . "' }\"")
            ->toMatch('/<option value="' . DepartmentModifier::SCOPE_BOTH . '"\s+selected>/')
            ->toMatch('/id="modIsActive" name="is_active" value="1"\s+checked>/')
            // «без цвета» и «без иконки» выбраны
            ->toMatch('/name="color" value=""[^>]*\s+checked>/')
            ->toMatch('/name="icon" value=""[^>]*\s+checked>/');
    });

    test('карта правил для превью приходит в JS', function () {
        $this->actingAs(H::adminUser())
            ->get(route('admin.departments.modifiers.create', $this->dept))
            ->assertOk()
            ->assertSee('window.ProductionRates', false);
    });

    test('несуществующий отдел — 404', function () {
        $this->actingAs(H::adminUser())
            ->get(route('admin.departments.modifiers.create', 999999))
            ->assertNotFound();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Возврат на форму после ошибки валидации
// ══════════════════════════════════════════════════════════════════════════════

describe('DepartmentModifierController create() — ошибки валидации', function () {

    test('ошибки и введённые значения показываются на форме, правило не создано', function () {
        $createUrl = route('admin.departments.modifiers.create', $this->dept);

        $this->actingAs(H::adminUser())
            ->from($createUrl)
            ->post(route('admin.departments.modifiers.store', $this->dept), [
                'key'                => 'Плохой Ключ!',
                'name'               => 'Торцовка особая',
                'color'              => '#0D6EFD',
                'icon'               => 'bi-scissors',
                'trigger'            => DepartmentModifier::TRIGGER_MANUAL,
                'applies_to'         => DepartmentModifier::SCOPE_WORKSHOP,
                'worker_coeff_delta' => '-2.5',
                'is_active'          => '1',
            ])
            ->assertRedirect($createUrl)
            ->assertSessionHasErrors('key');

        $html = $this->actingAs(H::adminUser())
            ->get($createUrl)
            ->assertOk()
            ->assertSee('is-invalid', false)
            ->assertSee('value="Торцовка особая"', false)
            ->assertSee('value="Плохой Ключ!"', false)
            ->getContent();

        expect($html)
            ->toMatch('/name="color" value="#0D6EFD"[^>]*\s+checked>/')
            ->toMatch('/name="icon" value="bi-scissors"[^>]*\s+checked>/')
            ->toMatch('/<option value="' . DepartmentModifier::SCOPE_WORKSHOP . '"\s+selected>/')
            ->toContain("workerDelta: '-2.5'");

        expect($this->dept->modifiers()->count())->toBe(0);
    });

    test('снятая галочка «Правило активно» остаётся снятой после ошибки валидации', function () {
        $createUrl = route('admin.departments.modifiers.create', $this->dept);

        // Форма шлёт скрытое is_active=0 перед чекбоксом; снятый чекбокс не отправляется
        $this->actingAs(H::adminUser())
            ->from($createUrl)
            ->post(route('admin.departments.modifiers.store', $this->dept), [
                'key'        => 'Плохой Ключ!',
                'name'       => 'Выключенное правило',
                'trigger'    => DepartmentModifier::TRIGGER_MANUAL,
                'applies_to' => DepartmentModifier::SCOPE_BOTH,
                'is_active'  => '0',
            ])
            ->assertSessionHasErrors('key');

        $html = $this->actingAs(H::adminUser())->get($createUrl)->getContent();

        expect($html)
            ->toContain('<input type="hidden" name="is_active" value="0">')
            ->not->toMatch('/id="modIsActive" name="is_active" value="1"\s+checked>/');
    });

    test('скрытое is_active=0 сохраняет правило выключенным', function () {
        $this->actingAs(H::adminUser())
            ->post(route('admin.departments.modifiers.store', $this->dept), [
                'key'                => 'off_rule',
                'name'               => 'Выключенное правило',
                'trigger'            => DepartmentModifier::TRIGGER_MANUAL,
                'applies_to'         => DepartmentModifier::SCOPE_BOTH,
                'worker_coeff_delta' => '1',
                'is_active'          => '0',
            ])
            ->assertSessionHasNoErrors();

        expect($this->dept->modifiers()->where('key', 'off_rule')->value('is_active'))->toBeFalsy();
    });

    test('sku-правило без маски: форма открывается в режиме SKU', function () {
        $createUrl = route('admin.departments.modifiers.create', $this->dept);

        $this->actingAs(H::adminUser())
            ->from($createUrl)
            ->post(route('admin.departments.modifiers.store', $this->dept), [
                'key'        => 'small_tile',
                'name'       => 'Мелкая плитка',
                'trigger'    => DepartmentModifier::TRIGGER_SKU,
                'applies_to' => DepartmentModifier::SCOPE_BOTH,
            ])
            ->assertRedirect($createUrl)
            ->assertSessionHasErrors('sku_pattern');

        $this->actingAs(H::adminUser())
            ->get($createUrl)
            ->assertOk()
            ->assertSee("x-data=\"{ trigger: '" . DepartmentModifier::TRIGGER_SKU . "' }\"", false)
            ->assertSee('value="small_tile"', false);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Доступ
// ══════════════════════════════════════════════════════════════════════════════

describe('DepartmentModifierController create() — доступ', function () {

    test('мастер этого же отдела получает 403', function () {
        $this->actingAs(Access::master($this->dept, ['orders', 'supplier-orders']))
            ->get(route('admin.departments.modifiers.create', $this->dept))
            ->assertForbidden();
    });

    test('помощник мастера получает 403', function () {
        $this->actingAs(Access::userWithPosition('Помощник мастера', $this->dept))
            ->get(route('admin.departments.modifiers.create', $this->dept))
            ->assertForbidden();
    });
});
