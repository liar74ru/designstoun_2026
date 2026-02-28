@extends('layouts.app')

@section('title', 'Добавление работника')

@section('content')
    <div class="container py-4">
        <!-- Заголовок и навигация -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2 mb-0">➕ Добавление работника</h1>

            <a href="{{ route('workers.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> К списку работников
            </a>
        </div>

        <!-- Форма добавления -->
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <form method="POST" action="{{ route('workers.store') }}">
                            @csrf

                            <!-- Поле Имя (обязательное) -->
                            <div class="mb-3">
                                <label for="name" class="form-label">Имя <span class="text-danger">*</span></label>
                                <input type="text"
                                       class="form-control @error('name') is-invalid @enderror"
                                       id="name"
                                       name="name"
                                       value="{{ old('name') }}"
                                       required>
                                @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Поле Должность -->
                            <div class="mb-3">
                                <label for="position" class="form-label">Должность</label>
                                <input type="text"
                                       class="form-control @error('position') is-invalid @enderror"
                                       id="position"
                                       name="position"
                                       value="{{ old('position') }}">
                                @error('position')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- НОВОЕ ПОЛЕ: Отдел (выпадающий список) -->
                            <div class="mb-3">
                                <label for="department_id" class="form-label">Отдел</label>
                                <select class="form-select @error('department_id') is-invalid @enderror"
                                        id="department_id"
                                        name="department_id">
                                    <option value="">— Выберите отдел —</option>
                                    @foreach($departments as $department)
                                        <option value="{{ $department->id }}"
                                            {{ old('department_id') == $department->id ? 'selected' : '' }}>
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
                                       value="{{ old('email') }}">
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
                                       value="{{ old('phone') }}">
                                @error('phone')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Кнопки -->
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-circle"></i> Сохранить
                                </button>
                                <a href="{{ route('workers.index') }}" class="btn btn-outline-secondary">
                                    Отмена
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
