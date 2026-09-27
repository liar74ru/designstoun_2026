<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class ProductCoeffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'prod_cost_coeff'   => ['required', 'numeric', 'between:-100,100'],
            'master_cost_coeff' => ['required', 'numeric', 'between:-100,100'],
        ];
    }

    public function messages(): array
    {
        return [
            'prod_cost_coeff.required'   => 'Укажите коэффициент пильщика',
            'prod_cost_coeff.numeric'    => 'Коэффициент пильщика должен быть числом',
            'prod_cost_coeff.between'    => 'Коэффициент пильщика — от -100 до 100',
            'master_cost_coeff.required' => 'Укажите коэффициент мастера',
            'master_cost_coeff.numeric'  => 'Коэффициент мастера должен быть числом',
            'master_cost_coeff.between'  => 'Коэффициент мастера — от -100 до 100',
        ];
    }
}
