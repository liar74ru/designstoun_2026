<?php

namespace App\Http\Requests\StoneReception;

use Illuminate\Foundation\Http\FormRequest;

class StoreStoneReceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'receiver_id'            => 'required|exists:workers,id',
            'cutter_id'              => 'nullable|exists:workers,id',
            'store_id'               => 'required|exists:stores,id',
            'raw_material_batch_id'  => 'required|exists:raw_material_batches,id',
            'raw_quantity_used'      => 'required|numeric|min:0.001',
            'notes'                  => 'nullable|string',
            'products'               => 'required|array|min:1',
            'products.*.product_id'  => 'required|exists:products,id',
            'products.*.quantity'    => 'required|numeric|min:0.001',
            'products.*.is_undercut' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'receiver_id.required'           => 'Выберите приемщика',
            'raw_material_batch_id.required'  => 'Выберите партию сырья',
            'raw_quantity_used.required'      => 'Укажите расход сырья',
            'raw_quantity_used.min'           => 'Расход сырья должен быть больше 0',
            'products.required'              => 'Добавьте хотя бы один продукт',
            'products.*.product_id.required' => 'Выберите продукт',
            'products.*.quantity.required'   => 'Укажите количество',
            'products.*.quantity.min'        => 'Количество должно быть больше 0',
        ];
    }
}
