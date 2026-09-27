<?php

use App\Models\Counterparty;
use App\Models\Department;
use App\Models\Product;
use App\Models\RawMaterialBatch;
use App\Models\StoneReception;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Models\Worker;
use App\Models\Workshop;
use App\Support\DepartmentAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

/**
 * Чужие записи смотреть можно, менять нельзя.
 *
 * В списках мастер выбирает чужой отдел фильтром и открывает карточки — это оставлено.
 * Изменяющие действия закрыты политиками `modify` (App\Support\DepartmentAccess):
 * запись должна относиться к одному из отделов пользователя (основной + pivot).
 */

/** Модуль → ключ операции реестра, под которой мастеру открыт раздел. */
const RDA_OPERATIONS = [
    'raw-batches'      => 'raw-batches',
    'stone-receptions' => 'stone-receptions',
    'workshops'        => 'workshops',
    'supplier-orders'  => 'supplier-orders',
    'workers'          => 'workers',
];

/** Запись модуля в отделе (null — без своего отдела). */
function rdaRecord(string $module, ?Department $dept): Model
{
    $store = H::store();

    return match ($module) {
        'raw-batches' => H::batch(H::product(), $store, H::cutter(), 25.0, ['department_id' => $dept?->id]),

        'stone-receptions' => H::reception(
            H::batch(H::product(), $store, H::cutter(), 25.0),
            H::worker(),
            H::cutter(),
            $store,
            1.0,
            ['department_id' => $dept?->id],
        ),

        'workshops' => Workshop::create([
            'store_id'      => $store->id,
            'packer_id'     => H::worker('Упаковщик')->id,
            'receiver_id'   => H::worker('Приёмщик')->id,
            'status'        => 'active',
            'department_id' => $dept?->id,
        ]),

        'supplier-orders' => SupplierOrder::create([
            'number'          => 'SO-' . uniqid(),
            'store_id'        => $store->id,
            'counterparty_id' => Counterparty::create(['name' => 'Поставщик', 'moysklad_id' => uniqid()])->id,
            'department_id'   => $dept?->id,
            'status'          => SupplierOrder::STATUS_NEW,
        ]),

        'workers' => Worker::create([
            'name'          => 'Работник ' . uniqid(),
            'position'      => 'Работник',
            'department_id' => $dept?->id,
        ]),
    };
}

/** Запрос к именованному маршруту записи — методом, под которым маршрут зарегистрирован. */
function rdaCall(string $routeName, Model $record)
{
    $method = collect(Route::getRoutes()->getByName($routeName)->methods())
        ->reject(fn ($m) => $m === 'HEAD')
        ->first();

    return test()->call($method, route($routeName, $record));
}

function rdaModule(string $routeName): string
{
    return explode('.', $routeName)[0];
}

// ══════════════════════════════════════════════════════════════════════════════
// Изменяющие действия над чужой записью — 403
// ══════════════════════════════════════════════════════════════════════════════

const RDA_MUTATING = [
    'raw-batches.edit', 'raw-batches.update', 'raw-batches.destroy', 'raw-batches.destroy-new',
    'raw-batches.adjust.form', 'raw-batches.adjust', 'raw-batches.transfer.form', 'raw-batches.transfer',
    'raw-batches.return.form', 'raw-batches.return', 'raw-batches.archive', 'raw-batches.mark-used',
    'raw-batches.mark-in-work', 'raw-batches.sync',

    'stone-receptions.edit', 'stone-receptions.update', 'stone-receptions.destroy', 'stone-receptions.sync',
    'stone-receptions.reset-status', 'stone-receptions.mark-completed', 'stone-receptions.update-store',
    'stone-receptions.update-item-coeff', 'stone-receptions.refresh-item-coeffs',

    'workshops.edit', 'workshops.update', 'workshops.destroy', 'workshops.sync', 'workshops.reset-status',
    'workshops.mark-completed', 'workshops.update-item-coeff', 'workshops.refresh-item-coeffs',
    'workshops.update-stores',

    'supplier-orders.edit', 'supplier-orders.update', 'supplier-orders.destroy', 'supplier-orders.sync',
    'supplier-orders.sync-confirm', 'supplier-orders.force-sync',

    'workers.edit', 'workers.update', 'workers.destroy', 'workers.archive', 'workers.restore',
    'workers.create-user', 'workers.store-user',
];

dataset('rda_mutating_routes', RDA_MUTATING);

describe('Изменение чужой записи', function () {

    test('мастер другого отдела получает 403', function (string $routeName) {
        $module = rdaModule($routeName);
        $own    = Access::department('Свой');
        $record = rdaRecord($module, Access::department('Чужой'));

        $this->actingAs(Access::master($own, RDA_OPERATIONS[$module]));

        rdaCall($routeName, $record)->assertForbidden();

        expect($record->fresh())->not->toBeNull();
    })->with('rda_mutating_routes');

    test('запрет не меняет запись: чужой работник не архивируется', function () {
        $worker = rdaRecord('workers', Access::department('Чужой'));

        $this->actingAs(Access::master(Access::department('Свой'), 'workers'))
            ->patch(route('workers.archive', $worker))
            ->assertForbidden();

        expect($worker->fresh()->isArchived())->toBeFalse();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Смотреть чужое можно
// ══════════════════════════════════════════════════════════════════════════════

describe('Просмотр чужой записи', function () {

    test('карточка записи другого отдела открывается', function (string $module) {
        $record = rdaRecord($module, Access::department('Чужой'));

        $this->actingAs(Access::master(Access::department('Свой'), RDA_OPERATIONS[$module]))
            ->get(route("{$module}.show", $record))
            ->assertOk();
    })->with(['raw-batches', 'stone-receptions', 'workshops', 'supplier-orders']);
});

/** Адреса всех изменяющих действий записи — для проверки кнопок на карточке. */
function rdaMutatingUrls(string $module, Model $record): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with((string) $r->getName(), "{$module}."))
        ->filter(fn ($r) => in_array($r->getName(), RDA_MUTATING, true))
        ->map(fn ($r) => route($r->getName(), $record))
        ->unique()
        ->values()
        ->all();
}

describe('Кнопки изменения на карточке', function () {

    test('на чужой карточке их нет, на своей есть', function (string $module) {
        $own     = Access::department('Свой');
        $master  = Access::master($own, RDA_OPERATIONS[$module]);
        $foreign = rdaRecord($module, Access::department('Чужой'));
        $mine    = rdaRecord($module, $own);

        $foreignHtml = $this->actingAs($master)->get(route("{$module}.show", $foreign))->assertOk()->getContent();
        $mineHtml    = $this->actingAs($master)->get(route("{$module}.show", $mine))->assertOk()->getContent();

        foreach (rdaMutatingUrls($module, $foreign) as $url) {
            expect($foreignHtml)->not->toContain('"' . $url . '"');
        }

        $shown = collect(rdaMutatingUrls($module, $mine))
            ->filter(fn ($url) => str_contains($mineHtml, '"' . $url . '"'));
        expect($shown)->not->toBeEmpty();
    })->with(['raw-batches', 'stone-receptions', 'workshops', 'supplier-orders']);
});

// ══════════════════════════════════════════════════════════════════════════════
// Кому можно
// ══════════════════════════════════════════════════════════════════════════════

describe('Разрешённые изменения', function () {

    test('мастер своего отдела проходит проверку', function (string $module) {
        $dept   = Access::department('Свой');
        $record = rdaRecord($module, $dept);

        $this->actingAs(Access::master($dept, RDA_OPERATIONS[$module]));

        expect(rdaCall("{$module}.edit", $record)->status())->not->toBe(403);
    })->with(array_keys(RDA_OPERATIONS));

    test('админ меняет запись любого отдела', function (string $module) {
        $record = rdaRecord($module, Access::department('Чужой'));

        $this->actingAs(H::adminUser());

        expect(rdaCall("{$module}.edit", $record)->status())->not->toBe(403);
    })->with(array_keys(RDA_OPERATIONS));

    test('запись без отдела и без цепочки доступна мастеру', function (string $module) {
        $record = rdaRecord($module, null);

        $this->actingAs(Access::master(Access::department('Свой'), RDA_OPERATIONS[$module]));

        expect(rdaCall("{$module}.edit", $record)->status())->not->toBe(403);
    })->with(['raw-batches', 'workshops', 'supplier-orders', 'workers']);
});

// ══════════════════════════════════════════════════════════════════════════════
// Отдел по цепочке
// ══════════════════════════════════════════════════════════════════════════════

describe('Отдел записи по цепочке', function () {

    test('приёмка без своего отдела относится к отделу партии', function () {
        $deptA = Access::department('Отдел партии');
        $deptB = Access::department('Другой');
        $store = H::store();

        $reception = H::reception(
            H::batch(H::product(), $store, H::cutter(), 25.0, ['department_id' => $deptA->id]),
            H::worker(),
            H::cutter(),
            $store,
            1.0,
            ['department_id' => null],
        );

        $this->actingAs(Access::master($deptA, 'stone-receptions'))
            ->get(route('stone-receptions.edit', $reception))
            ->assertOk();

        $this->actingAs(Access::master($deptB, 'stone-receptions'))
            ->get(route('stone-receptions.edit', $reception))
            ->assertForbidden();
    });

    test('цех без своего отдела относится к отделу упаковщика', function () {
        $deptA  = Access::department('Отдел упаковщика');
        $packer = Worker::create(['name' => 'Упаковщик', 'position' => 'Работник', 'department_id' => $deptA->id]);

        $workshop = Workshop::create([
            'store_id'    => H::store()->id,
            'packer_id'   => $packer->id,
            'receiver_id' => H::worker()->id,
            'status'      => 'active',
        ]);

        $this->actingAs(Access::master(Access::department('Другой'), 'workshops'))
            ->get(route('workshops.edit', $workshop))
            ->assertForbidden();
    });

    test('работник из pivot отдела мастера — мастер его архивирует', function () {
        $deptA  = Access::department('Отдел мастера');
        $worker = rdaRecord('workers', Access::department('Основной отдел работника'));
        $worker->departments()->syncWithoutDetaching([$deptA->id]);

        $this->actingAs(Access::master($deptA, 'workers'))
            ->patch(route('workers.archive', $worker))
            ->assertRedirect();

        expect($worker->fresh()->isArchived())->toBeTrue();
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// DepartmentAccess
// ══════════════════════════════════════════════════════════════════════════════

describe('DepartmentAccess', function () {

    test('правило для админа, своего, чужого и неопределённого отдела', function () {
        $own    = Access::department('Свой');
        $other  = Access::department('Чужой');
        $master = Access::master($own, 'raw-batches');
        $admin  = H::adminUser();

        expect(DepartmentAccess::allows($admin, $other->id))->toBeTrue()
            ->and(DepartmentAccess::allows($master, $own->id))->toBeTrue()
            ->and(DepartmentAccess::allows($master, (string) $own->id))->toBeTrue()
            ->and(DepartmentAccess::allows($master, $other->id))->toBeFalse()
            ->and(DepartmentAccess::allows($master, null))->toBeTrue()
            ->and(DepartmentAccess::allowsAny($master, [$other->id, $own->id]))->toBeTrue()
            ->and(DepartmentAccess::allowsAny($master, [$other->id]))->toBeFalse()
            ->and(DepartmentAccess::allowsAny($master, []))->toBeTrue();
    });

    test('мастер без отдела не может менять запись с отделом', function () {
        expect(DepartmentAccess::allows(Access::masterWithoutDept(), Access::department()->id))->toBeFalse();
    });
});
