<?php

namespace App\Http\Requests\Packaging;

use Illuminate\Foundation\Http\FormRequest;

class StorePackagingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'packer_id'              => 'required|exists:workers,id',
            'receiver_id'            => 'required|exists:workers,id',
            'store_id'               => 'required|exists:stores,id',
            'package_product_id'     => 'required|exists:products,id',
            'package_quantity'       => 'required|numeric|min:0.001',
            'notes'                  => 'nullable|string',
            'processing_name'        => 'nullable|string|max:255',
            'manual_created_at'      => 'nullable|date',
            'products'               => 'required|array|min:1',
            'products.*.product_id'  => 'required|exists:products,id',
            'products.*.quantity'    => 'required|numeric|min:0.001',
            'products.*.is_undercut' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'packer_id.required'              => 'Выберите упаковщика',
            'receiver_id.required'            => 'Выберите приёмщика',
            'package_product_id.required'     => 'Выберите упаковку (тару)',
            'package_quantity.required'       => 'Укажите количество тары',
            'package_quantity.min'            => 'Количество тары должно быть больше 0',
            'products.required'               => 'Добавьте хотя бы один упакованный продукт',
            'products.*.product_id.required'  => 'Выберите продукт',
            'products.*.quantity.required'    => 'Укажите количество',
            'products.*.quantity.min'         => 'Количество должно быть больше 0',
        ];
    }
}
