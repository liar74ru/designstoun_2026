<?php

namespace App\Http\Requests\Worker;

use App\Models\Worker;
use App\Services\WorkerService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Создание и правка карточки работника.
 *
 * Не-админ назначает только отделы из своей зоны и только рядовые должности
 * (WorkerService::assignablePositions) — иначе мастер с see-workers выдал бы себе
 * или подчинённому права во всех отделах. Свою карточку не-админ правит без отделов
 * и должности: эти поля исключаются из запроса.
 */
class WorkerRequest extends FormRequest
{
    /** Чужой работник — 403 до валидации; та же политика modify, что и в контроллере. */
    public function authorize(): bool
    {
        $worker = $this->worker();

        return $worker === null || $this->user()->can('modify', $worker);
    }

    public function rules(): array
    {
        $service    = app(WorkerService::class);
        $user       = $this->user();
        $worker     = $this->worker();
        $accessible = $user->accessibleDepartmentIds();

        $rules = [
            'name'  => 'required|string|max:255',
            'email' => ['nullable', 'email', Rule::unique('workers', 'email')->ignore($worker?->id)],
            'phone' => 'nullable|string|max:50',
        ];

        if (!$service->canChangeAssignment($user, $worker)) {
            return $rules + [
                'position'         => 'exclude',
                'department_id'    => 'exclude',
                'department_ids'   => 'exclude',
                'department_ids.*' => 'exclude',
            ];
        }

        $departmentRule = $accessible === null ? [] : [Rule::in($accessible)];

        return $rules + [
            'position'         => ['required', 'string', Rule::in($service->assignablePositions($user, $worker))],
            'department_id'    => ['nullable', 'exists:departments,id', ...$departmentRule],
            'department_ids'   => 'nullable|array',
            'department_ids.*' => ['exists:departments,id', ...$departmentRule],
        ];
    }

    public function messages(): array
    {
        return [
            'position.in'         => 'Эту должность может назначить только администратор',
            'department_id.in'    => 'Можно выбрать только свой отдел',
            'department_ids.*.in' => 'Можно выбрать только свои отделы',
        ];
    }

    private function worker(): ?Worker
    {
        $worker = $this->route('worker');

        return $worker instanceof Worker ? $worker : null;
    }
}
