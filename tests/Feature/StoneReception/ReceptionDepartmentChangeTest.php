<?php

use App\Models\Department;
use App\Models\DepartmentOperationSetting;
use App\Models\StoneReception;
use App\Models\User;
use App\Models\Worker;
use Tests\Helpers\ReceptionTestHelper as H;

/**
 * Мастер с доступом к операции stone-receptions в своём отделе.
 */
function makeMasterForDeptChange(Department $dept): User
{
    DepartmentOperationSetting::updateOrCreate(
        ['department_id' => $dept->id, 'operation_key' => 'stone-receptions'],
        ['enabled' => true, 'config' => ['positions' => ['Мастер']]],
    );
    $dept->forgetOperationsCache();

    $worker = Worker::create([
        'name'          => 'Мастер Смены Отдела',
        'position'      => 'Мастер',
        'department_id' => $dept->id,
    ]);

    return User::factory()->create(['is_admin' => false, 'worker_id' => $worker->id]);
}

function makeReceptionForDeptChange(?Department $dept = null): StoneReception
{
    $store    = H::store();
    $cutter   = H::cutter();
    $receiver = H::worker();
    $rawProd  = H::product();
    $batch    = H::batch($rawProd, $store, $cutter, 50.0);

    return H::reception($batch, $receiver, $cutter, $store, 5.0, [
        'department_id' => $dept?->id,
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// updateDepartment()
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionController updateDepartment()', function () {

    test('админ меняет отдел приёмки', function () {
        $deptA = Department::create(['name' => 'Цех',      'code' => 'TSEH']);
        $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

        $reception = makeReceptionForDeptChange($deptA);

        $this->actingAs(H::adminUser())
            ->patch(route('stone-receptions.update-department', $reception), [
                'department_id' => $deptB->id,
            ])
            ->assertRedirect();

        $reception->refresh();
        expect($reception->department_id)->toBe($deptB->id);
    });

    test('меняет отдел и у неактивной приёмки', function () {
        $deptA = Department::create(['name' => 'Цех',      'code' => 'TSEH']);
        $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

        $reception = makeReceptionForDeptChange($deptA);
        $reception->update(['status' => StoneReception::STATUS_COMPLETED]);

        $this->actingAs(H::adminUser())
            ->patch(route('stone-receptions.update-department', $reception), [
                'department_id' => $deptB->id,
            ])
            ->assertRedirect();

        expect($reception->fresh()->department_id)->toBe($deptB->id);
    });

    test('не-админ получает 403', function () {
        $deptA = Department::create(['name' => 'Цех',      'code' => 'TSEH']);
        $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

        $reception = makeReceptionForDeptChange($deptA);

        $this->actingAs(makeMasterForDeptChange($deptA))
            ->patch(route('stone-receptions.update-department', $reception), [
                'department_id' => $deptB->id,
            ])
            ->assertForbidden();

        expect($reception->fresh()->department_id)->toBe($deptA->id);
    });

    test('отклоняет пустой отдел', function () {
        $deptA     = Department::create(['name' => 'Цех', 'code' => 'TSEH']);
        $reception = makeReceptionForDeptChange($deptA);

        $this->actingAs(H::adminUser())
            ->patch(route('stone-receptions.update-department', $reception), [
                'department_id' => '',
            ])
            ->assertSessionHasErrors(['department_id']);

        expect($reception->fresh()->department_id)->toBe($deptA->id);
    });

    test('отклоняет несуществующий отдел', function () {
        $deptA     = Department::create(['name' => 'Цех', 'code' => 'TSEH']);
        $reception = makeReceptionForDeptChange($deptA);

        $this->actingAs(H::adminUser())
            ->patch(route('stone-receptions.update-department', $reception), [
                'department_id' => 999999,
            ])
            ->assertSessionHasErrors(['department_id']);

        expect($reception->fresh()->department_id)->toBe($deptA->id);
    });

    test('смена отдела приёмки меняет отдел связанной партии сырья', function () {
        $deptA = Department::create(['name' => 'Цех',      'code' => 'TSEH']);
        $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

        $reception = makeReceptionForDeptChange($deptA);
        $reception->rawMaterialBatch->update(['department_id' => $deptA->id]);

        $this->actingAs(H::adminUser())
            ->patch(route('stone-receptions.update-department', $reception), [
                'department_id' => $deptB->id,
            ])
            ->assertRedirect();

        $reception->refresh();
        expect($reception->department_id)->toBe($deptB->id);
        expect($reception->rawMaterialBatch->department_id)->toBe($deptB->id);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// store() — отдел из формы создания ведёт за собой отдел партии
// ══════════════════════════════════════════════════════════════════════════════

describe('Создание приёмки [store()] — выбор отдела', function () {

    test('явный department_id сохраняется в приёмке и меняет отдел партии', function () {
        $deptA = Department::create(['name' => 'Цех',      'code' => 'TSEH']);
        $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

        $store    = H::store();
        $cutter   = H::cutter();
        $cutter->update(['department_id' => $deptA->id]);
        $receiver = H::worker();
        $rawProd  = H::product();
        $batch    = H::batch($rawProd, $store, $cutter, 50.0, ['department_id' => $deptA->id]);

        $this->actingAs(H::adminUser())
            ->post('/stone-receptions', H::receptionPostData($receiver, $cutter, $store, $batch) + [
                'department_id' => $deptB->id,
            ])
            ->assertRedirect();

        $reception = StoneReception::first();
        expect($reception->department_id)->toBe($deptB->id);
        expect($batch->fresh()->department_id)->toBe($deptB->id);
    });

    test('без department_id действует прежний фолбэк, отдел партии не трогается', function () {
        $deptA = Department::create(['name' => 'Цех', 'code' => 'TSEH']);

        $store    = H::store();
        $cutter   = H::cutter();
        $cutter->update(['department_id' => $deptA->id]);
        $receiver = H::worker();
        $rawProd  = H::product();
        $batch    = H::batch($rawProd, $store, $cutter, 50.0, ['department_id' => $deptA->id]);

        $this->actingAs(H::adminUser())
            ->post('/stone-receptions', H::receptionPostData($receiver, $cutter, $store, $batch))
            ->assertRedirect();

        $reception = StoneReception::first();
        expect($reception->department_id)->toBe($deptA->id);
        expect($batch->fresh()->department_id)->toBe($deptA->id);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// create() — список отделов для селекта
// ══════════════════════════════════════════════════════════════════════════════

describe('Форма создания [create()] — селект отдела', function () {

    test('админ видит все активные отделы', function () {
        $deptA = Department::create(['name' => 'Цех',      'code' => 'TSEH']);
        $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

        $this->actingAs(H::adminUser())
            ->get(route('stone-receptions.create'))
            ->assertStatus(200)
            ->assertViewHas('departments', fn($departments) =>
                $departments->pluck('id')->contains($deptA->id)
                && $departments->pluck('id')->contains($deptB->id));
    });

    test('не-админ видит только свои отделы', function () {
        $deptA = Department::create(['name' => 'Цех',      'code' => 'TSEH']);
        $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

        $this->actingAs(makeMasterForDeptChange($deptA))
            ->get(route('stone-receptions.create'))
            ->assertStatus(200)
            ->assertViewHas('departments', fn($departments) =>
                $departments->pluck('id')->all() === [$deptA->id]);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// show() — форма смены отдела только у админа
// ══════════════════════════════════════════════════════════════════════════════

describe('StoneReceptionController show() — блок «Отдел»', function () {

    test('админ видит форму смены отдела', function () {
        $deptA = Department::create(['name' => 'Цех', 'code' => 'TSEH']);
        $reception = makeReceptionForDeptChange($deptA);

        $this->actingAs(H::adminUser())
            ->get(route('stone-receptions.show', $reception))
            ->assertStatus(200)
            ->assertSee(route('stone-receptions.update-department', $reception));
    });

    test('не-админ видит отдел текстом, без формы', function () {
        $deptA = Department::create(['name' => 'Цех', 'code' => 'TSEH']);
        $reception = makeReceptionForDeptChange($deptA);

        $this->actingAs(makeMasterForDeptChange($deptA))
            ->get(route('stone-receptions.show', $reception))
            ->assertStatus(200)
            ->assertDontSee(route('stone-receptions.update-department', $reception))
            ->assertSee('Цех');
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// update() — отдел зафиксирован после создания
// ══════════════════════════════════════════════════════════════════════════════

describe('Редактирование приёмки [update()] — отдел', function () {

    test('не меняет отдел приёмки, даже если у пильщика другой отдел', function () {
        $deptA = Department::create(['name' => 'Цех',      'code' => 'TSEH']);
        $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

        $store    = H::store();
        $cutter   = H::cutter();
        $cutter->update(['department_id' => $deptB->id]);
        $receiver = H::worker();
        $rawProd  = H::product();
        $product  = H::product();
        $batch    = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0, [
            'department_id' => $deptA->id,
        ]);
        $reception->items()->create(['product_id' => $product->id, 'quantity' => 2.0]);

        $this->actingAs(H::adminUser())
            ->put(route('stone-receptions.update', $reception), [
                'receiver_id'           => $receiver->id,
                'cutter_id'             => $cutter->id,
                'store_id'              => $store->id,
                'raw_material_batch_id' => $batch->id,
                'raw_quantity_delta'    => 0,
                'products'              => [
                    ['product_id' => $product->id, 'quantity' => 4.0],
                ],
            ])
            ->assertRedirect(route('stone-receptions.index'));

        $reception->refresh();
        expect((float) $reception->items->first()->quantity)->toBe(4.0);
        expect($reception->department_id)->toBe($deptA->id);
    });

    test('игнорирует department_id, присланный формой редактирования', function () {
        $deptA = Department::create(['name' => 'Цех',      'code' => 'TSEH']);
        $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

        $store    = H::store();
        $cutter   = H::cutter();
        $receiver = H::worker();
        $rawProd  = H::product();
        $product  = H::product();
        $batch    = H::batch($rawProd, $store, $cutter, 50.0);
        $reception = H::reception($batch, $receiver, $cutter, $store, 5.0, [
            'department_id' => $deptA->id,
        ]);
        $reception->items()->create(['product_id' => $product->id, 'quantity' => 2.0]);

        $this->actingAs(H::adminUser())
            ->put(route('stone-receptions.update', $reception), [
                'receiver_id'           => $receiver->id,
                'cutter_id'             => $cutter->id,
                'store_id'              => $store->id,
                'department_id'         => $deptB->id,
                'raw_material_batch_id' => $batch->id,
                'raw_quantity_delta'    => 0,
                'products'              => [
                    ['product_id' => $product->id, 'quantity' => 2.0],
                ],
            ])
            ->assertRedirect(route('stone-receptions.index'));

        expect($reception->fresh()->department_id)->toBe($deptA->id);
    });
});
