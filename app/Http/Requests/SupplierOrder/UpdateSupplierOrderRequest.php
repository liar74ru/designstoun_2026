<?php

namespace App\Http\Requests\SupplierOrder;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierOrderRequest extends FormRequest
{
    /**
     * Чужой отдел — 403 до валидации: иначе правка чужой записи с кривыми данными
     * отвечала бы ошибками полей, а не отказом в доступе. Та же политика modify,
     * что и в контроллере.
     */
    public function authorize(): bool
    {
        return $this->user()->can('modify', $this->route('supplierOrder'));
    }

    public function rules(): array
    {
        return [
            'store_id'              => 'required|uuid|exists:stores,id',
            'counterparty_id'       => 'required|uuid|exists:counterparties,id',
            'receiver_id'           => 'nullable|integer|exists:workers,id',
            'department_id'         => 'nullable|integer|exists:departments,id',
            'number'                => 'required|string|max:100',
            'note'                  => 'nullable|string|max:1000',
            'manual_created_at'     => 'nullable|date',
            'products'              => 'required|array|min:1',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity'   => 'required|numeric|min:0.001',
        ];
    }
}
