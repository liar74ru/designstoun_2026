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
            'settings.*.value'   => ['required', 'numeric', 'min:0'],
        ]);

        foreach ($validated['settings'] as $item) {
            Setting::set($item['key'], $item['value']);
        }

        return redirect()
            ->route('admin.settings.index')
            ->with('success', 'Настройки сохранены.');
    }
}
