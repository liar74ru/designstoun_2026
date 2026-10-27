<?php

namespace App\Http\Controllers;

use App\Models\Worker;
use App\Services\SalaryService;
use App\Services\WorkerDashboardService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// рефакторинг v2 от 26.04.2026 — controller → service
class CutterWorkerDashboardController extends Controller
{
    public function __construct(
        private readonly WorkerDashboardService $service,
        private readonly SalaryService $salaryService,
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

        [$defaultFrom, $defaultTo] = $this->service->getDefaultWeekRange();

        $dateFrom = $request->filled('date_from')
            ? Carbon::parse($request->input('date_from'))->startOfDay()
            : $defaultFrom;

        $dateTo = $request->filled('date_to')
            ? Carbon::parse($request->input('date_to'))->endOfDay()
            : $defaultTo;

        $data = $this->service->getDashboardData($worker->id, $dateFrom, $dateTo);

        $receptions = $data['logs'];

        // Деньги — только самому человеку и админу; мастер на чужой странице видит м².
        $canSeePay = $user->isAdmin() || $user->worker_id === $worker->id;

        // Копилка не зависит от выбранного периода: считается по невыданному.
        $salary = $canSeePay ? $this->salaryService->summary($worker) : null;

        return view('workers.dashboard.show', array_merge(
            compact('worker', 'receptions', 'dateFrom', 'dateTo', 'canSeePay', 'salary'),
            $data,
        ));
    }
}
