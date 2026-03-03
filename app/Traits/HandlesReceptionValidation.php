<?php

namespace App\Traits;

use Illuminate\Http\Request;

trait HandlesReceptionValidation
{
    /**
     * Валидирует данные приемки
     */
    protected function validateReception(Request $request, bool $isCreate = true): array
    {
        $rules = [
            'receiver_id' => 'required|exists:workers,id',
            'cutter_id' => 'nullable|exists:workers,id',
            'store_id' => 'required|exists:stores,id',
            'raw_material_batch_id' => 'required|exists:raw_material_batches,id',
            'raw_quantity_used' => 'required|numeric|min:0.001',
            'notes' => 'nullable|string',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|numeric|min:0.001',
        ];

        $messages = [
            'receiver_id.required' => 'Выберите приемщика',
            'raw_material_batch_id.required' => 'Выберите партию сырья',
            'raw_quantity_used.required' => 'Укажите расход сырья',
            'raw_quantity_used.min' => 'Расход сырья должен быть больше 0',
            'products.required' => 'Добавьте хотя бы один продукт',
            'products.min' => 'Добавьте хотя бы один продукт',
            'products.*.product_id.required' => 'Выберите продукт',
            'products.*.quantity.required' => 'Укажите количество',
            'products.*.quantity.min' => 'Количество должно быть больше 0',
        ];

        return $request->validate($rules, $messages);
    }
}
