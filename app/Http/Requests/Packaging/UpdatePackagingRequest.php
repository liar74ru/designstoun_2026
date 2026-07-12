<?php

namespace App\Http\Requests\Packaging;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePackagingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Вычисляем итоговое package_quantity из дельты, чтобы валидатор получил итог.
     */
    public function prepareForValidation(): void
    {
        $delta = (float) $this->input('package_quantity_delta', 0);
        $current = (float) $this->route('packaging')->package_quantity;
        $this->merge(['package_quantity' => $current + $delta]);
    }

    public function rules(): array
    {
        return [
            'packer_id'              => 'required|exists:workers,id',
            'receiver_id'            => 'required|exists:workers,id',
            'store_id'               => 'required|exists:stores,id',
            'department_id'          => 'nullable|exists:departments,id',
            'package_product_id'     => 'required|exists:products,id',
            'package_quantity'       => 'required|numeric|min:0',
            'result_product_id'      => 'nullable|exists:products,id|different:package_product_id',
            'notes'                  => 'nullable|string',
            'manual_created_at'      => 'nullable|date',
            'products'               => 'required|array|min:1',
            'products.*.product_id'  => 'required|exists:products,id',
            'products.*.quantity'    => 'required|numeric|min:0',
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
            'packer_id.required'             => 'Выберите упаковщика',
            'receiver_id.required'           => 'Выберите приёмщика',
            'package_product_id.required'    => 'Выберите упаковку (тару)',
            'result_product_id.different'    => 'Товар-результат не может совпадать с тарой',
            'products.required'              => 'Должен быть хотя бы один упакованный продукт',
            'products.*.product_id.required' => 'Выберите продукт',
            'products.*.quantity.required'   => 'Укажите количество',
        ];
    }
}
