<?php

namespace App\Services;

use App\Models\Department;
use App\Models\SalaryPayout;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Сводка выплат за неделю выдачи по отделам.
 *
 * Неделя выдачи — period_to выдачи (пятница 00:00): у всех, кому выдали в один день, он общий.
 * Работник, заработавший в нескольких отделах, стоит строкой в каждом: наличные, карта, налог,
 * «в копилку» и перерасчёт делятся пропорционально начисленному в отделе (снимок
 * salary_payout_departments). Сумма по отделам равна выдаче до копейки.
 */
class SalaryPayoutReportService
{
    /** Денежные поля выдачи, которые делятся между отделами. */
    private const SPLIT_FIELDS = [
        'cash'   => 'paid_cash',
        'card'   => 'paid_card',
        'tax'    => 'paid_tax',
        'toBank' => 'to_bank',
        'recalc' => 'recalc',
        'total'  => 'paid_total',
    ];

    public function __construct(private readonly SalaryService $salary) {}

    /**
     * Недели, за которые были выдачи, — новые сверху.
     *
     * @return Collection<int, Carbon>
     */
    public function weeks(): Collection
    {
        return SalaryPayout::query()
            ->select('period_to')
            ->distinct()
            ->orderByDesc('period_to')
            ->pluck('period_to')
            ->map(fn ($to) => Carbon::parse($to))
            ->values();
    }

    /**
     * Неделя (неизвестная — последняя), группы по отделам со строками и подытогами, общий итог.
     *
     * @return array<string, mixed>
     */
    public function report(?Carbon $week): array
    {
        $weeks = $this->weeks();
        // Неизвестная неделя — как незаданная: последняя
        $week = $weeks->first(fn (Carbon $w) => $week && $w->isSameDay($week)) ?? $weeks->first();

        $payouts = $week
            ? SalaryPayout::with(['worker.department', 'departments'])
                ->whereDate('period_to', $week->toDateString())
                ->get()
            : collect();

        $rows = $payouts->flatMap(fn (SalaryPayout $payout) => $this->splitPayout($payout));

        $departments = Department::whereIn('id', $rows->pluck('departmentId')->filter()->unique())
            ->get()
            ->keyBy('id');

        $groups = $rows
            ->groupBy('departmentId')
            ->map(fn (Collection $deptRows, $id) => [
                'department' => $id ? $departments->get($id) : null,
                'rows'       => $deptRows->sortBy(fn ($row) => $row['worker']->name)->values(),
                'totals'     => $this->totals($deptRows),
            ])
            ->sortBy(fn ($group) => $group['department']->name ?? "\u{FFFF}")
            ->values();

        return [
            'week'      => $week,
            'weeks'     => $weeks,
            'groups'    => $groups,
            'totals'    => $this->totals($rows),
            'hasRecalc' => $payouts->contains(fn (SalaryPayout $p) => $p->recalc != 0),
        ];
    }

    /**
     * Строки выдачи по отделам. Доли — по положительному начисленному в отделе; начислять нечего
     * (всё ≤ 0) — вся выдача в основной отдел работника.
     */
    private function splitPayout(SalaryPayout $payout): Collection
    {
        // Выдачи до появления снимка — разбивка по текущим данным за тот же период
        $accrued = $payout->departments->isNotEmpty()
            ? $payout->departments->mapWithKeys(fn ($d) => [(int) $d->department_id => $d->accrued])->all()
            : $this->salary->accruedByDepartment(
                $payout->worker,
                Carbon::parse($payout->period_from),
                Carbon::parse($payout->period_to),
            );

        $weights = array_map(fn ($v) => max(0.0, (float) $v), $accrued);
        if (array_sum($weights) <= 0) {
            $primary = (int) $payout->worker->department_id;
            $accrued = [$primary => (float) $payout->accrued];
            $weights = [$primary => 1.0];
        }

        $split = [];
        foreach (self::SPLIT_FIELDS as $key => $field) {
            $split[$key] = $this->distribute((float) $payout->{$field}, $weights);
        }

        return collect(array_keys($accrued))->map(fn (int $deptId): array => [
            'departmentId' => $deptId ?: null,
            'worker'       => $payout->worker,
            'payout'       => $payout,
            'accrued'      => round((float) $accrued[$deptId], 2),
        ] + array_map(fn ($parts) => $parts[$deptId] ?? 0.0, $split));
    }

    /**
     * Сумма по весам до копеек; остаток округления — отделу с наибольшим весом.
     *
     * @param  array<int, float>  $weights
     * @return array<int, float>
     */
    private function distribute(float $amount, array $weights): array
    {
        $sum   = array_sum($weights);
        $parts = array_map(fn ($w) => round($amount * $w / $sum, 2), $weights);

        $largest         = array_keys($weights, max($weights))[0];
        $parts[$largest] = round($parts[$largest] + $amount - array_sum($parts), 2);

        return $parts;
    }

    /** @return array<string, float> */
    private function totals(Collection $rows): array
    {
        return collect(['accrued', ...array_keys(self::SPLIT_FIELDS)])
            ->mapWithKeys(fn ($key) => [$key => round($rows->sum($key), 2)])
            ->all();
    }
}
