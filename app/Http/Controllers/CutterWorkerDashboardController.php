<?php

namespace App\Http\Controllers;

use App\Models\Worker;
use App\Models\WorkerHourEntry;
use App\Services\SalaryService;
use App\Services\WorkerDashboardService;
use App\Services\WorkerHourService;
use App\Support\DepartmentSettings;
use App\Support\ProductSearch;
use App\Support\QueryParam;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// рефакторинг v2 от 26.04.2026 — controller → service
class CutterWorkerDashboardController extends Controller
{
    public function __construct(
        private readonly WorkerDashboardService $service,
        private readonly SalaryService $salaryService,
        private readonly WorkerHourService $hourService,
    ) {}

    /**
     * Выработка в обеих ролях (работник и мастер) с разбивкой по отделам.
     */
    public function show(Request $request, ?int $workerId = null)
    {
        $user = Auth::user();

        if ($workerId) {
            $worker = Worker::findOrFail($workerId);
            $this->authorize('viewDashboard', $worker);
        } else {
            abort_unless($user->worker_id, 403, 'Ваш аккаунт не привязан к работнику');
            $worker = $user->worker;
        }

        [$defaultFrom, $defaultTo] = $this->service->getDefaultWeekRange(fridayShowsPrevious: true);

        $dateFrom = QueryParam::date($request, 'date_from')?->startOfDay() ?? $defaultFrom;
        $dateTo   = QueryParam::date($request, 'date_to')?->endOfDay() ?? $defaultTo;

        $productIds = ProductSearch::idsFromRequest($request, 'filter.product_id', 'filter.product_search');

        $data = $this->service->getDashboardData($worker->id, $dateFrom, $dateTo, $productIds);

        $receptions = $data['logs'];

        // Деньги — только самому человеку и админу; мастер на чужой странице видит м².
        $canSeePay = $user->isAdmin() || $user->worker_id === $worker->id;

        // Копилка не зависит от выбранного периода: считается по невыданному.
        $salary      = $canSeePay ? $this->salaryService->summary($worker) : null;
        $expectedPay = $salary
            ? $this->salaryService->expectedPay($worker, $salary, $dateFrom, $dateTo, $data['totalPay'])
            : null;

        // Часы ремонтных работ добавляют сам работник и админ (WorkerHourEntryPolicy::create).
        // Нижней границы даты у админа нет — как в WorkerHourRequest.
        $canAddHours     = $user->can('create', [WorkerHourEntry::class, $worker]);
        $hourlyRate      = DepartmentSettings::hourlyRate($worker->department_id ? (int) $worker->department_id : null);
        $hoursUnpaidFrom = $canAddHours && !$user->isAdmin() ? $this->hourService->unpaidFrom($worker) : null;

        // Кнопка периода «За всё время»: с первой выработки или часов
        $firstActivity = $this->service->firstActivityDate($worker->id);
        // Блок часов свёрнут, если за текущую неделю часов нет
        $hoursOpen = $this->service->hasHoursInCurrentWeek($worker->id);

        return view('workers.dashboard.show', array_merge(
            compact('worker', 'receptions', 'dateFrom', 'dateTo', 'canSeePay', 'salary', 'expectedPay',
                'canAddHours', 'hourlyRate', 'hoursUnpaidFrom', 'firstActivity', 'hoursOpen'),
            $data,
        ));
    }
}
