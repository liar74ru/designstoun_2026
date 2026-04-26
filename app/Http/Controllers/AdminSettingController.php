<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Setting;
use App\Models\Store;
use Illuminate\Http\Request;

class AdminSettingController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $settings    = Setting::orderBy('id')->get();
        $departments = Department::orderBy('name')->get();
        $stores      = Store::where('archived', false)->orderBy('name')->get();

        return view('admin.settings.index', compact('settings', 'departments', 'stores'));
    }

    public function update(Request $request)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'settings'           => ['required', 'array'],
            'settings.*.key'     => ['required', 'string', 'max:100'],
            'settings.*.value'   => [
                'required',
                'string',
                'max:500',
                function ($attribute, $value, $fail) {
                    if (is_numeric($value) && (float) $value < 0) {
                        $fail('Значение не может быть отрицательным.');
                    }
                },
            ],
        ]);

        foreach ($validated['settings'] as $item) {
            Setting::set($item['key'], $item['value']);
        }

        return redirect()
            ->route('admin.settings.index')
            ->with('success', 'Настройки сохранены.');
    }

    public function updateDepartmentStores(Request $request)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'departments'                          => ['required', 'array'],
            'departments.*.raw_store_id'           => ['nullable', 'string', 'exists:stores,id'],
            'departments.*.product_store_id'       => ['nullable', 'string', 'exists:stores,id'],
            'departments.*.production_store_id'    => ['nullable', 'string', 'exists:stores,id'],
        ]);

        $departmentIds = array_keys($validated['departments']);
        $departments   = Department::whereIn('id', $departmentIds)->get()->keyBy('id');

        foreach ($validated['departments'] as $deptId => $stores) {
            $dept = $departments->get($deptId);
            if ($dept) {
                Setting::setDeptStores(
                    $dept,
                    $stores['raw_store_id'] ?? null,
                    $stores['product_store_id'] ?? null,
                    $stores['production_store_id'] ?? null,
                );
            }
        }

        return redirect()
            ->route('admin.settings.index')
            ->with('success', 'Склады отделов сохранены.');
    }

}
