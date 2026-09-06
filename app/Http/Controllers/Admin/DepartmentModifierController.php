<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Department\DepartmentModifierRequest;
use App\Models\Department;
use App\Models\DepartmentModifier;
use App\Services\DepartmentModifierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class DepartmentModifierController extends Controller
{
    public function __construct(
        private DepartmentModifierService $service,
    ) {}

    public function create(Department $department): View
    {
        return view('admin.departments.modifiers.create', [
            'department' => $department,
        ]);
    }

    public function store(DepartmentModifierRequest $request, Department $department): RedirectResponse
    {
        $this->service->create($department, $request->validated());

        return redirect()
            ->route('admin.departments.show', $department)
            ->with('success', 'Правило создано.');
    }

    public function edit(Department $department, DepartmentModifier $modifier): View
    {
        return view('admin.departments.modifiers.edit', [
            'department' => $department,
            'modifier'   => $modifier,
        ]);
    }

    public function update(
        DepartmentModifierRequest $request,
        Department $department,
        DepartmentModifier $modifier,
    ): RedirectResponse {
        $this->service->update($modifier, $request->validated());

        return redirect()
            ->route('admin.departments.show', $department)
            ->with('success', 'Правило обновлено.');
    }

    public function destroy(Department $department, DepartmentModifier $modifier): RedirectResponse
    {
        $this->service->delete($modifier);

        return redirect()
            ->route('admin.departments.show', $department)
            ->with('success', 'Правило удалено.');
    }
}
