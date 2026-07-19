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

    public function create(Department $department): View
    {
        return view('admin.departments.presets.create', [
            'department'   => $department,
            'prefillItems' => $this->service->prefillItems(null, $this->oldItemsInput()),
        ]);
    }

    public function store(WorkshopPresetRequest $request, Department $department): RedirectResponse
    {
        $this->service->create($department, $request->validated());

        return redirect()
            ->route('admin.departments.show', $department)
            ->with('success', 'Пресет создан.');
    }

    public function edit(Department $department, WorkshopPreset $preset): View
    {
        return view('admin.departments.presets.edit', [
            'department'   => $department,
            'preset'       => $preset,
            'prefillItems' => $this->service->prefillItems($preset, $this->oldItemsInput()),
        ]);
    }

    public function update(WorkshopPresetRequest $request, Department $department, WorkshopPreset $preset): RedirectResponse
    {
        $this->service->update($preset, $request->validated());

        return redirect()
            ->route('admin.departments.show', $department)
            ->with('success', 'Пресет обновлён.');
    }

    public function destroy(Department $department, WorkshopPreset $preset): RedirectResponse
    {
        $this->service->delete($preset);

        return redirect()
            ->route('admin.departments.show', $department)
            ->with('success', 'Пресет удалён.');
    }

    public function copy(Request $request, Department $department, WorkshopPreset $preset): RedirectResponse
    {
        $validated = $request->validate(
            ['target_department_id' => ['required', 'exists:departments,id']],
            ['target_department_id.required' => 'Выберите отдел для копирования'],
        );

        $target = Department::findOrFail($validated['target_department_id']);
        $this->service->copyToDepartment($preset, $target);

        return redirect()
            ->route('admin.departments.show', $target)
            ->with('success', "Пресет скопирован в отдел «{$target->name}».");
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
