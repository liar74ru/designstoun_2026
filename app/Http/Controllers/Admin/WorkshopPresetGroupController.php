<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workshop\WorkshopPresetGroupRequest;
use App\Models\Department;
use App\Models\WorkshopPresetGroup;
use App\Services\WorkshopPresetGroupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class WorkshopPresetGroupController extends Controller
{
    public function __construct(
        private WorkshopPresetGroupService $service,
    ) {}

    public function create(Department $department): View
    {
        return view('admin.departments.preset-groups.create', [
            'department' => $department,
        ]);
    }

    public function store(WorkshopPresetGroupRequest $request, Department $department): RedirectResponse
    {
        $this->service->create($department, $request->validated());

        return redirect()
            ->route('admin.departments.show', $department)
            ->withFragment('workshop')
            ->with('success', 'Группа пресетов создана.');
    }

    public function edit(Department $department, WorkshopPresetGroup $presetGroup): View
    {
        return view('admin.departments.preset-groups.edit', [
            'department' => $department,
            'group'      => $presetGroup,
        ]);
    }

    public function update(
        WorkshopPresetGroupRequest $request,
        Department $department,
        WorkshopPresetGroup $presetGroup,
    ): RedirectResponse {
        $this->service->update($presetGroup, $request->validated());

        return redirect()
            ->route('admin.departments.show', $department)
            ->withFragment('workshop')
            ->with('success', 'Группа пресетов обновлена.');
    }

    public function destroy(Department $department, WorkshopPresetGroup $presetGroup): RedirectResponse
    {
        $this->service->delete($presetGroup);

        return redirect()
            ->route('admin.departments.show', $department)
            ->withFragment('workshop')
            ->with('success', 'Группа удалена, её пресеты остались без группы.');
    }
}
