<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Setting;
use App\Services\SkuColorService;
use App\Support\DepartmentSettings;
use App\Support\ProductionRates;
use Illuminate\Http\Request;

class AdminSettingController extends Controller
{
    /** Статусы техопераций МойСклад: имя должно совпадать с МойСклад символ в символ. */
    private const MOYSKLAD_KEYS = ['MOYSKLAD_IN_WORK_STATE', 'MOYSKLAD_DONE_STATE'];

    /**
     * Хаб настроек: плитки разделов со сводкой в одну строку.
     */
    public function index(SkuColorService $skuColorService)
    {
        $activeDepartments = Department::where('is_active', true)->count();
        $skuColorCount     = $skuColorService->all()->count();
        $inWorkState       = Setting::get('MOYSKLAD_IN_WORK_STATE');
        $doneState         = Setting::get('MOYSKLAD_DONE_STATE');
        $pieceRate         = DepartmentSettings::pieceRate(null);
        $masterRate        = DepartmentSettings::masterBaseRate(null);

        return view('admin.settings.index', compact(
            'activeDepartments', 'skuColorCount', 'inWorkState', 'doneState', 'pieceRate', 'masterRate'
        ));
    }

    /**
     * Ставки по умолчанию для отделов. Поля — из реестра config/department_settings.php,
     * поэтому новый ключ реестра появляется здесь без правки шаблона.
     */
    public function rates()
    {
        $groups = config('department_settings');
        $values = [];
        foreach ($groups as $group) {
            foreach ($group['keys'] as $key => $meta) {
                $values[$key] = Setting::get($key, $meta['default'] ?? 0);
            }
        }

        return view('admin.settings.rates', compact('groups', 'values'));
    }

    public function moysklad()
    {
        $settings = Setting::whereIn('key', self::MOYSKLAD_KEYS)->orderBy('id')->get();

        return view('admin.settings.moysklad', compact('settings'));
    }

    public function skuColors(SkuColorService $skuColorService)
    {
        $skuColors = $skuColorService->all();

        return view('admin.settings.sku-colors', compact('skuColors'));
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'settings'           => ['required', 'array'],
            'settings.*.key'     => ['required', 'string', 'max:100'],
            'settings.*.value'   => [
                'required',
                'string',
                'max:500',
                // Какие ключи допускают отрицательное значение — решает реестр
                // config/production_rates.php, а не список в контроллере.
                function ($attribute, $value, $fail) use ($request) {
                    $idx = explode('.', $attribute)[1] ?? null;
                    $key = $request->input("settings.{$idx}.key");
                    if ($key !== null && ProductionRates::allowsNegative($key)) {
                        return;
                    }
                    if (is_numeric($value) && (float) $value < 0) {
                        $fail('Значение не может быть отрицательным.');
                    }
                },
            ],
        ]);

        foreach ($validated['settings'] as $item) {
            Setting::set($item['key'], $item['value']);
        }

        // Форма живёт на странице раздела (ставки, МойСклад) — туда и возвращаемся
        return back()->with('success', 'Настройки сохранены.');
    }

    public function updateDepartmentStores(Request $request)
    {
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

        // Форма складов живёт в карточке отдела — туда и возвращаемся
        return back()->with('success', 'Склады отделов сохранены.');
    }

}
