<?php

namespace App\Http\Controllers;

use App\Models\Worker;
use App\Models\Department;
use Illuminate\Http\Request;

class WorkerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $workers = Worker::with('department')->orderBy('name')->paginate(15);
        return view('workers.index', compact('workers'));
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
            'position' => 'required|string|in:' . implode(',', Worker::POSITIONS),
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
            'position' => 'required|string|in:' . implode(',', Worker::POSITIONS),
            'email' => 'nullable|email|unique:workers,email,' . $worker->id,
            'phone' => 'nullable|string|max:50',
            'department_id' => 'nullable|exists:departments,id',
        ]);

        $worker->update($validated);

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
}
