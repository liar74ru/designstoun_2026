<?php

use App\Models\Product;
use App\Support\ProductSearch;
use Illuminate\Http\Request;

/**
 * Поиск по каталогу: id подходящих товаров для фильтров списков и дашбордов.
 */

beforeEach(function () {
    $this->quartz  = Product::factory()->create(['name' => 'Плитка Кварцит 50хL']);
    $this->quartz2 = Product::factory()->create(['name' => 'Брусчатка кварцит']);
    $this->granite = Product::factory()->create(['name' => 'Плитка Гранит']);
});

function productSearchRequest(array $filter): Request
{
    return Request::create('/', 'GET', ['filter' => $filter]);
}

test('matchIds находит все товары с частью названия', function () {
    expect(ProductSearch::matchIds('КВАРЦ'))
        ->toEqualCanonicalizing([$this->quartz->id, $this->quartz2->id]);
});

test('matchIds: ничего не подошло — [-1], чтобы фильтр вернул пустоту, а не всё', function () {
    expect(ProductSearch::matchIds('мрамор'))->toBe([-1]);
});

test('matchIds: запрос без слов — фильтра нет', function (mixed $query) {
    expect(ProductSearch::matchIds($query))->toBeNull();
})->with(['пусто' => [''], 'тире' => ['—'], 'null' => [null]]);

test('matchIds склеивает значение, разрезанное Spatie по запятым', function () {
    expect(ProductSearch::matchIds(['плитка', ' кварц']))->toBe([$this->quartz->id]);
});

test('idsFromRequest: ничего не задано — null', function () {
    expect(ProductSearch::idsFromRequest(productSearchRequest([]), 'filter.product_id', 'filter.product_search'))
        ->toBeNull();
});

test('idsFromRequest: выбранный товар', function () {
    $request = productSearchRequest(['product_id' => (string) $this->granite->id]);

    expect(ProductSearch::idsFromRequest($request, 'filter.product_id', 'filter.product_search'))
        ->toBe([$this->granite->id]);
});

test('idsFromRequest: текст поиска', function () {
    $request = productSearchRequest(['product_search' => 'кварц']);

    expect(ProductSearch::idsFromRequest($request, 'filter.product_id', 'filter.product_search'))
        ->toEqualCanonicalizing([$this->quartz->id, $this->quartz2->id]);
});

test('idsFromRequest: товар и текст вместе — пересечение', function (string $product, string $search, string $expected) {
    $request = productSearchRequest(['product_id' => (string) $this->{$product}->id, 'product_search' => $search]);
    $ids     = ProductSearch::idsFromRequest($request, 'filter.product_id', 'filter.product_search');

    expect($ids)->toBe($expected === 'none' ? [-1] : [$this->{$expected}->id]);
})->with([
    'товар подходит под текст'    => ['quartz', 'кварц', 'quartz'],
    'товар не подходит под текст' => ['granite', 'кварц', 'none'],
]);

test('idsFromRequest: значение-массив из адреса не роняет', function () {
    $request = productSearchRequest(['product_id' => ['x'], 'product_search' => ['кварц']]);

    expect(ProductSearch::idsFromRequest($request, 'filter.product_id', 'filter.product_search'))->toBeNull();
});
