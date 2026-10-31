<?php

namespace App\Http\Requests\Salary;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Исправление последней выдачи. Имена полей свои, чтобы old() и ошибки
 * не попадали в форму новой выдачи на той же странице.
 */
class SalaryPayoutUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'edit_cash'    => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'edit_card'    => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'edit_tax'     => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'edit_comment' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'edit_cash'    => 'наличные',
            'edit_card'    => 'на карту',
            'edit_tax'     => 'налог',
            'edit_comment' => 'комментарий',
        ];
    }
}
