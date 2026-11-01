<?php

namespace App\Http\Controllers;

use App\Services\WorkerDashboardService;
use App\Support\QueryParam;
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

        $dateFrom = QueryParam::date($request, 'date_from')?->startOfDay();
        $dateTo   = QueryParam::date($request, 'date_to')?->endOfDay();

        $departmentIds = QueryParam::ids($request, 'filter.department_id');
        $rawProductId  = QueryParam::id($request, 'filter.raw_product_id');
        $productId     = QueryParam::id($request, 'filter.product_id');
        $workerId      = QueryParam::id($request, 'filter.worker_id');

        // Не-админ (мастер/помощник) видит итоги только по своим отделам; админ — все (null).
        $restrictDepartmentIds = $request->user()->accessibleDepartmentIds();

        $data = $this->service->getEnterpriseDashboardData(
            $dateFrom, $dateTo, $departmentIds, $rawProductId, $productId, $restrictDepartmentIds, $workerId
        );

        return view('admin.enterprise-dashboard', $data);
    }
}
