<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

class AdminSettingController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $settings = Setting::orderBy('id')->get();

        return view('admin.settings.index', compact('settings'));
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
}
