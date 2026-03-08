@extends('layouts.app')

@section('title', 'Учётная запись — ' . $worker->name)

@section('content')
    <div class="container py-4" style="max-width:560px">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0">🔑 Учётная запись работника</h1>
            <a href="{{ route('workers.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> К списку
            </a>
        </div>

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill"></i> {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        {{-- Информация о работнике --}}
        <div class="card border-0 bg-light mb-4">
            <div class="card-body py-3">
                <div class="row text-center">
                    <div class="col">
                        <div class="text-muted small">Работник</div>
                        <div class="fw-semibold">{{ $worker->name }}</div>
                    </div>
                    <div class="col">
                        <div class="text-muted small">Должность</div>
                        <div class="fw-semibold">{{ $worker->position ?? '—' }}</div>
                    </div>
                    <div class="col">
                        <div class="text-muted small">ID пользователя</div>
                        <div class="fw-semibold">#{{ $worker->user->id }}</div>
                    </div>
                    <div class="col">
                        <div class="text-muted small">Права</div>
                        <div>
                            @if($worker->user->is_admin)
                                <span class="badge bg-danger">Администратор</span>
                            @else
                                <span class="badge bg-secondary">Пользователь</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h5 class="mb-0">Изменить данные входа</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('workers.update-user', $worker) }}">
                    @csrf
                    @method('PUT')

                    {{-- Телефон --}}
                    <div class="mb-3">
                        <label class="form-label">Телефон (логин) <span class="text-danger">*</span></label>
                        <input type="text"
                               name="phone"
                               class="form-control @error('phone') is-invalid @enderror"
                               value="{{ old('phone', $worker->user->phone) }}"
                               required>
                        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Новый пароль --}}
                    <div class="mb-3">
                        <label class="form-label">Новый пароль</label>
                        <input type="password"
                               name="password"
                               class="form-control @error('password') is-invalid @enderror"
                               placeholder="Оставьте пустым если не менять">
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Повторите пароль</label>
                        <input type="password"
                               name="password_confirmation"
                               class="form-control"
                               placeholder="Оставьте пустым если не менять">
                    </div>

                    {{-- Права администратора --}}
                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox"
                                   name="is_admin" id="is_admin" value="1"
                                {{ old('is_admin', $worker->user->is_admin) ? 'checked' : '' }}>
                            <label class="form-check-label" for="is_admin">
                                Права администратора
                            </label>
                        </div>
                        <small class="text-muted">Администратор имеет полный доступ ко всем разделам</small>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Сохранить
                        </button>
                        <a href="{{ route('workers.index') }}" class="btn btn-outline-secondary">Отмена</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
