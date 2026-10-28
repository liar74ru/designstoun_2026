<?php

use App\Models\Department;
use App\Models\Product;
use App\Models\SkuColor;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\ReceptionTestHelper as H;

// ══════════════════════════════════════════════════════════════════════════════
// Цвета по SKU: POST /admin/sku-colors и Product::getColorBySku()
// ══════════════════════════════════════════════════════════════════════════════

beforeEach(function () {
    Cache::flush();
    Product::forgetSkuColorMemo();
});

// Мемо — статическое свойство: не даём изменённой карте утечь в другие тесты
afterEach(function () {
    Product::forgetSkuColorMemo();
});

function saveSkuColors($test, array $rows)
{
    return $test->actingAs(H::adminUser())
        ->post(route('admin.sku-colors.update'), ['rows' => $rows]);
}

// ─── Подбор цвета ────────────────────────────────────────────────────────────

test('миграция переносит прежние цвета: по одному цвету на камень', function () {
    expect(SkuColor::count())->toBe(8)
        ->and(Product::getColorBySku('01-07-30'))->toBe('#92D050')
        ->and(Product::getColorBySku('04-07-30'))->toBe('#92D050')
        ->and(Product::getColorBySku('04-01'))->toBe('#BFBFBF');
});

test('неизвестный и пустой SKU — белый', function () {
    expect(Product::getColorBySku('02-01-10'))->toBe('#FFFFFF')
        ->and(Product::getColorBySku(null))->toBe('#FFFFFF')
        ->and(Product::getColorBySku(''))->toBe('#FFFFFF');
});

test('префикс из одного сегмента — цвет группы, свой префикс камня важнее', function () {
    saveSkuColors($this, [
        ['name' => 'Сырьё',   'color' => '#123456', 'prefixes' => '01'],
        ['name' => 'Сланец',  'color' => '#92D050', 'prefixes' => '01-07'],
    ])->assertSessionHasNoErrors();

    expect(Product::getColorBySku('01-05-30'))->toBe('#123456')
        ->and(Product::getColorBySku('01-07-30'))->toBe('#92D050')
        ->and(Product::getColorBySku('04-05-30'))->toBe('#FFFFFF');
});

// ─── Сохранение ──────────────────────────────────────────────────────────────

test('администратор сохраняет список: полная замена, префиксы строкой', function () {
    saveSkuColors($this, [
        ['name' => 'Кварцит', 'color' => '#bfbfbf', 'prefixes' => '01-01, 04-01;01-01  05-01'],
    ])
        ->assertRedirect(route('admin.settings.sku-colors'))
        ->assertSessionHas('success');

    $rows = SkuColor::all();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->name)->toBe('Кварцит')
        ->and($rows->first()->color)->toBe('#BFBFBF')
        // разделители — запятая, точка с запятой, пробелы; повтор внутри строки отброшен
        ->and($rows->first()->prefixes)->toBe(['01-01', '04-01', '05-01']);
});

test('пустое название сохраняется как null', function () {
    saveSkuColors($this, [
        ['name' => '  ', 'color' => '#000000', 'prefixes' => '01-08'],
    ])->assertSessionHasNoErrors();

    expect(SkuColor::first()->name)->toBeNull();
});

test('пустой список удаляет все цвета', function () {
    saveSkuColors($this, [])->assertSessionHasNoErrors();

    expect(SkuColor::count())->toBe(0)
        ->and(Product::getColorBySku('01-07-30'))->toBe('#FFFFFF');
});

test('кэш карты сбрасывается после сохранения', function () {
    // прогреваем кэш и мемо прежним цветом
    expect(Product::getColorBySku('01-07-30'))->toBe('#92D050');

    saveSkuColors($this, [
        ['name' => 'Сланец', 'color' => '#654321', 'prefixes' => '01-07'],
    ]);

    expect(Product::getColorBySku('01-07-30'))->toBe('#654321');
});

// ─── Валидация ───────────────────────────────────────────────────────────────

test('один префикс у двух цветов отклоняется, список не меняется', function () {
    saveSkuColors($this, [
        ['name' => 'А', 'color' => '#111111', 'prefixes' => '01-07'],
        ['name' => 'Б', 'color' => '#222222', 'prefixes' => '04-07, 01-07'],
    ])->assertSessionHasErrors('rows');

    expect(SkuColor::count())->toBe(8);
});

test('цвет без префиксов отклоняется', function () {
    saveSkuColors($this, [
        ['name' => 'А', 'color' => '#111111', 'prefixes' => ' , '],
    ])->assertSessionHasErrors('rows.0.prefixes');
});

test('префикс из трёх сегментов отклоняется', function () {
    saveSkuColors($this, [
        ['name' => 'А', 'color' => '#111111', 'prefixes' => '01-07-30'],
    ])->assertSessionHasErrors('rows.0.prefixes.0');
});

test('цвет не в формате #RRGGBB отклоняется', function (string $color) {
    saveSkuColors($this, [
        ['name' => 'А', 'color' => $color, 'prefixes' => '01-07'],
    ])->assertSessionHasErrors('rows.0.color');
})->with(['red', '#FFF', '#GGGGGG', '']);

// ─── Доступ и страница ───────────────────────────────────────────────────────

test('не-админ получает 403', function () {
    $dept   = Department::create(['name' => 'Отдел', 'is_active' => true]);
    $worker = Worker::create(['name' => 'Мастер', 'position' => 'Мастер', 'department_id' => $dept->id]);
    $user   = User::factory()->create(['is_admin' => false, 'worker_id' => $worker->id]);

    $this->actingAs($user)
        ->post(route('admin.sku-colors.update'), [
            'rows' => [['name' => 'А', 'color' => '#111111', 'prefixes' => '01-07']],
        ])
        ->assertForbidden();

    expect(SkuColor::count())->toBe(8);
});

test('страница цветов показывает префиксы строкой', function () {
    $this->actingAs(H::adminUser())
        ->get(route('admin.settings.sku-colors'))
        ->assertOk()
        ->assertSee('Цвета по SKU')
        ->assertSee('01-07, 04-07');
});
