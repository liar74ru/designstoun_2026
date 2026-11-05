<?php

use App\Models\Department;
use App\Models\Workshop;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

function makeWorkshopForDeptChange(?Department $dept = null): Workshop
{
    return Workshop::create([
        'packer_id'     => H::worker('Упаковщиков Павел')->id,
        'receiver_id'   => H::worker('Приёмщиков Иван')->id,
        'store_id'      => H::store()->id,
        'department_id' => $dept?->id,
        'status'        => Workshop::STATUS_ACTIVE,
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// updateDepartment()
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkshopController updateDepartment()', function () {

    test('админ меняет отдел операции', function () {
        $deptA    = Department::create(['name' => 'Цех',      'code' => 'TSEH']);
        $deptB    = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);
        $workshop = makeWorkshopForDeptChange($deptA);

        $this->actingAs(H::adminUser())
            ->patch(route('workshops.update-department', $workshop), ['department_id' => $deptB->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        expect($workshop->fresh()->department_id)->toBe($deptB->id);
    });

    test('меняет отдел и у закрытой операции', function () {
        $deptA    = Department::create(['name' => 'Цех',      'code' => 'TSEH']);
        $deptB    = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);
        $workshop = makeWorkshopForDeptChange($deptA);
        $workshop->update(['status' => Workshop::STATUS_COMPLETED]);

        $this->actingAs(H::adminUser())
            ->patch(route('workshops.update-department', $workshop), ['department_id' => $deptB->id])
            ->assertRedirect();

        expect($workshop->fresh()->department_id)->toBe($deptB->id);
    });

    test('операция без отдела получает отдел', function () {
        $dept     = Department::create(['name' => 'Цех', 'code' => 'TSEH']);
        $workshop = makeWorkshopForDeptChange();

        $this->actingAs(H::adminUser())
            ->patch(route('workshops.update-department', $workshop), ['department_id' => $dept->id])
            ->assertRedirect();

        expect($workshop->fresh()->department_id)->toBe($dept->id);
    });

    test('не-админ получает 403', function () {
        $deptA    = Department::create(['name' => 'Цех',      'code' => 'TSEH']);
        $deptB    = Department::create(['name' => 'Галтовка', 'code' => 'GALT']);
        $workshop = makeWorkshopForDeptChange($deptA);

        $this->actingAs(Access::master($deptA, 'workshops'))
            ->patch(route('workshops.update-department', $workshop), ['department_id' => $deptB->id])
            ->assertForbidden();

        expect($workshop->fresh()->department_id)->toBe($deptA->id);
    });

    test('отклоняет неверный отдел', function (mixed $value) {
        $dept     = Department::create(['name' => 'Цех', 'code' => 'TSEH']);
        $workshop = makeWorkshopForDeptChange($dept);

        $this->actingAs(H::adminUser())
            ->patch(route('workshops.update-department', $workshop), ['department_id' => $value])
            ->assertSessionHasErrors(['department_id']);

        expect($workshop->fresh()->department_id)->toBe($dept->id);
    })->with([
        'пустой'         => [''],
        'несуществующий' => [999999],
        'текст'          => ['abc'],
    ]);
});
