<?php

namespace App\Http\Controllers;

use App\Http\Requests\Worker\WorkerRequest;
use App\Models\Department;
use App\Models\Worker;
use App\Services\WorkerService;
use Illuminate\Http\Request;

// рефакторинг v2 от 26.04.2026 — controller → service
class WorkerController extends Controller
{
    public function __construct(private readonly WorkerService $service) {}

    public function index(Request $request)
    {
        $workers = $this->service
            ->buildIndexQuery($request->input('filter', []), auth()->user())
            ->paginate(15)
            ->withQueryString();

        $departments = Department::orderBy('name')->get();
        $positions   = array_combine(Worker::POSITIONS, Worker::POSITIONS);
        $status      = $request->input('filter.status', 'active');

        return view('workers.index', compact('workers', 'departments', 'positions', 'status'));
    }

    public function create(Request $request)
    {
        return view('workers.create', $this->service->formOptions($request->user()));
    }

    public function store(WorkerRequest $request)
    {
        $this->service->create($request->validated(), $request->user());

        return redirect()->route('workers.index')
            ->with('success', 'Работник успешно добавлен');
    }

    public function edit(Request $request, Worker $worker)
    {
        $this->authorize('modify', $worker);

        $worker->load('departments');

        return view('workers.edit', ['worker' => $worker] + $this->service->formOptions($request->user(), $worker));
    }

    public function update(WorkerRequest $request, Worker $worker)
    {
        $this->authorize('modify', $worker);

        $this->service->update($worker, $request->validated(), $request->user());

        return redirect()->route('workers.index')
            ->with('success', 'Работник успешно обновлен');
    }

    public function destroy(Worker $worker)
    {
        $this->authorize('modify', $worker);

        $worker->delete();

        return redirect()->route('workers.index')
            ->with('success', 'Работник успешно удален');
    }

    public function archive(Worker $worker)
    {
        $this->authorize('modify', $worker);

        $worker->archive();

        return redirect()->route('workers.index')
            ->with('success', 'Работник '.$worker->name.' переведён в архив');
    }

    public function restore(Worker $worker)
    {
        $this->authorize('modify', $worker);

        $worker->restore();

        return redirect()->route('workers.index')
            ->with('success', 'Работник '.$worker->name.' возвращён из архива');
    }

    public function createUser(Worker $worker)
    {
        $this->authorize('modify', $worker);

        if ($worker->user) {
            return redirect()->route('workers.index')
                ->with('error', 'У этого работника уже есть учетная запись');
        }

        return view('workers.create-user', compact('worker'));
    }

    public function editUser(Worker $worker)
    {
        $this->authorize('editSelf', $worker);

        if (!$worker->user) {
            return redirect()->route('workers.index')
                ->with('error', 'У этого работника нет учётной записи');
        }

        return view('workers.edit-user', compact('worker'));
    }

    public function storeUser(Request $request, Worker $worker)
    {
        $this->authorize('modify', $worker);

        if ($worker->user) {
            return redirect()->route('workers.index')
                ->with('error', 'У этого работника уже есть учетная запись');
        }

        $validated = $request->validate([
            'password' => 'required|string|min:6',
        ]);

        $result = $this->service->createUser($worker, $validated['password']);

        if (!$result['success']) {
            return back()->with('error', $result['message']);
        }

        return redirect()->route('workers.index')
            ->with('success', 'Пользователь успешно создан для работника '.$worker->name);
    }

    public function updateUser(Request $request, Worker $worker)
    {
        $this->authorize('editSelf', $worker);

        if (!$worker->user) {
            return redirect()->route('workers.index')
                ->with('error', 'У этого работника нет учётной записи');
        }

        $validated = $request->validate([
            'password' => 'required|string|min:6|confirmed',
            'is_admin' => 'boolean',
        ], [
            'password.required'  => 'Введите новый пароль',
            'password.min'       => 'Пароль должен быть не менее 6 символов',
            'password.confirmed' => 'Пароли не совпадают',
        ]);

        $this->service->updateUser(
            $worker,
            $validated['password'],
            $request->boolean('is_admin'),
            auth()->user()->is_admin,
        );

        if (auth()->user()->isWorker()) {
            return redirect()->route('worker.dashboard')
                ->with('success', 'Пароль успешно изменён');
        }

        return redirect()->route('workers.index')
            ->with('success', 'Учётная запись работника '.$worker->name.' обновлена');
    }
}
