<?php

namespace App\Http\Requests\Salary;

use Illuminate\Foundation\Http\FormRequest;

/** Сумма к выдаче на невыданный период: свою — работник, любую — админ. Пусто — по умолчанию. */
class SalaryRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageSalary', $this->route('worker'));
    }

    public function rules(): array
    {
        return [
            'amount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ];
    }
}
