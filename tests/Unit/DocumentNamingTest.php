<?php

use App\Support\DocumentNaming;
use Carbon\Carbon;

describe('DocumentNaming::weekPrefix()', function () {

    test('совпадает с неделей из weeklyName', function () {
        $date = Carbon::parse('2026-07-15'); // среда, ISO-неделя 29

        expect(DocumentNaming::weekPrefix('УПАК', $date))->toBe('26-29-УПАК-')
            ->and(DocumentNaming::weeklyName('УПАК', 2, $date))->toBe('26-29-УПАК-02');
    });
});

describe('DocumentNaming::nextSequence()', function () {

    test('без существующих имён возвращает 1', function () {
        expect(DocumentNaming::nextSequence([], '26-29-УПАК-'))->toBe(1);
    });

    test('возвращает max NN + 1', function () {
        $names = ['26-29-УПАК-01', '26-29-УПАК-03', '26-29-УПАК-02'];

        expect(DocumentNaming::nextSequence($names, '26-29-УПАК-'))->toBe(4);
    });

    test('имя с суффиксом коллизии учитывается по базовому NN', function () {
        $names = ['26-29-УПАК-02_01'];

        expect(DocumentNaming::nextSequence($names, '26-29-УПАК-'))->toBe(3);
    });

    test('имена, не подходящие под префикс, игнорируются', function () {
        $names = ['26-28-УПАК-07', 'произвольное имя', null];

        expect(DocumentNaming::nextSequence($names, '26-29-УПАК-'))->toBe(1);
    });
});
