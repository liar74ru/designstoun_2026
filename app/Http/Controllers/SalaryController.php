<?php

namespace App\Http\Controllers;

use App\Http\Requests\Salary\HideBalanceRequest;
use App\Http\Requests\Salary\PiggyAdjustRequest;
use App\Http\Requests\Salary\SalaryPaidRequest;
use App\Http\Requests\Salary\SalaryPayoutUpdateRequest;
use App\Http\Requests\Salary\SalaryRequestRequest;
use App\Models\SalaryPayout;
use App\Models\Worker;
use App\Services\SalaryService;

/** Копилка и выдача зарплаты — блок на странице выработки работника. */
class SalaryController extends Controller
{
    public function __construct(private readonly SalaryService $service) {}

    public function updateRequest(SalaryRequestRequest $request, Worker $worker)
    {
        $amount = $request->filled('amount') && ! $request->boolean('reset')
            ? (float) $request->input('amount')
            : null;

        $this->service->setRequest($worker, $amount, $request->user());

        return back()->with('success', $amount === null
            ? 'Сумма к выдаче — по умолчанию'
            : 'Сумма к выдаче сохранена');
    }

    public function toggleHideBalance(HideBalanceRequest $request, Worker $worker)
    {
        $this->service->setHideBalance($worker, $request->boolean('hide'));

        return back();
    }

    public function markPaid(SalaryPaidRequest $request, Worker $worker)
    {
        $this->service->markPaid(
            $worker,
            (float) $request->input('paid_cash', 0),
            $request->filled('paid_card') ? (float) $request->input('paid_card') : null,
            $request->filled('paid_tax') ? (float) $request->input('paid_tax') : null,
            $request->input('comment'),
            $request->user(),
        );

        return back()->with('success', 'Зарплата отмечена как выданная');
    }

    public function updatePayout(SalaryPayoutUpdateRequest $request, Worker $worker, SalaryPayout $salaryPayout)
    {
        $this->service->updateLastPayout(
            $worker,
            $salaryPayout,
            (float) $request->input('edit_cash', 0),
            $request->filled('edit_card') ? (float) $request->input('edit_card') : null,
            $request->filled('edit_tax') ? (float) $request->input('edit_tax') : null,
            $request->input('edit_comment'),
            $request->user(),
        );

        return back()->with('success', 'Выдача исправлена');
    }

    public function adjustPiggy(PiggyAdjustRequest $request, Worker $worker)
    {
        $this->service->adjustPiggy(
            $worker,
            (float) $request->input('piggy_amount'),
            $request->input('piggy_comment'),
            $request->user(),
        );

        return back()->with('success', 'Копилка скорректирована');
    }
}
