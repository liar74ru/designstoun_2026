<?php

namespace App\Http\Controllers;

use App\Models\Worker;
use App\Services\WorkerDashboardService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// рефакторинг v2 от 26.04.2026 — controller → service
class CutterWorkerDashboardController extends Controller
{
    public function __construct(private readonly WorkerDashboardService $service) {}

    public function showWorker(Request $request, ?int $workerId = null)
    {
        return $this->show($request, $workerId, isMaster: false);
    }

    public function showMaster(Request $request, ?int $workerId = null)
    {
        return $this->show($request, $workerId, isMaster: true);
    }

    private function show(Request $request, ?int $workerId, bool $isMaster)
    {
        $user = Auth::user();

        if ($user->isAdmin() && $workerId) {
            $worker = Worker::findOrFail($workerId);
        } elseif ($user->isMaster() && $workerId) {
            $worker = Worker::findOrFail($workerId);
            $masterDeptId = $user->worker?->department_id;
            if ($masterDeptId && $worker->department_id !== $masterDeptId) {
                abort(403, 'Нет доступа к дашборду этого работника');
            }
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

        $data = $this->service->getDashboardData($worker->id, $isMaster, $dateFrom, $dateTo);

        $receptions = $data['logs'];

        return view('workers.dashboard.show', array_merge(
            compact('worker', 'isMaster', 'receptions', 'dateFrom', 'dateTo'),
            $data,
        ));
    }
}
