<?php

namespace App\Http\Requests\Workshop;

use App\Models\WorkshopPreset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkshopPresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:100',
                // Имя уникально в каждом отделе пресета, включая подключённые.
                function (string $attribute, mixed $value, \Closure $fail) {
                    $taken = WorkshopPreset::query()
                        ->where('name', $value)
                        ->when($this->route('preset'), fn($q, $preset) => $q->whereKeyNot($preset->id))
                        ->whereHas('departments', fn($q) => $q->whereIn('departments.id', $this->targetDepartmentIds()))
                        ->exists();
                    if ($taken) {
                        $fail('Пресет с таким названием уже есть в одном из выбранных отделов');
                    }
                },
            ],

            'department_ids'   => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'exists:departments,id'],

            'group_ids'   => ['nullable', 'array'],
            'group_ids.*' => [
                'integer',
                Rule::exists('workshop_preset_groups', 'id')
                    ->where('department_id', $this->route('department')->id),
            ],

            'raw_materials'              => 'required|array|min:1',
            'raw_materials.*.product_id' => 'required|exists:products,id',
            'raw_materials.*.quantity'   => 'required|numeric|min:0.001',

            'packages'                   => 'nullable|array',
            'packages.*.product_id'      => 'required_with:packages|exists:products,id',
            'packages.*.quantity'        => 'required_with:packages|numeric|min:0.001',

            'products'                   => 'required|array|min:1',
            'products.*.product_id'      => 'required|exists:products,id',
            'products.*.quantity'        => 'required|numeric|min:0.001',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Укажите название пресета',

            'group_ids.*.exists' => 'Выберите группы этого отдела',

            'raw_materials.required'              => 'Добавьте хотя бы одну позицию сырья',
            'raw_materials.*.product_id.required' => 'Выберите сырьё',
            'raw_materials.*.quantity.required'   => 'Укажите количество сырья',
            'raw_materials.*.quantity.min'        => 'Количество сырья должно быть больше 0',

            'packages.*.product_id.required_with' => 'Выберите упаковку',
            'packages.*.quantity.required_with'   => 'Укажите количество упаковки',
            'packages.*.quantity.min'             => 'Количество упаковки должно быть больше 0',

            'products.required'              => 'Добавьте хотя бы один продукт на выходе',
            'products.*.product_id.required' => 'Выберите продукт',
            'products.*.quantity.required'   => 'Укажите количество',
            'products.*.quantity.min'        => 'Количество должно быть больше 0',
        ];
    }

    /** Отделы, в которых окажется пресет: отмеченные + текущий + основной (как в сервисе). */
    private function targetDepartmentIds(): array
    {
        return collect($this->input('department_ids', []))
            ->map(fn($id) => (int) $id)
            ->push($this->route('department')->id, $this->route('preset')?->department_id)
            ->filter()->unique()->values()->all();
    }
}
