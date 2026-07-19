<?php

namespace App\Http\Requests\Workshop;

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
                Rule::unique('workshop_presets', 'name')
                    ->where('department_id', $this->route('department')->id)
                    ->ignore($this->route('preset')?->id),
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
            'name.unique'   => 'Пресет с таким названием уже есть в этом отделе',

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
}
