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
            'product_store_id'       => 'required|exists:stores,id',
            'department_id'          => 'nullable|exists:departments,id',
            'package_product_id'     => 'required|exists:products,id',
            'package_quantity'       => 'required|numeric|min:0.001',
            'result_product_id'      => 'nullable|exists:products,id|different:package_product_id',
            'notes'                  => 'nullable|string',
            'processing_name'        => 'nullable|string|max:255',
            'manual_created_at'      => 'nullable|date',
            'products'               => 'required|array|min:1',
            'products.*.product_id'  => 'required|exists:products,id',
            'products.*.quantity'    => 'required|numeric|min:0.001',
            'products.*.is_undercut' => 'nullable|boolean',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $resultId = $this->input('result_product_id');
            if (!$resultId) {
                return;
            }
            $productIds = array_column($this->input('products', []), 'product_id');
            if (in_array($resultId, $productIds)) {
                $validator->errors()->add(
                    'result_product_id',
                    'Товар-результат не может совпадать с упакованным продуктом'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'packer_id.required'              => 'Выберите упаковщика',
            'receiver_id.required'            => 'Выберите приёмщика',
            'store_id.required'               => 'Выберите склад сырья',
            'product_store_id.required'       => 'Выберите склад продукта',
            'package_product_id.required'     => 'Выберите упаковку (тару)',
            'package_quantity.required'       => 'Укажите количество тары',
            'package_quantity.min'            => 'Количество тары должно быть больше 0',
            'result_product_id.different'     => 'Товар-результат не может совпадать с тарой',
            'products.required'               => 'Добавьте хотя бы один упакованный продукт',
            'products.*.product_id.required'  => 'Выберите продукт',
            'products.*.quantity.required'    => 'Укажите количество',
            'products.*.quantity.min'         => 'Количество должно быть больше 0',
        ];
    }
}
