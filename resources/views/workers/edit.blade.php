@extends('layouts.app')

@section('title', 'Редактирование работника')

@section('content')
    <div class="container py-4">
        <!-- Заголовок и навигация -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2 mb-0">✏️ Редактирование работника</h1>

            <a href="{{ route('workers.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> К списку работников
            </a>
        </div>

        <!-- Форма редактирования -->
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <form method="POST" action="{{ route('workers.update', $worker) }}">
                            @csrf
                            @method('PUT')

                            <!-- Поле Имя (обязательное) -->
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
                                <label for="position" class="form-label">Должность</label>
                                <input type="text"
                                       class="form-control @error('position') is-invalid @enderror"
                                       id="position"
                                       name="position"
                                       value="{{ old('position', $worker->position) }}">
                                @error('position')
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

                            <!-- Информация о создании/обновлении -->
                            <div class="alert alert-light small mb-3">
                                <div>Создан: {{ $worker->created_at->format('d.m.Y H:i') }}</div>
                                @if($worker->updated_at != $worker->created_at)
                                    <div>Обновлен: {{ $worker->updated_at->format('d.m.Y H:i') }}</div>
                                @endif
                            </div>

                            <!-- Кнопки -->
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-circle"></i> Обновить
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
