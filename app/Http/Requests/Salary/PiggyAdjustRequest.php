<?php

namespace App\Http\Requests\Salary;

use Illuminate\Foundation\Http\FormRequest;

/** Корректировка копилки админом: «+» — внести, «−» — аванс или долг. */
class PiggyAdjustRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'piggy_amount'  => ['required', 'numeric', 'not_in:0', 'min:-9999999', 'max:9999999'],
            'piggy_comment' => ['required', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'piggy_amount'  => 'сумма',
            'piggy_comment' => 'комментарий',
        ];
    }
}
