<?php

namespace App\Http\Requests\Salary;

use Illuminate\Foundation\Http\FormRequest;

/** «Зарплата выдана»: раскладка выданного. Налог — из зарплаты работника. */
class SalaryPaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'paid_cash' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'paid_card' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'paid_tax'  => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'comment'   => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'paid_cash' => 'наличные',
            'paid_card' => 'на карту',
            'paid_tax'  => 'налог',
        ];
    }
}
