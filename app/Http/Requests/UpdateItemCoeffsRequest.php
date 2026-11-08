<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Правка коэффициентов позиций на show-странице приёмки и цеха. */
class UpdateItemCoeffsRequest extends FormRequest
{
    /**
     * Чужой отдел — 403 до валидации: иначе правка чужой записи с кривыми данными
     * отвечала бы ошибками полей, а не отказом в доступе. Та же политика modify,
     * что и в контроллере.
     */
    public function authorize(): bool
    {
        return $this->user()->can('modify', $this->route('stoneReception') ?? $this->route('workshop'));
    }

    public function rules(): array
    {
        return [
            'items'                     => ['required', 'array'],
            'items.*.item_id'           => ['required', 'integer'],
            'items.*.base_coeff'        => ['required', 'numeric', 'between:-100,100'],
            'items.*.master_base_coeff' => ['required', 'numeric', 'between:-100,100'],
            'items.*.modifiers'         => 'nullable|array',
            'items.*.modifiers.*'       => 'string|max:64',
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.base_coeff.between'        => 'Коэффициент пильщика — от -100 до 100',
            'items.*.master_base_coeff.between' => 'Коэффициент мастера — от -100 до 100',
        ];
    }
}
