<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class ProductHiddenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'hidden' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'hidden.required' => 'Не указано, скрыть товар или показать',
            'hidden.boolean'  => 'Неверное значение видимости товара',
        ];
    }
}
