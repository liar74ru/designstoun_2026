<?php

use App\Models\Department;
use App\Models\DepartmentOperationSetting;
use App\Models\RawMaterialBatch;
use App\Models\User;
use App\Models\Worker;
use Tests\Helpers\ReceptionTestHelper as H;

/**
 * Мастер с доступом к операции raw-batches в своём отделе.
 */
function makeMasterForBatchDeptChange(Department $dept): User
{
    DepartmentOperationSetting::updateOrCreate(
        ['department_id' => $dept->id, 'operation_key' => 'raw-batches'],
        ['enabled' => true, 'config' => ['positions' => ['Мастер']]],
    );
    $dept->forgetOperationsCache();

    $worker = Worker::create([
        'name'          => 'Мастер Смены Отдела Партии',
        'position'      => 'Мастер',
        'department_id' => $dept->id,
    ]);

    return User::factory()->create(['is_admin' => false, 'worker_id' => $worker->id]);
}

function makeBatchForDeptChange(?Department $dept = null): RawMaterialBatch
{
    $product = H::product();
    $store   = H::store();
    $cutter  = H::cutter();

    return H::batch($product, $store, $cutter, 25.0, [
        'department_id' => $dept?->id,
    ]);
}

function batchStorePostData(Worker $worker, array $extra = []): array
{
    $product   = H::product();
    $fromStore = H::store('Склад-источник');
    $toStore   = H::store('Склад-цех');
    H::stock($product, $fromStore, 50.0);

    return $extra + [
        'product_id'    => $product->id,
        'quantity'      => 10.0,
        'worker_id'     => $worker->id,
        'from_store_id' => $fromStore->id,
        'to_store_id'   => $toStore->id,
    ];
}

// ══════════════════════════════════════════════════════════════════════════════
// store() — партия без отдела не создаётся
// ══════════════════════════════════════════════════════════════════════════════

describe('Создание партии [store()] — обязательный отдел', function () {

    test('работник без отдела и пустой селект → ошибка, партия не создана', function () {
        $worker = H::cutter();

        $this->actingAs(H::adminUser())
            ->post('/raw-batches', batchStorePostData($worker))
            ->assertSessionHasErrors(['department_id']);

        expect(RawMaterialBatch::count())->toBe(0);
    });

    test('без явного отдела партия получает отдел работника (фолбэк)', function () {
        $dept   = Department::create(['name' => 'Цех', 'code' => 'TSEH']);
        $worker = H::cutter();
        $worker->update(['department_id' => $dept->id]);

        $this->actingAs(H::adminUser())
            ->post('/raw-batches', batchStorePostData($worker))
            ->assertRedirect(route('raw-batches.index'));

        expect(RawMaterialBatch::first()->department_id)->toBe($dept->id);
    });

    test('явный отдел имеет приоритет над отделом работника', function () {
        $deptA  = Department::create(['name' => 'Цех',      'code' => 'TSEH']);
        $deptB  = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);
        $worker = H::cutter();
        $worker->update(['department_id' => $deptA->id]);

        $this->actingAs(H::adminUser())
            ->post('/raw-batches', batchStorePostData($worker, ['department_id' => $deptB->id]))
            ->assertRedirect(route('raw-batches.index'));

        expect(RawMaterialBatch::first()->department_id)->toBe($deptB->id);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// updateDepartment() — смена отдела партии на show-странице
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchController updateDepartment()', function () {

    test('админ меняет отдел партии', function () {
        $deptA = Department::create(['name' => 'Цех',      'code' => 'TSEH']);
        $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

        $batch = makeBatchForDeptChange($deptA);

        $this->actingAs(H::adminUser())
            ->patch(route('raw-batches.update-department', $batch), [
                'department_id' => $deptB->id,
            ])
            ->assertRedirect();

        expect($batch->fresh()->department_id)->toBe($deptB->id);
    });

    test('админ задаёт отдел партии без отдела', function () {
        $dept  = Department::create(['name' => 'Цех', 'code' => 'TSEH']);
        $batch = makeBatchForDeptChange(null);

        $this->actingAs(H::adminUser())
            ->patch(route('raw-batches.update-department', $batch), [
                'department_id' => $dept->id,
            ])
            ->assertRedirect();

        expect($batch->fresh()->department_id)->toBe($dept->id);
    });

    test('не-админ получает 403', function () {
        $deptA = Department::create(['name' => 'Цех',      'code' => 'TSEH']);
        $deptB = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);

        $batch = makeBatchForDeptChange($deptA);

        $this->actingAs(makeMasterForBatchDeptChange($deptA))
            ->patch(route('raw-batches.update-department', $batch), [
                'department_id' => $deptB->id,
            ])
            ->assertForbidden();

        expect($batch->fresh()->department_id)->toBe($deptA->id);
    });

    test('отклоняет пустой и несуществующий отдел', function () {
        $deptA = Department::create(['name' => 'Цех', 'code' => 'TSEH']);
        $batch = makeBatchForDeptChange($deptA);

        $this->actingAs(H::adminUser())
            ->patch(route('raw-batches.update-department', $batch), ['department_id' => ''])
            ->assertSessionHasErrors(['department_id']);

        $this->actingAs(H::adminUser())
            ->patch(route('raw-batches.update-department', $batch), ['department_id' => 999999])
            ->assertSessionHasErrors(['department_id']);

        expect($batch->fresh()->department_id)->toBe($deptA->id);
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// show() — форма смены отдела только у админа
// ══════════════════════════════════════════════════════════════════════════════

describe('RawMaterialBatchController show() — строка «Отдел»', function () {

    test('админ видит форму смены отдела', function () {
        $deptA = Department::create(['name' => 'Цех', 'code' => 'TSEH']);
        $batch = makeBatchForDeptChange($deptA);

        $this->actingAs(H::adminUser())
            ->get(route('raw-batches.show', $batch))
            ->assertStatus(200)
            ->assertSee(route('raw-batches.update-department', $batch));
    });

    test('не-админ видит отдел текстом, без формы', function () {
        $deptA = Department::create(['name' => 'ЦехОсобый', 'code' => 'TSEH']);
        $batch = makeBatchForDeptChange($deptA);

        $this->actingAs(makeMasterForBatchDeptChange($deptA))
            ->get(route('raw-batches.show', $batch))
            ->assertStatus(200)
            ->assertDontSee(route('raw-batches.update-department', $batch))
            ->assertSee('ЦехОсобый');
    });
});
