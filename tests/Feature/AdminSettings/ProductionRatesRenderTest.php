<?php

use App\Models\Department;
use App\Models\DepartmentSetting;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workshop;
use App\Models\WorkshopItem;
use App\Models\Worker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Helpers\ReceptionTestHelper as H;

/**
 * Дымовые тесты на партиал partials/production-rates-js.blade.php.
 *
 * Он делает запрос к БД и отдаёт ставки в JS, подключён в пяти формах.
 * Формы цеха другими тестами не рендерятся, поэтому ошибка в партиале
 * всплыла бы только в браузере.
 */

beforeEach(fn () => Cache::flush());

function ratesAdmin(): User
{
    return User::factory()->create(['is_admin' => true, 'worker_id' => null]);
}

function ratesWorkshop(?Department $dept = null): Workshop
{
    $worker = Worker::create(['name' => 'Упаковщик', 'position' => 'Мастер']);

    $workshop = Workshop::create([
        'packer_id'     => $worker->id,
        'receiver_id'   => $worker->id,
        'store_id'      => H::store('Склад цеха')->id,
        'department_id' => $dept?->id,
        'status'        => Workshop::STATUS_ACTIVE,
    ]);

    WorkshopItem::create([
        'workshop_id' => $workshop->id,
        'product_id'  => Product::factory()->create(['moysklad_id' => (string) Str::uuid()])->id,
        'role'        => WorkshopItem::ROLE_PRODUCT,
        'quantity'    => 1.0,
    ]);

    return $workshop;
}

test('форма создания операции цеха рендерится и отдаёт ставки в JS', function () {
    $this->actingAs(ratesAdmin())
        ->get(route('workshops.create'))
        ->assertOk()
        ->assertSee('window.ProductionRates', false)
        ->assertSee('MASK_TILE_COEFF_BONUS', false);
});

test('страница операции цеха рендерится со ставками', function () {
    $this->actingAs(ratesAdmin())
        ->get(route('workshops.show', ratesWorkshop()))
        ->assertOk()
        ->assertSee('window.ProductionRates', false);
});

test('в JS уходит ставка отдела, а не только глобальная', function () {
    $dept = Department::create(['name' => 'Резка', 'is_active' => true]);
    Setting::set('PIECE_RATE', '390');
    DepartmentSetting::create([
        'department_id' => $dept->id,
        'key'           => 'PIECE_RATE',
        'value'         => '420',
    ]);
    $dept->forgetSettingsCache();

    $this->actingAs(ratesAdmin())
        ->get(route('workshops.create'))
        ->assertOk()
        ->assertSee('"' . $dept->id . '":420', false);
});

test('коэффициенты приходят актуальными после правки настройки', function () {
    Setting::set('UNDERCUT_PENALTY', '7.25');

    $this->actingAs(ratesAdmin())
        ->get(route('workshops.create'))
        ->assertOk()
        ->assertSee('7.25', false);
});
