<?php

namespace App\Services;

use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerHourEntry;
use App\Support\DepartmentSettings;
use Carbon\Carbon;

/**
 * Часы ремонтных работ: работы вне сдельной оплаты за продукцию.
 *
 * Сумма = часы × ставка отдела за час (HOURLY_RATE); ставка фиксируется в записи.
 * Начисляется по дате работы вместе с выработкой недели (WorkerDashboardService),
 * поэтому сразу попадает в копилку и выдачу. Работник и мастер его отдела правят
 * записи, пока неделя не выплачена; админ — всегда (выплаченная неделя доберётся
 * до копилки пересчётом, как правка приёмки).
 */
class WorkerHourService
{
    public function __construct(private readonly SalaryService $salary) {}

    /** Первый день невыплаченного периода: часы раньше него уже выданы. */
    public function unpaidFrom(Worker $worker): Carbon
    {
        return $this->salary->openPeriod($worker)[0]->copy()->startOfDay();
    }

    /** Запись в невыплаченной неделе — её ещё можно править не только админу. */
    public function isUnpaid(WorkerHourEntry $entry): bool
    {
        return $this->unpaidFrom($entry->worker)->lte($entry->work_date);
    }

    /**
     * @param  array{work_date: string, hours: float|string, comment?: ?string}  $data
     */
    public function create(Worker $worker, array $data, User $by): WorkerHourEntry
    {
        $departmentId = $worker->department_id ? (int) $worker->department_id : null;
        $rate         = DepartmentSettings::hourlyRate($departmentId);

        return WorkerHourEntry::create([
            'worker_id'          => $worker->id,
            'department_id'      => $departmentId,
            'work_date'          => $data['work_date'],
            'hours'              => (float) $data['hours'],
            'hourly_rate'        => $rate,
            'amount'             => $this->amount((float) $data['hours'], $rate),
            'comment'            => $data['comment'] ?? null,
            'created_by_user_id' => $by->id,
        ]);
    }

    /**
     * Правка: сумма — от ставки, сохранённой в записи, а не от текущей ставки отдела.
     *
     * @param  array{work_date: string, hours: float|string, comment?: ?string}  $data
     */
    public function update(WorkerHourEntry $entry, array $data): void
    {
        $entry->update([
            'work_date' => $data['work_date'],
            'hours'     => (float) $data['hours'],
            'amount'    => $this->amount((float) $data['hours'], $entry->hourly_rate),
            'comment'   => $data['comment'] ?? null,
        ]);
    }

    public function delete(WorkerHourEntry $entry): void
    {
        $entry->delete();
    }

    private function amount(float $hours, float $rate): float
    {
        return round($hours * $rate, 2);
    }
}
