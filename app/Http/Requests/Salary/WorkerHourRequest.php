<?php

namespace App\Http\Requests\Salary;

use App\Models\Worker;
use App\Models\WorkerHourEntry;
use App\Services\WorkerHourService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Часы ремонтных работ: добавление (маршрут с {worker}) и правка (с {workerHourEntry}).
 * Право — до валидации; дата — в невыплаченной неделе и не позже сегодня (админу — любая прошедшая).
 */
class WorkerHourRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entry = $this->entry();

        return $entry
            ? $this->user()->can('modify', $entry)
            : $this->user()->can('create', [WorkerHourEntry::class, $this->route('worker')]);
    }

    public function rules(): array
    {
        $date = ['required', 'date', 'before_or_equal:today'];

        if (!$this->user()->isAdmin()) {
            $date[] = 'after_or_equal:' . app(WorkerHourService::class)->unpaidFrom($this->worker())->toDateString();
        }

        return [
            'work_date' => $date,
            'hours'     => ['required', 'numeric', 'min:0.25', 'max:24', 'multiple_of:0.25'],
            'comment'   => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'work_date.after_or_equal'  => 'Эта неделя уже выплачена — часы за неё добавляет администратор',
            'work_date.before_or_equal' => 'Дата работы не может быть позже сегодняшней',
            'hours.min'                 => 'Не меньше 0,25 ч (15 минут)',
            'hours.max'                 => 'Не больше 24 ч за запись',
            'hours.multiple_of'         => 'Часы — с шагом 0,25 (15 минут)',
        ];
    }

    private function entry(): ?WorkerHourEntry
    {
        $entry = $this->route('workerHourEntry');

        return $entry instanceof WorkerHourEntry ? $entry : null;
    }

    private function worker(): Worker
    {
        $entry = $this->entry();

        return $entry ? $entry->worker : $this->route('worker');
    }
}
