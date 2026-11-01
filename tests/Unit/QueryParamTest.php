<?php

use App\Support\QueryParam;
use Illuminate\Http\Request;

function queryParamRequest(array $query): Request
{
    return Request::create('/', 'GET', $query);
}

test('id принимает только положительное число в пределах integer', function (mixed $value, ?int $expected) {
    expect(QueryParam::id(queryParamRequest(['id' => $value]), 'id'))->toBe($expected);
})->with([
    'число'           => ['15', 15],
    'граница integer' => ['2147483647', 2147483647],
    'за границей'     => ['2147483648', null],
    'очень длинное'   => ['99999999999999999999999', null],
    'ноль'            => ['0', null],
    'минус'           => ['-1', null],
    'текст'           => ['abc', null],
    'дробное'         => ['1.5', null],
    'инъекция'        => ['1 or 1=1', null],
    'пусто'           => ['', null],
    'массив'          => [['1'], null],
]);

test('id без параметра — null', function () {
    expect(QueryParam::id(queryParamRequest([]), 'id'))->toBeNull();
});

test('id читает вложенный ключ фильтра', function () {
    expect(QueryParam::id(queryParamRequest(['filter' => ['worker_id' => '7']]), 'filter.worker_id'))->toBe(7);
});

test('ids оставляет только верные значения', function () {
    $request = queryParamRequest(['ids' => ['3', 'abc', '', '0', '99999999999', ['5'], '8']]);

    expect(QueryParam::ids($request, 'ids'))->toBe([3, 8]);
});

test('ids принимает одно значение и пустой параметр', function () {
    expect(QueryParam::ids(queryParamRequest(['ids' => '4']), 'ids'))->toBe([4])
        ->and(QueryParam::ids(queryParamRequest([]), 'ids'))->toBe([]);
});

test('date разбирает дату, неверное значение — null', function () {
    expect(QueryParam::date(queryParamRequest(['d' => '2026-03-05']), 'd')?->format('Y-m-d'))->toBe('2026-03-05')
        ->and(QueryParam::date(queryParamRequest(['d' => 'не дата']), 'd'))->toBeNull()
        ->and(QueryParam::date(queryParamRequest(['d' => '2026-13-45']), 'd'))->toBeNull()
        ->and(QueryParam::date(queryParamRequest(['d' => '']), 'd'))->toBeNull()
        ->and(QueryParam::date(queryParamRequest(['d' => ['x']]), 'd'))->toBeNull()
        ->and(QueryParam::date(queryParamRequest([]), 'd'))->toBeNull();
});
