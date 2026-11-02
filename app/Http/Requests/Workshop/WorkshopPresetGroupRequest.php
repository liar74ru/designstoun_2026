<?php

namespace App\Http\Requests\Workshop;

use App\Models\WorkshopPresetGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkshopPresetGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('workshop_preset_groups', 'name')
                    ->where('department_id', $this->route('department')->id)
                    ->ignore($this->route('presetGroup')?->id),
            ],
            'icon' => ['nullable', Rule::in(array_keys(WorkshopPresetGroup::ICONS))],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Укажите название группы',
            'name.unique'   => 'Группа с таким названием уже есть в этом отделе',
            'icon.in'       => 'Выберите иконку из набора',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('icon') === '') {
            $this->merge(['icon' => null]);
        }
    }
}
