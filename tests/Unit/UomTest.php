<?php

use App\Models\Product;
use App\Support\Uom;

// ══════════════════════════════════════════════════════════════════════════════
// Uom — единицы измерения из карточки товара для показа
// ══════════════════════════════════════════════════════════════════════════════

describe('Uom::label()', function () {

    test('м2 и м3 из МойСклад показываются со степенью', function () {
        expect(Uom::label('м2'))->toBe('м²')
            ->and(Uom::label('м3'))->toBe('м³');
    });

    test('остальные единицы не меняются, пробелы по краям срезаются', function () {
        expect(Uom::label('т'))->toBe('т')
            ->and(Uom::label('пог. м'))->toBe('пог. м')
            ->and(Uom::label(' шт '))->toBe('шт');
    });

    test('нет единицы — пустая строка, без подстановки по умолчанию', function () {
        expect(Uom::label(null))->toBe('')
            ->and(Uom::label(''))->toBe('');
    });

    test('of() берёт единицу товара; товара нет — пусто', function () {
        expect(Uom::of(new Product(['uom' => 'м3'])))->toBe('м³')
            ->and(Uom::of(null))->toBe('');
    });
});

describe('Uom::rate()', function () {

    test('ставка за единицу товара', function () {
        expect(Uom::rate('м2'))->toBe('₽/м²')
            ->and(Uom::rate('шт'))->toBe('₽/шт');
    });

    test('без единицы — «₽/ед.»', function () {
        expect(Uom::rate())->toBe('₽/ед.')
            ->and(Uom::rate(''))->toBe('₽/ед.');
    });
});

describe('Uom::totals() / itemTotals()', function () {

    test('суммы раздельно по единицам в порядке появления', function () {
        $rows = [
            ['qty' => 10, 'uom' => 'м2'],
            ['qty' => 3, 'uom' => 'шт'],
            ['qty' => 2.5, 'uom' => 'м²'], // уже нормализованная — та же единица
        ];

        expect(Uom::totals($rows, fn ($r) => $r['qty'], fn ($r) => $r['uom']))
            ->toBe(['м²' => 12.5, 'шт' => 3.0]);
    });

    test('строки без единицы собираются под пустым ключом', function () {
        expect(Uom::totals([['qty' => 4, 'uom' => null]], fn ($r) => $r['qty'], fn ($r) => $r['uom']))
            ->toBe(['' => 4.0]);
    });

    test('itemTotals() берёт quantity и единицу товара позиции', function () {
        $items = collect([
            (object) ['quantity' => '1.5', 'product' => new Product(['uom' => 'т'])],
            (object) ['quantity' => '2', 'product' => new Product(['uom' => 'т'])],
            (object) ['quantity' => '7', 'product' => new Product(['uom' => 'шт'])],
        ]);

        expect(Uom::itemTotals($items))->toBe(['т' => 3.5, 'шт' => 7.0]);
    });
});

describe('Uom::format() / formatTotals()', function () {

    test('число с единицей; без единицы — только число', function () {
        expect(Uom::format(1234.5, 'м2'))->toBe('1 234,500 м²')
            ->and(Uom::format(2, null, 2))->toBe('2,00');
    });

    test('итог по нескольким единицам — через «·»', function () {
        expect(Uom::formatTotals(['м²' => 12.5, 'шт' => 3.0]))->toBe('12,500 м² · 3 шт');
    });

    test('штуки — без нулей после запятой, дробь — если есть', function () {
        expect(Uom::format(3, 'шт'))->toBe('3 шт')
            ->and(Uom::format(2.5, 'шт'))->toBe('2,5 шт')
            ->and(Uom::format(1.125, 'шт'))->toBe('1,125 шт')
            ->and(Uom::format(2.9999999, 'шт'))->toBe('3 шт')
            ->and(Uom::format(1500, 'шт'))->toBe('1 500 шт')
            ->and(Uom::number(12.5, 'м²'))->toBe('12,500');
    });

    test('пустой итог — ноль', function () {
        expect(Uom::formatTotals([]))->toBe('0,000');
    });
});

describe('Uom::common()', function () {

    test('одна единица у всех — она', function () {
        expect(Uom::common(['м2', 'м²', 'м2']))->toBe('м²');
    });

    test('разные единицы — null', function () {
        expect(Uom::common(['м2', 'шт']))->toBeNull();
    });

    test('пусто или единица не задана — null', function () {
        expect(Uom::common([]))->toBeNull()
            ->and(Uom::common([null, null]))->toBeNull()
            ->and(Uom::common(['м2', null]))->toBeNull();
    });
});
