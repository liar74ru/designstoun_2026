<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

// ══════════════════════════════════════════════════════════════════════════════
// Setting модель
// ══════════════════════════════════════════════════════════════════════════════

describe('Setting::get()', function () {

    test('возвращает default если ключ не существует', function () {
        expect(Setting::get('NON_EXISTENT_KEY', 999.0))->toBe(999.0);
    });

    test('возвращает значение из БД', function () {
        Setting::create(['key' => 'TEST_KEY', 'value' => '123']);

        expect(Setting::get('TEST_KEY', 0))->toBe('123');
    });

    test('null default когда ключ не найден и default не задан', function () {
        expect(Setting::get('MISSING_KEY'))->toBeNull();
    });

});

describe('Setting::set()', function () {

    test('создаёт новую запись', function () {
        Setting::set('NEW_KEY', '500');

        expect(Setting::where('key', 'NEW_KEY')->value('value'))->toBe('500');
    });

    test('обновляет существующую запись', function () {
        Setting::create(['key' => 'UPDATE_KEY', 'value' => '100']);

        Setting::set('UPDATE_KEY', '200');

        expect(Setting::where('key', 'UPDATE_KEY')->value('value'))->toBe('200');
    });

    test('сбрасывает кеш при обновлении', function () {
        Setting::create(['key' => 'CACHE_KEY', 'value' => '100']);
        Setting::get('CACHE_KEY'); // прогрев кеша

        Setting::set('CACHE_KEY', '999');

        // После set кеш должен быть сброшен — следующий get читает из БД
        expect((string) Setting::get('CACHE_KEY'))->toBe('999');
    });

});
