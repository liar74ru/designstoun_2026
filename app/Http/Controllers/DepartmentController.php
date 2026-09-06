<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\DepartmentOperationSetting;
use App\Models\DepartmentSetting;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Worker;
use App\Services\DepartmentExpenseService;
use App\Services\WorkshopPresetService;
use App\Support\DepartmentSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function create()
    {
        return view('admin.departments.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:100', 'unique:departments,name'],
            'code'        => ['nullable', 'string', 'max:50', 'unique:departments,code'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        Department::create(array_merge($validated, ['is_active' => true]));

        return redirect()
            ->route('admin.settings.index')
            ->with('success', 'Отдел успешно создан.');
    }

    public function show(Department $department, WorkshopPresetService $presetService)
    {
        $workers           = $department->allWorkers()->orderBy('name')->get();
        $stores            = Store::where('archived', false)->orderBy('name')->get();
        $allWorkers        = Worker::orderBy('name')->get();
        $operations        = config('department_operations');
        $allowedPositions  = $department->allowedPositionsByOperation();
        $presets           = $presetService->getForDepartment($department);
        $allDepartments    = Department::where('is_active', true)->orderBy('name')->get();
        $costGroups        = config('department_settings');
        $costSettings      = $department->settings()->pluck('value', 'key')->all();
        $globalDefaults    = Setting::whereIn('key', DepartmentSettings::keys())->pluck('value', 'key')->all();
        $expenses          = $department->expenses()->get(['id', 'name', 'amount']);
        $modifiers         = $department->modifiers;

        return view('admin.departments.show', compact(
            'department', 'workers', 'stores', 'allWorkers', 'operations', 'allowedPositions',
            'presets', 'allDepartments', 'costGroups', 'costSettings', 'globalDefaults', 'expenses',
            'modifiers'
        ));
    }

    /**
     * Сохранить список накладных расходов отдела (полная замена).
     */
    public function updateExpenses(
        Request $request,
        Department $department,
        DepartmentExpenseService $service
    ) {
        $request->validate([
            'expenses'          => ['array'],
            'expenses.*.name'   => ['nullable', 'string', 'max:100'],
            'expenses.*.amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $service->sync($department, $request->input('expenses', []));

        return redirect()
            ->route('admin.departments.show', $department)
            ->with('success', 'Накладные расходы отдела обновлены.');
    }

    /**
     * Сохранить переопределения ставок мастера для отдела.
     * Пустое поле — удаление строки, значение наследуется от глобальной настройки.
     */
    public function updateCostSettings(Request $request, Department $department)
    {
        $request->validate([
            'settings'   => ['array'],
            'settings.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $payload = $request->input('settings', []);

        foreach (DepartmentSettings::keys() as $key) {
            $value = $payload[$key] ?? null;

            if ($value === null || $value === '') {
                DepartmentSetting::where('department_id', $department->id)
                    ->where('key', $key)
                    ->delete();
                continue;
            }

            DepartmentSetting::updateOrCreate(
                ['department_id' => $department->id, 'key' => $key],
                ['value' => (string) $value],
            );
        }

        $department->forgetSettingsCache();

        return redirect()
            ->route('admin.departments.show', $department)
            ->with('success', 'Ставки мастера обновлены.');
    }

    public function updateOperations(Request $request, Department $department)
    {
        $request->validate([
            'operations'                 => ['array'],
            'operations.*.positions'     => ['array'],
            'operations.*.positions.*'   => ['nullable', 'string', Rule::in(Worker::POSITIONS)],
        ]);

        $payload = $request->input('operations', []);

        foreach (config('department_operations') as $key => $op) {
            $configurable = $op['configurable_positions'] ?? [];
            if (empty($configurable)) {
                continue;
            }

            $submitted = $payload[$key]['positions'] ?? [];
            $positions = array_values(array_filter(
                array_map('strval', is_array($submitted) ? $submitted : []),
                fn ($p) => $p !== '' && in_array($p, $configurable, true)
            ));

            DepartmentOperationSetting::updateOrCreate(
                ['department_id' => $department->id, 'operation_key' => $key],
                ['config' => ['positions' => $positions], 'enabled' => count($positions) > 0],
            );
        }

        $department->forgetOperationsCache();

        return redirect()
            ->route('admin.departments.show', $department)
            ->with('success', 'Права обновлены.');
    }

    public function update(Request $request, Department $department)
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:100', 'unique:departments,name,' . $department->id],
            'code'        => ['nullable', 'string', 'max:50', 'unique:departments,code,' . $department->id],
            'description' => ['nullable', 'string', 'max:1000'],
            'manager_id'  => ['nullable', 'exists:workers,id'],
        ]);

        $department->update(array_merge($validated, ['is_active' => $request->boolean('is_active')]));

        return redirect()
            ->route('admin.departments.show', $department)
            ->with('success', 'Отдел успешно обновлён.');
    }

    public function destroy(Department $department)
    {
        if ($department->workers()->exists() || $department->allWorkers()->exists()) {
            return redirect()
                ->route('admin.settings.index')
                ->with('error', 'Нельзя удалить отдел: в нём есть работники.');
        }

        $department->delete();

        return redirect()
            ->route('admin.settings.index')
            ->with('success', 'Отдел удалён.');
    }
}
