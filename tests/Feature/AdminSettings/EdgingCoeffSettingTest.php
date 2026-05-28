<?php

use App\Models\Setting;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

function makeEdgingAdminUser(): User
{
    return User::factory()->create(['is_admin' => true, 'worker_id' => null]);
}

function edgingSeedSetting(string $key, string $value, string $label = ''): void
{
    Setting::updateOrCreate(['key' => $key], ['value' => $value, 'label' => $label]);
}

describe('POST /admin/settings — валидация EDGING_COEFF (whitelist отрицательных)', function () {

    test('администратор сохраняет отрицательное значение EDGING_COEFF', function () {
        edgingSeedSetting('EDGING_COEFF', '-2.5', 'Торцовка');

        $this->actingAs(makeEdgingAdminUser())
            ->post('/admin/settings', [
                'settings' => [
                    ['key' => 'EDGING_COEFF', 'value' => '-3.5'],
                ],
            ])
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHasNoErrors();

        expect(Setting::where('key', 'EDGING_COEFF')->value('value'))->toBe('-3.5');
    });

    test('UNDERCUT_PENALTY с отрицательным значением по-прежнему даёт ошибку (whitelist точечный)', function () {
        edgingSeedSetting('UNDERCUT_PENALTY', '1.5', 'Подкол');

        $this->actingAs(makeEdgingAdminUser())
            ->post('/admin/settings', [
                'settings' => [
                    ['key' => 'UNDERCUT_PENALTY', 'value' => '-1'],
                ],
            ])
            ->assertSessionHasErrors();
    });

    test('EDGING_COEFF принимает положительное значение тоже', function () {
        edgingSeedSetting('EDGING_COEFF', '-2.5', 'Торцовка');

        $this->actingAs(makeEdgingAdminUser())
            ->post('/admin/settings', [
                'settings' => [
                    ['key' => 'EDGING_COEFF', 'value' => '0.5'],
                ],
            ])
            ->assertSessionHasNoErrors();

        expect(Setting::where('key', 'EDGING_COEFF')->value('value'))->toBe('0.5');
    });

    test('смешанный пакет: EDGING_COEFF=-2.5 и PIECE_RATE=400 — обе сохраняются', function () {
        edgingSeedSetting('EDGING_COEFF', '-2.5', 'Торцовка');
        edgingSeedSetting('PIECE_RATE',   '390',  'Ставка');

        $this->actingAs(makeEdgingAdminUser())
            ->post('/admin/settings', [
                'settings' => [
                    ['key' => 'EDGING_COEFF', 'value' => '-2.5'],
                    ['key' => 'PIECE_RATE',   'value' => '400'],
                ],
            ])
            ->assertSessionHasNoErrors();

        expect(Setting::where('key', 'EDGING_COEFF')->value('value'))->toBe('-2.5');
        expect(Setting::where('key', 'PIECE_RATE')->value('value'))->toBe('400');
    });

    test('PIECE_RATE остаётся блокирующим: отрицательное значение даёт ошибку', function () {
        edgingSeedSetting('PIECE_RATE', '390', 'Ставка');

        $this->actingAs(makeEdgingAdminUser())
            ->post('/admin/settings', [
                'settings' => [
                    ['key' => 'PIECE_RATE', 'value' => '-1'],
                ],
            ])
            ->assertSessionHasErrors();
    });
});

describe('GET /admin/settings — отображение поля EDGING_COEFF', function () {

    test('поле «Торцовка» с текущим значением видно в блоке «Расчёт зарплаты»', function () {
        edgingSeedSetting('PIECE_RATE',       '390',  'Ставка пильщика');
        edgingSeedSetting('UNDERCUT_PENALTY', '1.5',  'Штраф подкол > 80%');
        edgingSeedSetting('EDGING_COEFF',     '-2.5', 'Коэффициент «Торцовка»');

        $this->actingAs(makeEdgingAdminUser())
            ->get('/admin/settings')
            ->assertOk()
            ->assertSee('Коэффициент «Торцовка»', false)
            ->assertSee('-2.5');
    });
});
