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

    // Отрицательным мог быть только EDGING_COEFF, он больше не участвует в расчётах
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
    })->with(['EDGING_COEFF', 'UNDERCUT_PENALTY', 'PIECE_RATE']);

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

describe('GET /admin/settings — неиспользуемые коэффициенты не показываются', function () {

    // Надбавки за подкол, торцовку и плитку-маску задаются правилами отдела; общие
    // коэффициенты в расчёте не участвуют, и поле на странице выглядело бы рабочим.
    test('на странице ставок нет неиспользуемых коэффициентов', function () {
        edgingSeedSetting('PIECE_RATE',            '390',  'Ставка пильщика');
        edgingSeedSetting('UNDERCUT_PENALTY',      '1.5',  'Штраф подкол > 80%');
        edgingSeedSetting('EDGING_COEFF',          '-2.5', 'Коэффициент «Торцовка»');
        edgingSeedSetting('MASK_TILE_COEFF_BONUS', '2',    'Бонус плитки-маски');
        edgingSeedSetting('MASTER_UNDERCUT_RATE',  '50',   'Надбавка мастеру за подкол');

        $this->actingAs(H::adminUser())
            ->get(route('admin.settings.rates'))
            ->assertOk()
            ->assertSee('Базовая ставка')
            ->assertDontSee('Штраф подкол > 80%', false)
            ->assertDontSee('Коэффициент «Торцовка»', false)
            ->assertDontSee('Бонус плитки-маски')
            ->assertDontSee('Надбавка мастеру за подкол');
    });
});
