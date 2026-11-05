<?php

use App\Support\MoyskladMoment;
use Carbon\Carbon;

afterEach(fn () => Carbon::setTestNow());

test('сдвигает время приложения (UTC+5) в таймзону МойСклад (UTC+3)', function () {
    expect(MoyskladMoment::format(Carbon::parse('2026-11-05 14:30:15')))->toBe('2026-11-05 12:30:15');
});

test('с миллисекундами — формат поставок и заказов поставщику', function () {
    expect(MoyskladMoment::format(Carbon::parse('2026-11-05 14:30:15'), true))->toBe('2026-11-05 12:30:15.000');
});

test('без даты — текущее время', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-05 09:00:00'));

    expect(MoyskladMoment::format())->toBe('2026-11-05 07:00:00');
});

test('ночью переходит на предыдущий день', function () {
    expect(MoyskladMoment::format(Carbon::parse('2026-01-01 01:30:00')))->toBe('2025-12-31 23:30:00');
});

test('не меняет переданную дату', function () {
    $date = Carbon::parse('2026-11-05 14:30:15');

    MoyskladMoment::format($date);

    expect($date->format('Y-m-d H:i:s'))->toBe('2026-11-05 14:30:15');
});
