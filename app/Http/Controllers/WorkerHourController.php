<?php

namespace App\Http\Controllers;

use App\Http\Requests\Salary\WorkerHourRequest;
use App\Models\Worker;
use App\Models\WorkerHourEntry;
use App\Services\WorkerHourService;
use Illuminate\Support\Facades\Auth;

/** Часы ремонтных работ на дашборде работника. */
class WorkerHourController extends Controller
{
    public function __construct(private readonly WorkerHourService $service) {}

    public function store(WorkerHourRequest $request, Worker $worker)
    {
        $this->service->create($worker, $request->validated(), $request->user());

        return back()->with('success', 'Часы добавлены');
    }

    public function edit(WorkerHourEntry $workerHourEntry)
    {
        $this->authorize('modify', $workerHourEntry);

        return view('worker-hours.edit', [
            'entry'      => $workerHourEntry,
            'unpaidFrom' => $this->service->unpaidFrom($workerHourEntry->worker),
            'backUrl'    => $this->dashboardUrl($workerHourEntry->worker),
        ]);
    }

    public function update(WorkerHourRequest $request, WorkerHourEntry $workerHourEntry)
    {
        $this->service->update($workerHourEntry, $request->validated());

        return redirect($this->dashboardUrl($workerHourEntry->worker))->with('success', 'Часы сохранены');
    }

    public function destroy(WorkerHourEntry $workerHourEntry)
    {
        $this->authorize('modify', $workerHourEntry);

        $this->service->delete($workerHourEntry);

        return back()->with('success', 'Запись удалена');
    }

    /** Свой дашборд — /my-work, чужой (мастер, админ) — по id. */
    private function dashboardUrl(Worker $worker): string
    {
        return Auth::user()->worker_id === $worker->id
            ? route('worker.dashboard')
            : route('worker.dashboard.by-id', $worker->id);
    }
}
