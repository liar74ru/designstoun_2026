<?php

namespace App\Services;

use App\Models\PiggyBankEntry;
use App\Models\SalaryPayout;
use App\Models\User;
use App\Models\Worker;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Выдача зарплаты и копилка работника.
 *
 * Выдаётся неделя Пт 00:00 – Чт 23:59; не выданные недели суммируются. Распоряжаться
 * выплатой можно с отсечки (Чт 21:00). Начисленное до выдачи ещё меняется (приёмки,
 * пересчёт коэффициентов), а выбранная сумма — нет: разница «начислено − выдано» уходит
 * в копилку или берётся из неё (остаток может быть < 0). Пересчёт уже выданных недель
 * (коэффициент меняет ставку задним числом) целиком уходит в копилку при следующей выдаче.
 */
class SalaryService
{
    public function __construct(private readonly WorkerDashboardService $dashboard) {}

    /** Последняя прошедшая отсечка периода (по умолчанию — четверг 21:00). */
    public function cutoffBefore(?Carbon $now = null): Carbon
    {
        $now ??= Carbon::now();
        [$hour, $minute] = array_map('intval', explode(':', config('salary.cutoff_time')));

        $cutoff = $now->copy()->setTime($hour, $minute);
        while ($cutoff->dayOfWeek !== (int) config('salary.cutoff_day') || $cutoff->gt($now)) {
            $cutoff->subDay();
        }

        return $cutoff;
    }

    /** Конец недели, которой уже можно распоряжаться: пятница 00:00 после последней отсечки. */
    public function payableTo(?Carbon $now = null): Carbon
    {
        return $this->cutoffBefore($now)->addDay()->startOfDay();
    }

    /**
     * Невыданный период: [from, to), to — пятница 00:00. Первая выдача — одна неделя,
     * более ранняя история в копилку не попадает; стартовый остаток вносит администратор.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function openPeriod(Worker $worker): array
    {
        $to   = $this->payableTo();
        $last = $worker->salaryPayouts()->latest('period_to')->value('period_to');

        $from = $last ? Carbon::parse($last) : $to->copy()->subWeek();

        return [$from, $to];
    }

    /** Выплатой можно распоряжаться, если с прошлой выдачи прошла отсечка (Чт 21:00). */
    public function isManageable(Carbon $from, Carbon $to): bool
    {
        return $to->gt($from);
    }

    /**
     * Перерасчёт выданных недель за последние recalc_weeks: начислено по ним сейчас минус
     * учтённое. Периоды выдач смежные — хватает одного расчёта по всему диапазону.
     */
    public function recalc(Worker $worker): float
    {
        $payouts = $this->recalcPayouts($worker);
        if ($payouts->isEmpty()) {
            return 0.0;
        }

        $now = $this->accrued($worker, $payouts->min('period_from'), $payouts->max('period_to'));

        return round($now - $payouts->sum('accounted'), 2);
    }

    public function piggyBalance(Worker $worker): float
    {
        return round((float) $worker->piggyBankEntries()->sum('amount'), 2);
    }

    /** Отчисление в копилку по умолчанию: % от начисленного, к ближайшему кратному шагу. */
    public function defaultDeposit(float $accrued): float
    {
        $step    = (int) config('salary.piggy_round_step');
        $deposit = round($accrued * config('salary.piggy_percent') / 100 / $step) * $step;

        return max(0.0, (float) $deposit);
    }

    /** Предел, который работник может запросить сам: начисленное + положительный остаток копилки. */
    public function maxRequest(float $accrued, float $balance): float
    {
        $step = (int) config('salary.request_step');

        return max(0.0, floor((max(0, $accrued) + max(0, $balance)) / $step) * $step);
    }

    /** Всё для блока «Копилка и выдача» на странице выработки. */
    public function summary(Worker $worker): array
    {
        [$from, $to] = $this->openPeriod($worker);

        // Окно закрыто (неделю уже выдали) — для справки начисленное текущей недели по сейчас.
        $isOpen  = $this->isManageable($from, $to);
        $accrued = $this->accrued($worker, $from, $isOpen ? $to : Carbon::now()->startOfSecond());
        $recalc  = $this->recalc($worker);
        $balance = $this->piggyBalance($worker);

        $defaultToPay = max(0.0, $accrued - $this->defaultDeposit($accrued));
        $toPay        = $worker->requested_payout ?? $defaultToPay;
        $toBank       = $accrued + $recalc - $toPay;

        return [
            'periodFrom'   => $from,
            'periodTo'     => $to,
            'isOpen'       => $isOpen,
            'nextCutoff'   => $this->cutoffBefore()->addWeek(),
            'accrued'      => $accrued,
            'recalc'       => $recalc,
            'defaultToPay' => $defaultToPay,
            'isRequested'  => $worker->requested_payout !== null,
            'toPay'        => $toPay,
            'toBank'       => $toBank,
            'balance'      => $balance,
            'balanceAfter' => $balance + $toBank,
            'maxRequest'   => $this->maxRequest($accrued, $balance + $recalc),
            'requestStep'  => (int) config('salary.request_step'),
            'payouts'      => $worker->salaryPayouts()->latest('period_to')->limit(10)->get(),
            'adjustments'  => $worker->piggyBankEntries()->adjustments()->with('createdBy')
                ->latest('id')->limit(10)->get(),
        ];
    }

    /**
     * Сумма к выдаче на открытый период. null — вернуть по умолчанию.
     * Работник — шаг и предел из конфига; администратор — без ограничений.
     */
    public function setRequest(Worker $worker, ?float $amount, User $actor): void
    {
        if ($amount !== null && ! $actor->isAdmin()) {
            [$from, $to] = $this->openPeriod($worker);
            if (! $this->isManageable($from, $to)) {
                throw ValidationException::withMessages([
                    'amount' => 'Сумму к выдаче можно менять с четверга ' . config('salary.cutoff_time') . '.',
                ]);
            }

            $accrued = $this->accrued($worker, $from, $to);
            if ($accrued <= 0) {
                throw ValidationException::withMessages(['amount' => 'За невыданный период нет начислений.']);
            }

            $step = (int) config('salary.request_step');
            if (fmod($amount, $step) != 0) {
                throw ValidationException::withMessages(['amount' => "Сумма должна быть кратна {$step} ₽."]);
            }

            $max = $this->maxRequest($accrued, $this->piggyBalance($worker) + $this->recalc($worker));
            if ($amount > $max) {
                throw ValidationException::withMessages([
                    'amount' => 'Не больше ' . number_format($max, 0, ',', ' ') . ' ₽ (начислено + копилка).',
                ]);
            }
        }

        $worker->update(['requested_payout' => $amount]);
    }

    /**
     * «Зарплата выдана»: начисленное за неделю фиксируется на момент нажатия, перерасчёт
     * выданных недель переносится сюда, разница с выданным проводится в копилку.
     * Приёмки Чт 21:00–23:59 после нажатия подхватит перерасчёт следующей выдачи.
     */
    public function markPaid(
        Worker $worker,
        float $cash,
        ?float $card,
        ?float $tax,
        ?string $comment,
        User $actor,
    ): SalaryPayout {
        return DB::transaction(function () use ($worker, $cash, $card, $tax, $comment, $actor) {
            $worker = Worker::whereKey($worker->id)->lockForUpdate()->firstOrFail();

            [$from, $to] = $this->openPeriod($worker);
            if (! $this->isManageable($from, $to)) {
                throw ValidationException::withMessages([
                    'paid' => 'Зарплата за эту неделю уже выдана. Аванс — через корректировку копилки.',
                ]);
            }

            // Перерасчёт по каждой выдаче отдельно: accounted должен совпасть с её периодом.
            $recalc = 0.0;
            foreach ($this->recalcPayouts($worker) as $prev) {
                $actual = round($this->accrued($worker, $prev->period_from, $prev->period_to), 2);
                if ($actual != $prev->accounted) {
                    $recalc += $actual - $prev->accounted;
                    $prev->update(['accounted' => $actual]);
                }
            }

            $accrued = round($this->accrued($worker, $from, $to), 2);
            $recalc  = round($recalc, 2);
            $total   = round($cash + ($card ?? 0) + ($tax ?? 0), 2);
            $toBank  = round($accrued + $recalc - $total, 2);

            $payout = $worker->salaryPayouts()->create([
                'period_from'      => $from,
                'period_to'        => $to,
                'accrued'          => $accrued,
                'requested_amount' => $worker->requested_payout,
                'paid_cash'        => $cash,
                'paid_card'        => $card ?: null,
                'paid_tax'         => $tax ?: null,
                'paid_total'       => $total,
                'recalc'           => $recalc,
                'accounted'        => $accrued,
                'to_bank'          => $toBank,
                'paid_at'          => now(),
                'paid_by_user_id'  => $actor->id,
                'comment'          => $comment,
            ]);

            if ($toBank != 0) {
                $worker->piggyBankEntries()->create([
                    'amount'             => $toBank,
                    'type'               => PiggyBankEntry::TYPE_PAYOUT,
                    'salary_payout_id'   => $payout->id,
                    'created_by_user_id' => $actor->id,
                ]);
            }

            $worker->update(['requested_payout' => null]);

            return $payout;
        });
    }

    /** Корректировка копилки администратором: аванс, долг (−), стартовый остаток (+). */
    public function adjustPiggy(Worker $worker, float $amount, string $comment, User $actor): PiggyBankEntry
    {
        return $worker->piggyBankEntries()->create([
            'amount'             => round($amount, 2),
            'type'               => PiggyBankEntry::TYPE_ADJUSTMENT,
            'comment'            => $comment,
            'created_by_user_id' => $actor->id,
        ]);
    }

    /** Выдачи, чьи недели ещё пересчитываются (закончились не раньше recalc_weeks назад). */
    private function recalcPayouts(Worker $worker): Collection
    {
        return $worker->salaryPayouts()
            ->where('period_to', '>=', Carbon::now()->subWeeks((int) config('salary.recalc_weeks')))
            ->orderBy('period_from')
            ->get();
    }

    /**
     * Начислено за [from, to). whereBetween дашборда включает правую границу —
     * сдвигаем на секунду, чтобы лог ровно в момент отсечки не попал в два периода.
     */
    private function accrued(Worker $worker, Carbon $from, Carbon $to): float
    {
        $data = $this->dashboard->getDashboardData($worker->id, $from, $to->copy()->subSecond());

        return (float) $data['totalPay'];
    }
}
