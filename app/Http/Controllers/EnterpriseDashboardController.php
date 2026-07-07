<?php

namespace App\Http\Controllers;

use App\Services\WorkerDashboardService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class EnterpriseDashboardController extends Controller
{
    public function __construct(private readonly WorkerDashboardService $service) {}

    public function index(Request $request)
    {
        [$defaultFrom, $defaultTo] = $this->service->getDefaultWeekRange();

        $dateFrom = $request->filled('date_from')
            ? Carbon::parse($request->input('date_from'))->startOfDay()
            : $defaultFrom;

        $dateTo = $request->filled('date_to')
            ? Carbon::parse($request->input('date_to'))->endOfDay()
            : $defaultTo;

        $data = $this->service->getEnterpriseDashboardData($dateFrom, $dateTo);

        return view('admin.enterprise-dashboard', $data);
    }
}
