<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Store;
use App\Models\Worker;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function create()
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        return view('admin.departments.create');
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

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

    public function show(Department $department)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $workers    = $department->activeWorkers()->get();
        $stores     = Store::where('archived', false)->orderBy('name')->get();
        $allWorkers = Worker::orderBy('name')->get();

        return view('admin.departments.show', compact('department', 'workers', 'stores', 'allWorkers'));
    }

    public function update(Request $request, Department $department)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

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
        abort_unless(auth()->user()?->isAdmin(), 403);

        if ($department->workers()->exists()) {
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
