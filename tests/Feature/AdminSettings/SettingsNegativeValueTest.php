<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\ReceptionTestHelper as H;

beforeEach(fn () => Cache::flush());

function edgingSeedSetting(string $key, string $value, string $label = ''): void
{
    Setting::updateOrCreate(['key' => $key], ['value' => $value, 'label' => $label]);
}

describe('POST /admin/settings — отрицательные значения', function () {

    test('отрицательное значение отклоняется для любого ключа', function (string $key) {
        edgingSeedSetting($key, '1', 'Настройка');

        $this->actingAs(H::adminUser())
            ->post('/admin/settings', [
                'settings' => [
                    ['key' => $key, 'value' => '-1'],
                ],
            ])
            ->assertSessionHasErrors();

        expect(Setting::where('key', $key)->value('value'))->toBe('1');
    })->with(['PIECE_RATE', 'MASTER_BASE_RATE', 'MASTER_ROUND_STEP']);

    test('PIECE_RATE остаётся блокирующим: отрицательное значение даёт ошибку', function () {
        edgingSeedSetting('PIECE_RATE', '390', 'Ставка');

        $this->actingAs(H::adminUser())
            ->post('/admin/settings', [
                'settings' => [
                    ['key' => 'PIECE_RATE', 'value' => '-1'],
                ],
            ])
            ->assertSessionHasErrors();
    });
});
