<?php

namespace App\Http\Controllers;

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

        return view('workers.index', compact('workers', 'departments', 'positions'));
    }

    public function create()
    {
        $departments = Department::orderBy('name')->get();

        return view('workers.create', compact('departments'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'positions'     => 'required|array|min:1',
            'positions.*'   => 'string|in:'.implode(',', Worker::POSITIONS),
            'email'         => 'nullable|email|unique:workers,email',
            'phone'         => 'nullable|string|max:50',
            'department_id' => 'nullable|exists:departments,id',
        ]);

        Worker::create($validated);

        return redirect()->route('workers.index')
            ->with('success', 'Работник успешно добавлен');
    }

    public function show(Worker $worker)
    {
        return view('workers.show', compact('worker'));
    }

    public function edit(Worker $worker)
    {
        $departments = Department::orderBy('name')->get();

        return view('workers.edit', compact('worker', 'departments'));
    }

    public function update(Request $request, Worker $worker)
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'positions'     => 'required|array|min:1',
            'positions.*'   => 'string|in:'.implode(',', Worker::POSITIONS),
            'email'         => 'nullable|email|unique:workers,email,'.$worker->id,
            'phone'         => 'nullable|string|max:50',
            'department_id' => 'nullable|exists:departments,id',
        ]);

        $worker->update($validated);

        $this->service->syncPhoneToUser($worker, $validated['phone'] ?? null);

        return redirect()->route('workers.index')
            ->with('success', 'Работник успешно обновлен');
    }

    public function destroy(Worker $worker)
    {
        $worker->delete();

        return redirect()->route('workers.index')
            ->with('success', 'Работник успешно удален');
    }

    public function createUser(Worker $worker)
    {
        if ($worker->user) {
            return redirect()->route('workers.index')
                ->with('error', 'У этого работника уже есть учетная запись');
        }

        return view('workers.create-user', compact('worker'));
    }

    public function editUser(Worker $worker)
    {
        if (!$worker->user) {
            return redirect()->route('workers.index')
                ->with('error', 'У этого работника нет учётной записи');
        }

        return view('workers.edit-user', compact('worker'));
    }

    public function storeUser(Request $request, Worker $worker)
    {
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
