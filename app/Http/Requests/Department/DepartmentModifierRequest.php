<?php

namespace App\Http\Requests\Department;

use App\Models\DepartmentModifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DepartmentModifierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'key' => [
                'required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('department_modifiers', 'key')
                    ->where('department_id', $this->route('department')->id)
                    ->ignore($this->route('modifier')?->id),
            ],

            'name'  => ['required', 'string', 'max:100'],
            'color' => ['nullable', Rule::in(array_keys(DepartmentModifier::COLORS))],

            'trigger' => [
                'required',
                Rule::in([DepartmentModifier::TRIGGER_MANUAL, DepartmentModifier::TRIGGER_SKU]),
            ],
            'sku_pattern'              => ['required_if:trigger,' . DepartmentModifier::TRIGGER_SKU, 'nullable', 'string', 'max:32'],
            'available_when_batch_sku' => ['nullable', 'string', 'max:32'],

            'applies_to' => [
                'required',
                Rule::in([
                    DepartmentModifier::SCOPE_RECEPTION,
                    DepartmentModifier::SCOPE_WORKSHOP,
                    DepartmentModifier::SCOPE_BOTH,
                ]),
            ],

            // Штрафы отрицательны — ограничения снизу нет.
            'worker_coeff_delta'   => ['nullable', 'numeric'],
            'worker_coeff_replace' => ['nullable', 'numeric'],
            'master_coeff_delta'   => ['nullable', 'numeric'],
            'master_coeff_replace' => ['nullable', 'numeric'],

            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'is_active'  => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                // ModifierEngine::apply() при заданном replace игнорирует delta молча —
                // не даём админу задать пару, которая работает не так, как выглядит.
                foreach (['worker' => 'пильщика', 'master' => 'мастера'] as $role => $label) {
                    if ($this->filled("{$role}_coeff_delta") && $this->filled("{$role}_coeff_replace")) {
                        $validator->errors()->add(
                            "{$role}_coeff_replace",
                            "Для {$label} задайте либо прибавку, либо замену коэффициента — не оба сразу",
                        );
                    }
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'key.required'    => 'Укажите ключ правила',
            'key.regex'       => 'Ключ — латиница в нижнем регистре, цифры и подчёркивание, начиная с буквы',
            'key.unique'      => 'Правило с таким ключом уже есть в этом отделе',
            'name.required'   => 'Укажите название правила',
            'color.in'        => 'Выберите цвет из палитры',
            'trigger.required' => 'Выберите, когда правило срабатывает',
            'trigger.in'       => 'Неизвестный тип срабатывания',
            'sku_pattern.required_if' => 'Укажите маску SKU — по ней правило срабатывает автоматически',
            'applies_to.required'     => 'Выберите область действия',
            'applies_to.in'           => 'Неизвестная область действия',
            'sort_order.required' => 'Укажите порядок применения',
            'sort_order.min'      => 'Порядок не может быть отрицательным',
            'sort_order.max'      => 'Порядок не может быть больше 65535',
        ];
    }

    /**
     * Чекбокс отсутствует в запросе, когда снят; пустые строки полей — это null,
     * иначе «не задано» превратится в 0 и правило начнёт обнулять коэффициент.
     */
    protected function prepareForValidation(): void
    {
        $nullable = [
            'color', 'sku_pattern', 'available_when_batch_sku',
            'worker_coeff_delta', 'worker_coeff_replace',
            'master_coeff_delta', 'master_coeff_replace',
        ];

        $merge = ['is_active' => $this->boolean('is_active')];

        foreach ($nullable as $field) {
            if ($this->input($field) === '') {
                $merge[$field] = null;
            }
        }

        $this->merge($merge);
    }
}
