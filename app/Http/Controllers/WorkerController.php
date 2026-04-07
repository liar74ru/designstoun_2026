<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Http\Request;

class WorkerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Worker::with('department');

        // Мастер с отделом видит только работников своего отдела
        $authUser = auth()->user();
        if ($authUser->isMaster() && $authUser->worker?->department_id) {
            $query->where('department_id', $authUser->worker->department_id);
        }

        if ($request->filled('filter.position')) {
            $query->where('position', $request->input('filter.position'));
        }

        if ($request->filled('filter.department_id')) {
            $query->where('department_id', $request->input('filter.department_id'));
        }

        if ($request->filled('filter.has_account')) {
            if ($request->input('filter.has_account') == '1') {
                $query->whereHas('user');
            } else {
                $query->whereDoesntHave('user');
            }
        }

        $workers = $query
            ->orderByRaw("CASE position
                WHEN 'Директор'     THEN 1
                WHEN 'Мастер'       THEN 2
                WHEN 'Приёмщик'     THEN 3
                WHEN 'Пильщик'      THEN 4
                WHEN 'Галтовщик'    THEN 5
                WHEN 'Разнорабочий' THEN 6
                ELSE 7
            END")
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        $departments = Department::orderBy('name')->get();
        $positions = array_combine(Worker::POSITIONS, Worker::POSITIONS);

        return view('workers.index', compact('workers', 'departments', 'positions'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $departments = Department::orderBy('name')->get();

        return view('workers.create', compact('departments'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'position' => 'required|string|in:'.implode(',', Worker::POSITIONS),
            'email' => 'nullable|email|unique:workers,email',
            'phone' => 'nullable|string|max:50',
            'department_id' => 'nullable|exists:departments,id',
        ]);

        Worker::create($validated);

        return redirect()->route('workers.index')
            ->with('success', 'Работник успешно добавлен');
    }

    /**
     * Display the specified resource.
     */
    public function show(Worker $worker)
    {
        return view('workers.show', compact('worker'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Worker $worker)
    {
        $departments = Department::orderBy('name')->get();

        return view('workers.edit', compact('worker', 'departments'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Worker $worker)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'position' => 'required|string|in:'.implode(',', Worker::POSITIONS),
            'email' => 'nullable|email|unique:workers,email,'.$worker->id,
            'phone' => 'nullable|string|max:50',
            'department_id' => 'nullable|exists:departments,id',
        ]);

        $worker->update($validated);

        // Если изменился телефон — синхронизируем с привязанным user
        if ($worker->user && isset($validated['phone']) && $worker->user->phone !== $validated['phone']) {
            $worker->user->update(['phone' => $validated['phone']]);
        }

        return redirect()->route('workers.index')
            ->with('success', 'Работник успешно обновлен');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Worker $worker)
    {
        $worker->delete();

        return redirect()->route('workers.index')
            ->with('success', 'Работник успешно удален');
    }

    public function createUser(Worker $worker)
    {
        // Проверяем, нет ли уже пользователя для этого рабочего
        if ($worker->user) {
            return redirect()->route('workers.index')
                ->with('error', 'У этого работника уже есть учетная запись');
        }

        return view('workers.create-user', compact('worker'));
    }

    public function editUser(Worker $worker)
    {
        if (! $worker->user) {
            return redirect()->route('workers.index')
                ->with('error', 'У этого работника нет учётной записи');
        }

        return view('workers.edit-user', compact('worker'));
    }

    public function updateUser(Request $request, Worker $worker)
    {
        if (! $worker->user) {
            return redirect()->route('workers.index')
                ->with('error', 'У этого работника нет учётной записи');
        }

        // Телефон не меняем через эту форму — он синхронизируется из worker.phone
        // Меняем только пароль (обязателен) и is_admin (только для admin)
        $validated = $request->validate([
            'password' => 'required|string|min:6|confirmed',
            'is_admin' => 'boolean',
        ], [
            'password.required' => 'Введите новый пароль',
            'password.min' => 'Пароль должен быть не менее 6 символов',
            'password.confirmed' => 'Пароли не совпадают',
        ]);

        $updateData = [
            'password' => bcrypt($validated['password']),
            // Телефон всегда берём из worker — единственный источник правды
            'phone' => $worker->phone,
        ];

        // is_admin — только администратор может менять
        if (auth()->user()->is_admin) {
            $updateData['is_admin'] = $request->boolean('is_admin');
        }

        $worker->user->update($updateData);

        // Если сам пользователь меняет свой пароль (рабочий) — возвращаем на его дашборд
        if (auth()->user()->isWorker()) {
            return redirect()->route('worker.dashboard')
                ->with('success', 'Пароль успешно изменён');
        }

        return redirect()->route('workers.index')
            ->with('success', 'Учётная запись работника '.$worker->name.' обновлена');
    }

    public function storeUser(Request $request, Worker $worker)
    {
        if ($worker->user) {
            return redirect()->route('workers.index')
                ->with('error', 'У этого работника уже есть учетная запись');
        }

        // Проверяем, есть ли телефон у работника
        if (! $worker->phone) {
            return back()->with('error', 'У работника не указан телефон. Сначала добавьте телефон.');
        }

        // Проверяем, не занят ли телефон
        if (User::where('phone', $worker->phone)->exists()) {
            return back()->with('error', 'Этот телефон уже используется другим пользователем');
        }

        $validated = $request->validate([
            'password' => 'required|string|min:6',
        ]);

        // Создаем пользователя с телефоном из данных работника
        $user = User::create([
            'name' => $worker->name,
            'phone' => $worker->phone, // Берем телефон из работника
            'password' => bcrypt($validated['password']),
            'worker_id' => $worker->id,
            'is_admin' => false,
        ]);

        return redirect()->route('workers.index')
            ->with('success', 'Пользователь успешно создан для работника '.$worker->name);
    }
}
