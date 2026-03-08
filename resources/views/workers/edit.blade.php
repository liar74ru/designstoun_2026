@extends('layouts.app')

@section('title', 'Редактирование работника')

@section('content')
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2 mb-0">✏️ Редактирование работника</h1>

            <a href="{{ route('workers.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> К списку работников
            </a>
        </div>

        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <form method="POST" action="{{ route('workers.update', $worker) }}">
                            @csrf
                            @method('PUT')

                            <!-- Поле Имя -->
                            <div class="mb-3">
                                <label for="name" class="form-label">Имя <span class="text-danger">*</span></label>
                                <input type="text"
                                       class="form-control @error('name') is-invalid @enderror"
                                       id="name"
                                       name="name"
                                       value="{{ old('name', $worker->name) }}"
                                       required>
                                @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Поле Должность -->
                            <div class="mb-3">
                                <label for="position" class="form-label">Должность <span class="text-danger">*</span></label>
                                <select class="form-select @error('position') is-invalid @enderror"
                                        id="position"
                                        name="position"
                                        required>
                                    <option value="">— Выберите должность —</option>
                                    @foreach(App\Models\Worker::POSITIONS as $position)
                                        <option value="{{ $position }}"
                                            {{ old('position', $worker->position) == $position ? 'selected' : '' }}>
                                            {{ $position }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('position')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Поле Отдел -->
                            <div class="mb-3">
                                <label for="department_id" class="form-label">Отдел</label>
                                <select class="form-select @error('department_id') is-invalid @enderror"
                                        id="department_id"
                                        name="department_id">
                                    <option value="">— Выберите отдел —</option>
                                    @foreach($departments as $department)
                                        <option value="{{ $department->id }}"
                                            {{ old('department_id', $worker->department_id) == $department->id ? 'selected' : '' }}>
                                            {{ $department->name }}
                                            @if($department->code)
                                                ({{ $department->code }})
                                            @endif
                                        </option>
                                    @endforeach
                                </select>
                                @error('department_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Поле Email -->
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email"
                                       class="form-control @error('email') is-invalid @enderror"
                                       id="email"
                                       name="email"
                                       value="{{ old('email', $worker->email) }}">
                                @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Поле Телефон -->
                            <div class="mb-3">
                                <label for="phone" class="form-label">Телефон</label>
                                <input type="text"
                                       class="form-control @error('phone') is-invalid @enderror"
                                       id="phone"
                                       name="phone"
                                       value="{{ old('phone', $worker->phone) }}">
                                @error('phone')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Кнопки -->
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-check-circle"></i> Сохранить изменения
                                    </button>
                                    <a href="{{ route('workers.index') }}" class="btn btn-outline-secondary">
                                        Отмена
                                    </a>
                                </div>
                                @if($worker->user)
                                    <a href="{{ route('workers.edit-user', $worker) }}" class="btn btn-outline-secondary">
                                        <i class="bi bi-key"></i> Учётная запись
                                    </a>
                                @else
                                    <a href="{{ route('workers.create-user', $worker) }}" class="btn btn-outline-primary">
                                        <i class="bi bi-person-plus"></i> Создать аккаунт
                                    </a>
                                @endif
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
