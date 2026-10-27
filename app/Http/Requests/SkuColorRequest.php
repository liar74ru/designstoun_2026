<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SkuColorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Префиксы приходят строкой «01-07, 04-07» — режем в массив. */
    protected function prepareForValidation(): void
    {
        $rows = $this->input('rows');
        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $i => $row) {
            $raw = (string) ($row['prefixes'] ?? '');
            $rows[$i]['prefixes'] = array_values(array_unique(array_filter(
                array_map('trim', preg_split('/[\s,;]+/', $raw)),
                fn ($p) => $p !== ''
            )));
        }

        $this->merge(['rows' => $rows]);
    }

    public function rules(): array
    {
        return [
            'rows'            => ['nullable', 'array'],
            'rows.*.name'     => ['nullable', 'string', 'max:100'],
            'rows.*.color'    => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'rows.*.prefixes' => ['required', 'array', 'min:1'],
            // «01-05» — камень, «01» — цвет всей группы (см. Product::getColorBySku())
            'rows.*.prefixes.*' => ['string', 'max:32', 'regex:/^[0-9A-Za-z]+(-[0-9A-Za-z]+)?$/'],
        ];
    }

    /** Префикс может принадлежать только одному цвету. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $seen = [];
                foreach ((array) $this->input('rows') as $row) {
                    foreach ((array) ($row['prefixes'] ?? []) as $prefix) {
                        if (isset($seen[$prefix])) {
                            $validator->errors()->add('rows', "Префикс {$prefix} указан у двух цветов");
                            return;
                        }
                        $seen[$prefix] = true;
                    }
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'rows.*.prefixes.required' => 'Укажите хотя бы один префикс SKU у каждого цвета',
            'rows.*.prefixes.*.regex'  => 'Префикс — один или два сегмента SKU, например «01» или «01-05»',
            'rows.*.color.regex'       => 'Цвет — в формате #RRGGBB',
        ];
    }
}
