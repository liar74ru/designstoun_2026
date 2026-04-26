<?php

namespace App\Http\Requests\SupplierOrder;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_id'              => 'required|exists:stores,id',
            'counterparty_id'       => 'required|exists:counterparties,id',
            'receiver_id'           => 'nullable|exists:workers,id',
            'number'                => 'required|string|max:100',
            'note'                  => 'nullable|string|max:1000',
            'manual_created_at'     => 'nullable|date',
            'products'              => 'required|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity'   => 'required|numeric|min:0.001',
        ];
    }
}
