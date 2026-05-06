@extends('layouts.app')

@section('title', 'Профиль — ' . $worker->name)

@section('content')
    <div class="container py-4" style="max-width:520px">

        {{-- Заголовок --}}
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h4 mb-0 fw-bold">Профиль</h1>
            <div class="d-flex gap-2">
                <a href="{{ route('workers.index') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> К списку
                </a>
                <a href="{{ route('logout') }}"
                   onclick="event.preventDefault(); document.getElementById('logout-form-profile').submit();"
                   class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-box-arrow-right"></i> Выйти
                </a>
                <form id="logout-form-profile" action="{{ route('logout') }}" method="POST" class="d-none">
                    @csrf
                </form>
            </div>
        </div>

        @include('partials.alerts')

        @if($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        {{-- Карточка профиля --}}
        <div class="card shadow-sm mb-3">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center flex-shrink-0"
                         style="width:52px;height:52px">
                        <i class="bi bi-person-fill text-white fs-4"></i>
                    </div>
                    <div class="flex-grow-1 min-width-0">
                        <div class="fw-bold fs-6 text-truncate">{{ $worker->name }}</div>
                        <div class="small text-muted mb-1">
                            <i class="bi bi-telephone"></i> {{ $worker->phone ?? '—' }}
                        </div>
                        @php
                            $positionColors = [
                                'Администратор'    => ['bg' => '#212529', 'color' => '#fff'],
                                'Мастер'           => ['bg' => '#e6a817', 'color' => '#212529'],
                                'Помощник мастера' => ['bg' => '#fd7e14', 'color' => '#fff'],
                                'Работник'         => ['bg' => '#0d6efd', 'color' => '#fff'],
                                'Разнорабочий'     => ['bg' => '#6c757d', 'color' => '#fff'],
                            ];
                        @endphp
                        <div class="d-flex flex-wrap gap-1">
                            @if($worker->position)
                                @php $c = $positionColors[$worker->position] ?? ['bg' => '#dee2e6', 'color' => '#212529']; @endphp
                                <span class="badge" style="background:{{ $c['bg'] }};color:{{ $c['color'] }}">{{ $worker->position }}</span>
                            @else
                                <span class="text-muted small">—</span>
                            @endif
                            @if($worker->user->is_admin)
                                <span class="badge bg-danger">Админ</span>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="mt-2 pt-2 border-top">
                    <small class="text-muted">
                        <i class="bi bi-info-circle"></i> Телефон используется как логин. Изменить можно в карточке работника.
                    </small>
                </div>
            </div>
        </div>

        {{-- Форма смены пароля --}}
        <div class="card shadow-sm">
            <div class="card-header bg-white py-2">
                <span class="fw-semibold"><i class="bi bi-lock text-muted me-1"></i>Изменить пароль</span>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="{{ route('workers.update-user', $worker) }}">
                    @csrf
                    @method('PUT')

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Новый пароль <span class="text-danger">*</span></label>
                        <input type="password"
                               name="password"
                               class="form-control @error('password') is-invalid @enderror"
                               placeholder="Введите новый пароль"
                               autofocus>
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Повторите пароль <span class="text-danger">*</span></label>
                        <input type="password"
                               name="password_confirmation"
                               class="form-control"
                               placeholder="Повторите новый пароль">
                    </div>

                    @if(auth()->user()->is_admin)
                        <div class="mb-3 p-3 border rounded bg-light">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox"
                                       name="is_admin" id="is_admin" value="1"
                                    {{ old('is_admin', $worker->user->is_admin) ? 'checked' : '' }}>
                                <label class="form-check-label fw-semibold" for="is_admin">
                                    Права администратора
                                </label>
                            </div>
                            <small class="text-muted">Полный доступ ко всем разделам системы</small>
                        </div>
                    @endif

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-save"></i> Сохранить пароль
                    </button>
                </form>
            </div>
        </div>

    </div>
@endsection
