<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderStatusSettingController extends Controller
{
    public const SETTING_KEY = 'MOYSKLAD_ORDER_STATUSES';

    public function index(): View
    {
        $statuses = json_decode(Setting::get(self::SETTING_KEY, '[]'), true) ?: [];

        return view('admin.order-statuses.index', compact('statuses'));
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'statuses'   => ['nullable', 'array', 'max:30'],
            'statuses.*' => ['required', 'string', 'max:100'],
        ]);

        $statuses = array_values(array_unique(array_filter(
            array_map('trim', $validated['statuses'] ?? [])
        )));

        Setting::set(self::SETTING_KEY, json_encode($statuses, JSON_UNESCAPED_UNICODE));

        return redirect()
            ->route('admin.order-statuses.index')
            ->with('success', 'Список статусов заявок сохранён.');
    }
}
