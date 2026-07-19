<?php

namespace App\Http\Requests\Workshop;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkshopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'packer_id'                  => 'required|exists:workers,id',
            'receiver_id'                => 'required|exists:workers,id',
            'store_id'                   => 'required|exists:stores,id',
            'product_store_id'           => 'required|exists:stores,id',
            'department_id'              => 'nullable|exists:departments,id',
            'notes'                      => 'nullable|string',
            'manual_created_at'          => 'nullable|date',
            'manual_processing_sum'      => 'nullable|numeric|min:0',

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
            'packer_id.required'                => 'Выберите работника',
            'receiver_id.required'              => 'Выберите приёмщика',
            'store_id.required'                 => 'Выберите склад сырья',
            'product_store_id.required'         => 'Выберите склад продукта',
            'manual_processing_sum.min'         => 'Затраты не могут быть отрицательными',

            'raw_materials.required'            => 'Добавьте хотя бы одну позицию сырья',
            'raw_materials.*.product_id.required' => 'Выберите сырьё',
            'raw_materials.*.quantity.required' => 'Укажите количество сырья',
            'raw_materials.*.quantity.min'      => 'Количество сырья должно быть больше 0',

            'packages.*.product_id.required_with' => 'Выберите упаковку',
            'packages.*.quantity.required_with'   => 'Укажите количество упаковки',

            'products.required'                 => 'Добавьте хотя бы один продукт на выходе',
            'products.*.product_id.required'    => 'Выберите продукт',
            'products.*.quantity.required'      => 'Укажите количество',
        ];
    }
}
