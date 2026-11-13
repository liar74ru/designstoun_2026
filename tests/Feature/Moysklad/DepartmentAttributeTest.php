<?php

use App\Models\Department;
use App\Models\Order;
use App\Models\OrderState;
use App\Models\Product;
use App\Services\Moysklad\CustomerOrderSyncService;
use App\Services\Moysklad\DepartmentAttributeService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

const DA_STATE   = '77777777-7777-7777-7777-777777777777';
const DA_ATTR_1  = 'd0000001-0000-0000-0000-000000000000';
const DA_ATTR_2  = 'd0000002-0000-0000-0000-000000000000';
const DA_FOREIGN = 'd0000009-0000-0000-0000-000000000000';

/** Строка метаданных реквизита заказа; $description = null — без описания. */
function daAttribute(string $id, string $name, ?string $description = 'Отдел', string $type = 'boolean'): array
{
    return array_filter([
        'id'          => $id,
        'name'        => $name,
        'type'        => $type,
        'description' => $description,
        'meta'        => [
            'href'      => 'https://api.moysklad.ru/api/remap/1.2/entity/customerorder/metadata/attributes/' . $id,
            'type'      => 'attributemetadata',
            'mediaType' => 'application/json',
        ],
    ], fn ($v) => $v !== null);
}

/** Значение флажка в самой заявке. */
function daValue(string $id, string $name, bool $value = true): array
{
    return ['id' => $id, 'name' => $name, 'type' => 'boolean', 'value' => $value];
}

/** МойСклад для pullActive: справочник статусов, реквизиты заказа, одна заявка с флажками. */
function daFakeSync(array $metadataRows, array $orderAttributes): void
{
    $product = Product::factory()->create();

    Http::fake([
        '*/entity/customerorder/metadata/attributes' => Http::response(['rows' => $metadataRows], 200),
        '*/entity/customerorder/metadata'            => Http::response(['states' => [
            ['id' => DA_STATE, 'name' => 'В процессе', 'color' => 0, 'stateType' => 'Regular'],
        ]], 200),
        '*/entity/customerorder?*' => Http::response([
            'rows' => [[
                'id'    => 'ms-1',
                'name'  => 'Заявка 1',
                'state' => [
                    'meta' => ['href' => 'https://api.moysklad.ru/entity/customerorder/metadata/states/' . DA_STATE],
                    'name' => 'В процессе',
                ],
                'positions' => ['rows' => [[
                    'quantity'   => 1,
                    'shipped'    => 0,
                    'assortment' => [
                        'meta' => ['href' => 'https://api.moysklad.ru/entity/product/' . $product->moysklad_id],
                        'name' => $product->name,
                    ],
                ]]],
                'attributes' => $orderAttributes,
            ]],
            'meta' => ['size' => 1],
        ], 200),
        '*' => Http::response(['rows' => [], 'meta' => ['size' => 0]], 200),
    ]);
}

/** Id отделов заявки ms-1 после синхронизации. */
function daOrderDepartments(): array
{
    return Order::where('moysklad_id', 'ms-1')->firstOrFail()
        ->departments()->orderBy('departments.id')->pluck('departments.id')->all();
}

beforeEach(function () {
    config()->set('services.moysklad.token', 'test-token');
    OrderState::query()->delete();
    OrderState::create(['id' => DA_STATE, 'name' => 'В процессе', 'is_enabled' => true]);
});

// ══════════════════════════════════════════════════════════════════════════════
// Синхронизация заявок: отдел — по id флажка
// ══════════════════════════════════════════════════════════════════════════════

describe('Синхронизация: отдел заявки по id флажка', function () {

    test('флажок, переименованный в МойСклад, по-прежнему даёт отдел', function () {
        $dept = Department::create(['name' => 'Резка', 'is_active' => true, 'moysklad_attribute_id' => DA_ATTR_1]);

        daFakeSync(
            [daAttribute(DA_ATTR_1, 'Резка камня')],
            [daValue(DA_ATTR_1, 'Резка камня')],
        );

        app(CustomerOrderSyncService::class)->pullActive();

        expect(daOrderDepartments())->toBe([$dept->id]);
    });

    test('флажок с именем отдела, но другим id — не отдел', function () {
        Department::create(['name' => 'Резка', 'is_active' => true, 'moysklad_attribute_id' => DA_ATTR_1]);

        daFakeSync(
            [daAttribute(DA_ATTR_1, 'Резка'), daAttribute(DA_FOREIGN, 'Резка', null)],
            [daValue(DA_ATTR_1, 'Резка', false), daValue(DA_FOREIGN, 'Резка')],
        );

        app(CustomerOrderSyncService::class)->pullActive();

        expect(daOrderDepartments())->toBe([]);
    });

    test('несвязанный отдел привязывается по имени к свободному флажку «Отдел»', function () {
        $dept = Department::create(['name' => 'Цех', 'is_active' => true]);

        daFakeSync(
            [daAttribute(DA_ATTR_2, 'Цех')],
            [daValue(DA_ATTR_2, 'Цех')],
        );

        app(CustomerOrderSyncService::class)->pullActive();

        expect($dept->refresh()->moysklad_attribute_id)->toBe(DA_ATTR_2)
            ->and(daOrderDepartments())->toBe([$dept->id]);
    });

    test('автопривязка не берёт флажок без описания «Отдел» и занятый другим отделом', function () {
        Department::create(['name' => 'Резка', 'is_active' => true, 'moysklad_attribute_id' => DA_ATTR_1]);
        // Флажок «Резка» уже занят, а «Цех» без описания — оба не подходят
        $copy = Department::create(['name' => 'Цех', 'is_active' => true]);

        daFakeSync(
            [daAttribute(DA_ATTR_1, 'Цех'), daAttribute(DA_FOREIGN, 'Цех', 'Комментарий')],
            [daValue(DA_FOREIGN, 'Цех')],
        );

        app(CustomerOrderSyncService::class)->pullActive();

        expect($copy->refresh()->moysklad_attribute_id)->toBeNull()
            ->and(daOrderDepartments())->toBe([]);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Запись отделов в МойСклад
// ══════════════════════════════════════════════════════════════════════════════

describe('Запись отделов заявки в МойСклад', function () {

    test('пишет флажок по сохранённому id, метаданные не запрашивает', function () {
        $dept = Department::create(['name' => 'Резка', 'is_active' => true, 'moysklad_attribute_id' => DA_ATTR_1]);
        Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);

        Http::fake(['*/entity/customerorder/ms-1' => Http::response(['id' => 'ms-1'], 200)]);

        $result = app(CustomerOrderSyncService::class)
            ->updateDepartments(Order::where('moysklad_id', 'ms-1')->first(), [$dept->id]);

        expect($result['success'])->toBeTrue();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_ends_with($r->data()['attributes'][0]['meta']['href'], '/metadata/attributes/' . DA_ATTR_1)
            && $r->data()['attributes'][0]['value'] === true);
    });

    test('несопоставленный отдел — ошибка с подсказкой, запросов нет', function () {
        $dept = Department::create(['name' => 'Пескоструй', 'is_active' => true]);
        $order = Order::create(['moysklad_id' => 'ms-1', 'name' => 'Заявка 1']);
        Http::fake();

        $result = app(CustomerOrderSyncService::class)->updateDepartments($order, [$dept->id]);

        expect($result['success'])->toBeFalse()
            ->and($result['code'])->toBe('no_attribute')
            ->and($result['message'])->toContain('«Пескоструй»')
            ->and($result['message'])->toContain('карточке отдела');
        Http::assertNothingSent();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Карточка отдела: выбор и создание флажка
// ══════════════════════════════════════════════════════════════════════════════

describe('Карточка отдела: флажок МойСклад', function () {

    test('админ связывает отдел с флажком «Отдел»', function () {
        $dept = Department::create(['name' => 'Пескоструй', 'is_active' => true]);
        Http::fake(['*/metadata/attributes' => Http::response(['rows' => [daAttribute(DA_ATTR_1, 'Пескоструйка')]], 200)]);

        $this->actingAs(H::adminUser())
            ->patch(route('admin.departments.moysklad-attribute.update', $dept), ['moysklad_attribute_id' => DA_ATTR_1])
            ->assertRedirect(route('admin.departments.show', $dept) . '#main')
            ->assertSessionHas('success');

        expect($dept->refresh()->moysklad_attribute_id)->toBe(DA_ATTR_1);
    });

    test('флажок без описания «Отдел» и флажок другого отдела не связываются', function () {
        Department::create(['name' => 'Резка', 'is_active' => true, 'moysklad_attribute_id' => DA_ATTR_1]);
        $dept = Department::create(['name' => 'Пескоструй', 'is_active' => true]);
        Http::fake(['*/metadata/attributes' => Http::response(['rows' => [
            daAttribute(DA_ATTR_1, 'Резка'),
            daAttribute(DA_FOREIGN, 'Срочно', null),
        ]], 200)]);

        foreach ([DA_ATTR_1, DA_FOREIGN] as $attributeId) {
            $this->actingAs(H::adminUser())
                ->patch(route('admin.departments.moysklad-attribute.update', $dept), ['moysklad_attribute_id' => $attributeId])
                ->assertSessionHas('error');
        }

        expect($dept->refresh()->moysklad_attribute_id)->toBeNull();
    });

    test('пустой выбор отвязывает отдел без запросов в МойСклад', function () {
        $dept = Department::create(['name' => 'Резка', 'is_active' => true, 'moysklad_attribute_id' => DA_ATTR_1]);
        Http::fake();

        $this->actingAs(H::adminUser())
            ->patch(route('admin.departments.moysklad-attribute.update', $dept), ['moysklad_attribute_id' => ''])
            ->assertSessionHas('success');

        expect($dept->refresh()->moysklad_attribute_id)->toBeNull();
        Http::assertNothingSent();
    });

    test('«Создать флажок» создаёт реквизит с описанием «Отдел» и связывает отдел', function () {
        $dept = Department::create(['name' => 'Пескоструй', 'is_active' => true]);
        Http::fake(function (Request $r) {
            return $r->method() === 'POST'
                ? Http::response(daAttribute(DA_ATTR_2, 'Пескоструй'), 200)
                : Http::response(['rows' => []], 200);
        });

        $this->actingAs(H::adminUser())
            ->post(route('admin.departments.moysklad-attribute.store', $dept))
            ->assertSessionHas('success');

        expect($dept->refresh()->moysklad_attribute_id)->toBe(DA_ATTR_2);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->data() === ['name' => 'Пескоструй', 'type' => 'boolean', 'required' => false, 'description' => 'Отдел']);
    });

    test('«Создать флажок» при свободном флажке с тем же именем связывает его без дубля', function () {
        $dept = Department::create(['name' => 'Пескоструй', 'is_active' => true]);
        Http::fake(['*/metadata/attributes' => Http::response(['rows' => [daAttribute(DA_ATTR_1, 'Пескоструй')]], 200)]);

        $this->actingAs(H::adminUser())
            ->post(route('admin.departments.moysklad-attribute.store', $dept))
            ->assertSessionHas('success');

        expect($dept->refresh()->moysklad_attribute_id)->toBe(DA_ATTR_1);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    });

    test('ошибка МойСклад при создании — отдел остаётся несвязанным', function () {
        $dept = Department::create(['name' => 'Пескоструй', 'is_active' => true]);
        Http::fake(function (Request $r) {
            return $r->method() === 'POST'
                ? Http::response(['errors' => [['error' => 'Нет прав на изменение метаданных']]], 403)
                : Http::response(['rows' => []], 200);
        });

        $this->actingAs(H::adminUser())
            ->post(route('admin.departments.moysklad-attribute.store', $dept))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Нет прав на изменение метаданных'));

        expect($dept->refresh()->moysklad_attribute_id)->toBeNull();
    });

    test('мастер не может менять связь отдела', function () {
        $dept = Department::create(['name' => 'Резка', 'is_active' => true]);
        Http::fake();

        $master = Access::master($dept, 'orders');

        $this->actingAs($master)
            ->patch(route('admin.departments.moysklad-attribute.update', $dept), ['moysklad_attribute_id' => DA_ATTR_1])
            ->assertForbidden();
        $this->actingAs($master)
            ->post(route('admin.departments.moysklad-attribute.store', $dept))
            ->assertForbidden();

        expect($dept->refresh()->moysklad_attribute_id)->toBeNull();
        Http::assertNothingSent();
    });

    test('сервис не принимает флажок, которого нет в МойСклад', function () {
        $dept = Department::create(['name' => 'Резка', 'is_active' => true]);
        Http::fake(['*/metadata/attributes' => Http::response(['rows' => []], 200)]);

        $result = app(DepartmentAttributeService::class)->link($dept, DA_ATTR_1);

        expect($result['code'])->toBe('no_attribute')
            ->and($dept->refresh()->moysklad_attribute_id)->toBeNull();
    });
});
