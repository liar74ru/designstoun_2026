<?php

namespace App\Http\Controllers;

use App\Services\SalaryPayoutReportService;
use App\Support\QueryParam;
use Illuminate\Http\Request;

/** Сводка выплат за неделю по отделам — только админ (операция salary-payouts). */
class SalaryPayoutReportController extends Controller
{
    public function __construct(private readonly SalaryPayoutReportService $service) {}

    public function index(Request $request)
    {
        return view('salary-payouts.index', $this->service->report(QueryParam::date($request, 'week')));
    }
}
