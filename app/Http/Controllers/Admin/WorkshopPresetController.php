<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workshop\WorkshopPresetRequest;
use App\Models\Department;
use App\Models\WorkshopPreset;
use App\Services\WorkshopPresetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkshopPresetController extends Controller
{
    public function __construct(
        private WorkshopPresetService $service,
    ) {}

    public function create(Request $request, Department $department): View
    {
        return view('admin.departments.presets.create', [
            'department'     => $department,
            'groups'         => $department->presetGroups,
            // «+ Пресет» из карточки группы: группа подставляется, если она этого отдела.
            'defaultGroupId' => $department->presetGroups->firstWhere('id', $request->integer('group'))?->id,
            'allDepartments' => $this->activeDepartments(),
            'prefillItems'   => $this->service->prefillItems(null, $this->oldItemsInput()),
        ]);
    }

    public function store(WorkshopPresetRequest $request, Department $department): RedirectResponse
    {
        $this->service->create($department, $request->validated());

        return redirect()
            ->route('admin.departments.show', $department)
            ->withFragment('workshop')
            ->with('success', 'Пресет создан.');
    }

    public function edit(Department $department, WorkshopPreset $preset): View
    {
        return view('admin.departments.presets.edit', [
            'department'     => $department,
            'preset'         => $preset->load('department', 'departments'),
            'groups'         => $department->presetGroups,
            'allDepartments' => $this->activeDepartments(),
            'prefillItems'   => $this->service->prefillItems($preset, $this->oldItemsInput()),
        ]);
    }

    public function update(WorkshopPresetRequest $request, Department $department, WorkshopPreset $preset): RedirectResponse
    {
        $this->service->update($department, $preset, $request->validated());

        return redirect()
            ->route('admin.departments.show', $department)
            ->withFragment('workshop')
            ->with('success', 'Пресет обновлён.');
    }

    public function destroy(Department $department, WorkshopPreset $preset): RedirectResponse
    {
        // Удаление убирает пресет из всех отделов — только из основного, в остальных — «убрать».
        if ($preset->department_id !== $department->id) {
            return redirect()
                ->route('admin.departments.show', $department)
                ->withFragment('workshop')
                ->with('error', 'Удалить пресет можно только в его основном отделе, здесь его можно убрать из отдела.');
        }

        $this->service->delete($preset);

        return redirect()
            ->route('admin.departments.show', $department)
            ->withFragment('workshop')
            ->with('success', 'Пресет удалён.');
    }

    public function detach(Department $department, WorkshopPreset $preset): RedirectResponse
    {
        if ($preset->department_id === $department->id) {
            return redirect()
                ->route('admin.departments.show', $department)
                ->withFragment('workshop')
                ->with('error', 'Это основной отдел пресета: его можно только удалить.');
        }

        $this->service->detach($preset, $department);

        return redirect()
            ->route('admin.departments.show', $department)
            ->withFragment('workshop')
            ->with('success', "Пресет «{$preset->name}» убран из отдела.");
    }

    public function copy(Request $request, Department $department, WorkshopPreset $preset): RedirectResponse
    {
        $validated = $request->validate(
            ['target_department_id' => ['required', 'integer', 'exists:departments,id']],
            ['target_department_id.required' => 'Выберите отдел для копирования'],
        );

        $target = Department::findOrFail($validated['target_department_id']);
        $this->service->copyToDepartment($preset, $department, $target);

        return redirect()
            ->route('admin.departments.show', $target)
            ->withFragment('workshop')
            ->with('success', "Пресет скопирован в отдел «{$target->name}».");
    }

    private function activeDepartments()
    {
        return Department::where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * old-инпут строк формы для восстановления после ошибки валидации (null — ошибок не было).
     */
    private function oldItemsInput(): ?array
    {
        if (!session()->hasOldInput()) {
            return null;
        }

        return [
            'raw_materials' => old('raw_materials', []),
            'packages'      => old('packages', []),
            'products'      => old('products', []),
        ];
    }
}
