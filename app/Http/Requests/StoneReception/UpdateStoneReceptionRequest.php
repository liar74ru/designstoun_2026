<?php

namespace App\Http\Requests\StoneReception;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStoneReceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Вычисляем raw_quantity_used из дельты ДО валидации,
     * чтобы валидатор получил итоговое значение.
     */
    public function prepareForValidation(): void
    {
        $rawDelta   = (float) $this->input('raw_quantity_delta', 0);
        $currentRaw = (float) $this->route('stone_reception')->raw_quantity_used;
        $this->merge(['raw_quantity_used' => $currentRaw + $rawDelta]);
    }

    public function rules(): array
    {
        return [
            'receiver_id'            => 'required|exists:workers,id',
            'cutter_id'              => 'nullable|exists:workers,id',
            'store_id'               => 'required|exists:stores,id',
            'raw_material_batch_id'  => 'required|exists:raw_material_batches,id',
            'raw_quantity_used'      => 'required|numeric|min:0',
            'notes'                  => 'nullable|string',
            'products'               => 'required|array|min:1',
            'products.*.product_id'  => 'required|exists:products,id',
            'products.*.quantity'    => 'required|numeric|min:0',
            'products.*.is_undercut' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'receiver_id.required'           => 'Выберите приемщика',
            'raw_material_batch_id.required'  => 'Выберите партию сырья',
            'raw_quantity_used.required'      => 'Укажите расход сырья',
            'products.required'              => 'Добавьте хотя бы один продукт',
            'products.*.product_id.required' => 'Выберите продукт',
            'products.*.quantity.required'   => 'Укажите количество',
        ];
    }
}
