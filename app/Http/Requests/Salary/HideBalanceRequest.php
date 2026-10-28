<?php

namespace App\Http\Requests\Salary;

use Illuminate\Foundation\Http\FormRequest;

/** Скрыть/показать остаток копилки — настройка самого работника. */
class HideBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageSalary', $this->route('worker'));
    }

    public function rules(): array
    {
        return [
            'hide' => ['required', 'boolean'],
        ];
    }
}
