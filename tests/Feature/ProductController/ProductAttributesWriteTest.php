<?php

use App\Models\Product;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

/** Строка метаданных доп. реквизита товара. */
function productAttributeMeta(string $name): array
{
    return [
        'id'   => 'attr-' . $name,
        'name' => $name,
        'meta' => [
            'href'      => 'https://api.moysklad.ru/api/remap/1.2/entity/product/metadata/attributes/attr-' . $name,
            'type'      => 'attributemetadata',
            'mediaType' => 'application/json',
        ],
    ];
}

/** МойСклад: реквизиты $names на GET метаданных, ответ $putStatus на PUT товара. */
function fakeProductAttributes(array $names, int $putStatus = 200, array $putBody = ['id' => 'ms-1']): void
{
    Http::fake([
        '*/entity/product/metadata/attributes*' => Http::response(['rows' => array_map('productAttributeMeta', $names)], 200),
        '*/entity/product/*'                    => Http::response($putBody, $putStatus),
    ]);
}

/** Реквизиты, ушедшие в PUT товара: имя (по id меты) → значение. */
function sentProductAttributes(): array
{
    $put = Http::recorded(fn (Request $request) => $request->method() === 'PUT')->first();
    expect($put)->not->toBeNull();

    return collect($put[0]->data()['attributes'])
        ->mapWithKeys(fn ($a) => [str_replace('attr-', '', basename($a['meta']['href'])) => $a['value']])
        ->all();
}

beforeEach(function () {
    config()->set('services.moysklad.token', 'test-token');
});

// ══════════════════════════════════════════════════════════════════════════════
// Скрытие товара — hiddenByDefault
// ══════════════════════════════════════════════════════════════════════════════

describe('Скрытие товара в выборе товара', function () {

    test('скрытие пишет hiddenByDefault в МойСклад, ставит is_hidden и сбрасывает кэш дерева', function () {
        fakeProductAttributes(['hiddenByDefault']);
        $product = Product::factory()->create(['is_hidden' => false]);
        Cache::put('products_tree_json_v4', ['old'], 600);

        $this->actingAs(H::adminUser())
            ->patch(route('products.hidden.update', $product->moysklad_id), ['hidden' => '1'])
            ->assertRedirect(route('products.show', $product->moysklad_id))
            ->assertSessionHas('success', 'Товар скрыт в выборе товара');

        expect(sentProductAttributes())->toBe(['hiddenByDefault' => true])
            ->and($product->fresh()->is_hidden)->toBeTrue()
            ->and(Cache::has('products_tree_json_v4'))->toBeFalse();
    });

    test('показ пишет hiddenByDefault = false и снимает is_hidden', function () {
        fakeProductAttributes(['hiddenByDefault']);
        $product = Product::factory()->create(['is_hidden' => true]);

        $this->actingAs(H::adminUser())
            ->patch(route('products.hidden.update', $product->moysklad_id), ['hidden' => '0'])
            ->assertSessionHas('success', 'Товар снова виден в выборе товара');

        expect(sentProductAttributes())->toBe(['hiddenByDefault' => false])
            ->and($product->fresh()->is_hidden)->toBeFalse();
    });

    test('мастер с доступом к «Товарам» может скрыть товар', function () {
        fakeProductAttributes(['hiddenByDefault']);
        $product = Product::factory()->create(['is_hidden' => false]);

        $this->actingAs(Access::master(Access::department(), 'products'))
            ->patch(route('products.hidden.update', $product->moysklad_id), ['hidden' => '1'])
            ->assertSessionHas('success');

        expect($product->fresh()->is_hidden)->toBeTrue();
    });

    test('без доступа к «Товарам» — 403, в МойСклад ничего не уходит', function () {
        Http::fake();
        $product = Product::factory()->create(['is_hidden' => false]);

        $this->actingAs(Access::masterWithoutDept())
            ->patch(route('products.hidden.update', $product->moysklad_id), ['hidden' => '1'])
            ->assertForbidden();

        Http::assertNothingSent();
        expect($product->fresh()->is_hidden)->toBeFalse();
    });

    test('нет реквизита hiddenByDefault в МойСклад — ошибка, локально без изменений', function () {
        fakeProductAttributes(['prodCostCoeff']);
        $product = Product::factory()->create(['is_hidden' => false]);

        $this->actingAs(H::adminUser())
            ->patch(route('products.hidden.update', $product->moysklad_id), ['hidden' => '1'])
            ->assertSessionHas('error', 'В МойСклад нет реквизита товара: «hiddenByDefault»');

        expect($product->fresh()->is_hidden)->toBeFalse();
    });

    test('ошибка API — текст из errors[0].error, локально без изменений', function () {
        fakeProductAttributes(['hiddenByDefault'], 400, ['errors' => [['error' => 'Товар не найден']]]);
        $product = Product::factory()->create(['is_hidden' => false]);

        $this->actingAs(H::adminUser())
            ->patch(route('products.hidden.update', $product->moysklad_id), ['hidden' => '1'])
            ->assertSessionHas('error', 'Ошибка МойСклад: Товар не найден');

        expect($product->fresh()->is_hidden)->toBeFalse();
    });

    test('без поля hidden — ошибка валидации, в МойСклад ничего не уходит', function () {
        Http::fake();
        $product = Product::factory()->create();

        $this->actingAs(H::adminUser())
            ->patch(route('products.hidden.update', $product->moysklad_id))
            ->assertSessionHasErrors('hidden');

        Http::assertNothingSent();
    });

    test('карточка товара показывает кнопку по текущему состоянию', function () {
        $visible = Product::factory()->create(['is_hidden' => false]);
        $hidden  = Product::factory()->create(['is_hidden' => true]);

        $this->actingAs(H::adminUser())
            ->get(route('products.show', $visible->moysklad_id))
            ->assertSee(route('products.hidden.update', $visible->moysklad_id))
            ->assertSee('Скрыть');

        $this->actingAs(H::adminUser())
            ->get(route('products.show', $hidden->moysklad_id))
            ->assertSee('Показать');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Коэффициенты — тот же общий путь записи реквизитов
// ══════════════════════════════════════════════════════════════════════════════

describe('Запись коэффициентов товара', function () {

    test('админ пишет prodCostCoeff и masterCostCoeff в МойСклад и локально', function () {
        fakeProductAttributes(['prodCostCoeff', 'masterCostCoeff']);
        $product = Product::factory()->create(['prod_cost_coeff' => 0, 'master_cost_coeff' => 0]);

        $this->actingAs(H::adminUser())
            ->patch(route('products.coeffs.update', $product->moysklad_id), [
                'prod_cost_coeff'   => '1.5',
                'master_cost_coeff' => '0.25',
            ])
            ->assertSessionHas('success', 'Коэффициенты товара сохранены в МойСклад');

        expect(sentProductAttributes())->toEqual(['prodCostCoeff' => 1.5, 'masterCostCoeff' => 0.25]);

        $product->refresh();
        expect((float) $product->prod_cost_coeff)->toBe(1.5)
            ->and((float) $product->master_cost_coeff)->toBe(0.25);
    });

    test('не-админ с доступом к «Товарам» — 403', function () {
        Http::fake();
        $product = Product::factory()->create();

        $this->actingAs(Access::master(Access::department(), 'products'))
            ->patch(route('products.coeffs.update', $product->moysklad_id), [
                'prod_cost_coeff'   => '1',
                'master_cost_coeff' => '1',
            ])
            ->assertForbidden();

        Http::assertNothingSent();
    });
});
