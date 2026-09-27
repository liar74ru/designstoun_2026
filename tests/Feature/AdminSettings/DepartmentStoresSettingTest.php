<?php

use App\Models\Department;
use Illuminate\Support\Str;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

// ══════════════════════════════════════════════════════════════════════════════
// Склады по умолчанию отделов: POST /admin/departments/store-defaults
// ══════════════════════════════════════════════════════════════════════════════

beforeEach(function () {
    $this->raw        = H::store('Склад сырья');
    $this->product    = H::store('Склад готовой продукции');
    $this->production = H::store('Склад цеха');
});

describe('AdminSettingController updateDepartmentStores()', function () {

    test('сохраняет три склада отдела и возвращает в карточку отдела', function () {
        $dept = Access::department('Распиловка');

        $this->actingAs(H::adminUser())
            ->from(route('admin.departments.show', $dept))
            ->post(route('admin.departments.store-defaults'), [
                'departments' => [
                    $dept->id => [
                        'raw_store_id'        => $this->raw->id,
                        'product_store_id'    => $this->product->id,
                        'production_store_id' => $this->production->id,
                    ],
                ],
            ])
            ->assertRedirect(route('admin.departments.show', $dept))
            ->assertSessionHas('success', 'Склады отделов сохранены.')
            ->assertSessionHasNoErrors();

        $dept->refresh();
        expect($dept->default_raw_store_id)->toBe($this->raw->id)
            ->and($dept->default_product_store_id)->toBe($this->product->id)
            ->and($dept->default_production_store_id)->toBe($this->production->id);
    });

    test('обновляет несколько отделов за один запрос, каждому — свои склады', function () {
        $a = Access::department('Отдел А');
        $b = Access::department('Отдел Б');

        $this->actingAs(H::adminUser())
            ->post(route('admin.departments.store-defaults'), [
                'departments' => [
                    $a->id => ['raw_store_id' => $this->raw->id],
                    $b->id => ['raw_store_id' => $this->production->id, 'product_store_id' => $this->product->id],
                ],
            ])
            ->assertRedirect();

        expect($a->fresh()->default_raw_store_id)->toBe($this->raw->id)
            ->and($a->fresh()->default_product_store_id)->toBeNull()
            ->and($b->fresh()->default_raw_store_id)->toBe($this->production->id)
            ->and($b->fresh()->default_product_store_id)->toBe($this->product->id);
    });

    test('пустое значение сбрасывает склад отдела', function () {
        $dept = Access::department();
        $dept->update([
            'default_raw_store_id'        => $this->raw->id,
            'default_product_store_id'    => $this->product->id,
            'default_production_store_id' => $this->production->id,
        ]);

        $this->actingAs(H::adminUser())
            ->post(route('admin.departments.store-defaults'), [
                'departments' => [
                    $dept->id => [
                        'raw_store_id'        => '',
                        'product_store_id'    => $this->product->id,
                        'production_store_id' => '',
                    ],
                ],
            ])
            ->assertRedirect();

        $dept->refresh();
        expect($dept->default_raw_store_id)->toBeNull()
            ->and($dept->default_product_store_id)->toBe($this->product->id)
            ->and($dept->default_production_store_id)->toBeNull();
    });

    test('не трогает отделы, которых нет в запросе', function () {
        $sent    = Access::department();
        $skipped = Access::department();
        $skipped->update(['default_raw_store_id' => $this->raw->id]);

        $this->actingAs(H::adminUser())
            ->post(route('admin.departments.store-defaults'), [
                'departments' => [$sent->id => ['raw_store_id' => $this->product->id]],
            ])
            ->assertRedirect();

        expect($skipped->fresh()->default_raw_store_id)->toBe($this->raw->id);
    });

    test('несуществующий отдел молча пропускается, остальные сохраняются', function () {
        $dept = Access::department();

        $this->actingAs(H::adminUser())
            ->post(route('admin.departments.store-defaults'), [
                'departments' => [
                    999999   => ['raw_store_id' => $this->raw->id],
                    $dept->id => ['raw_store_id' => $this->raw->id],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        expect($dept->fresh()->default_raw_store_id)->toBe($this->raw->id)
            ->and(Department::find(999999))->toBeNull();
    });

    test('сохранённый склад сырья становится складом по умолчанию в форме поступления', function () {
        $dept = Access::department();

        $this->actingAs(H::adminUser())
            ->post(route('admin.departments.store-defaults'), [
                'departments' => [$dept->id => ['raw_store_id' => $this->production->id]],
            ]);

        $response = $this->actingAs(Access::master($dept, 'supplier-orders'))
            ->get(route('supplier-orders.create'))
            ->assertOk();

        expect($response->viewData('defaultStore')?->id)->toBe($this->production->id);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Валидация
// ══════════════════════════════════════════════════════════════════════════════

describe('AdminSettingController updateDepartmentStores() — валидация', function () {

    test('без массива departments — ошибка', function () {
        $this->actingAs(H::adminUser())
            ->post(route('admin.departments.store-defaults'), [])
            ->assertSessionHasErrors('departments');
    });

    test('departments не массив — ошибка', function () {
        $this->actingAs(H::adminUser())
            ->post(route('admin.departments.store-defaults'), ['departments' => 'всё'])
            ->assertSessionHasErrors('departments');
    });

    test('несуществующий склад отклоняется, ничего не сохраняется', function () {
        $dept = Access::department();
        $dept->update(['default_product_store_id' => $this->product->id]);
        $fake = (string) Str::uuid();

        $this->actingAs(H::adminUser())
            ->post(route('admin.departments.store-defaults'), [
                'departments' => [
                    $dept->id => [
                        'raw_store_id'        => $this->raw->id,
                        'product_store_id'    => $fake,
                        'production_store_id' => $fake,
                    ],
                ],
            ])
            ->assertSessionHasErrors([
                "departments.{$dept->id}.product_store_id",
                "departments.{$dept->id}.production_store_id",
            ])
            ->assertSessionDoesntHaveErrors("departments.{$dept->id}.raw_store_id");

        $dept->refresh();
        expect($dept->default_raw_store_id)->toBeNull()
            ->and($dept->default_product_store_id)->toBe($this->product->id);
    });

    test('склад не строкой отклоняется', function () {
        $dept = Access::department();

        $this->actingAs(H::adminUser())
            ->post(route('admin.departments.store-defaults'), [
                'departments' => [$dept->id => ['raw_store_id' => ['a', 'b']]],
            ])
            ->assertSessionHasErrors("departments.{$dept->id}.raw_store_id");
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Доступ
// ══════════════════════════════════════════════════════════════════════════════

describe('AdminSettingController updateDepartmentStores() — доступ', function () {

    test('мастер своего отдела получает 403 и склады не меняются', function () {
        $dept = Access::department();

        $this->actingAs(Access::master($dept, ['supplier-orders', 'orders']))
            ->post(route('admin.departments.store-defaults'), [
                'departments' => [$dept->id => ['raw_store_id' => $this->raw->id]],
            ])
            ->assertForbidden();

        expect($dept->fresh()->default_raw_store_id)->toBeNull();
    });

    test('работник получает 403', function () {
        $dept = Access::department();

        $this->actingAs(Access::userWithPosition('Работник', $dept))
            ->post(route('admin.departments.store-defaults'), [
                'departments' => [$dept->id => ['raw_store_id' => $this->raw->id]],
            ])
            ->assertForbidden();
    });
});
