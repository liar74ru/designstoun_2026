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
        // Первый заход без параметров → редирект на текущую неделю,
        // чтобы даты в фильтре совпадали с данными (и работала кнопка «Всё время»).
        if (empty($request->query())) {
            [$f, $t] = $this->service->getDefaultWeekRange();

            return redirect()->to(url()->current() . '?' . http_build_query([
                'date_from' => $f->format('Y-m-d'),
                'date_to'   => $t->format('Y-m-d'),
            ]));
        }

        $dateFrom = $request->filled('date_from')
            ? Carbon::parse($request->input('date_from'))->startOfDay()
            : null;

        $dateTo = $request->filled('date_to')
            ? Carbon::parse($request->input('date_to'))->endOfDay()
            : null;

        $departmentIds = array_filter((array) $request->input('filter.department_id', []));
        $rawProductId  = $request->input('filter.product_id') ?: null;

        $data = $this->service->getEnterpriseDashboardData($dateFrom, $dateTo, $departmentIds, $rawProductId);

        return view('admin.enterprise-dashboard', $data);
    }
}
