@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h3>Создание пользователя для {{ $worker->name }}</h3>
                    </div>

                    <div class="card-body">
                        {{-- Предупреждение если нет телефона --}}
                        @if(!$worker->phone)
                            <div class="alert alert-danger">
                                <strong>Ошибка!</strong> У работника не указан телефон.
                                <a href="{{ route('workers.edit', $worker) }}" class="alert-link">
                                    Добавить телефон
                                </a>
                            </div>
                        @endif

                        {{-- Информация о работнике --}}
                        <div class="mb-4 p-3 bg-light rounded">
                            <h5>Данные работника:</h5>
                            <table class="table table-sm table-borderless mb-0">
                                <tr>
                                    <th style="width: 120px;">Имя:</th>
                                    <td>{{ $worker->name }}</td>
                                </tr>
                                <tr>
                                    <th>Должность:</th>
                                    <td>{{ $worker->position }}</td>
                                </tr>
                                <tr>
                                    <th>Телефон:</th>
                                    <td>
                                        <span class="fw-bold">{{ $worker->phone ?? 'Не указан' }}</span>
                                        @if($worker->phone)
                                            <span class="badge bg-info">будет использован для входа</span>
                                        @endif
                                    </td>
                                </tr>
                                @if($worker->department)
                                    <tr>
                                        <th>Отдел:</th>
                                        <td>{{ $worker->department->name }}</td>
                                    </tr>
                                @endif
                            </table>
                        </div>

                        <form method="POST" action="{{ route('workers.store-user', $worker) }}">
                            @csrf

                            {{-- Блок с телефоном (только для информации) --}}
                            @if($worker->phone)
                                <div class="mb-3">
                                    <label class="form-label">Телефон для входа</label>
                                    <div class="form-control bg-light" readonly>
                                        {{ $worker->phone }}
                                        <small class="text-muted">(будет использован автоматически)</small>
                                    </div>
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle"></i>
                                        Телефон будет взят из данных работника
                                    </small>
                                </div>
                            @endif

                            {{-- Только поле для пароля --}}
                            <div class="mb-3">
                                <label for="password" class="form-label">Пароль *</label>
                                <input type="password"
                                       class="form-control @error('password') is-invalid @enderror"
                                       id="password"
                                       name="password"
                                       placeholder="Введите пароль"
                                       {{ $worker->phone ? 'required' : 'disabled' }}
                                       minlength="6">
                                @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                <small class="text-muted">Минимум 6 символов</small>
                            </div>

                            {{-- Кнопки --}}
                            <div class="mb-3">
                                <button type="submit" class="btn btn-primary"
                                    {{ !$worker->phone ? 'disabled' : '' }}>
                                    <i class="fas fa-user-plus"></i> Создать пользователя
                                </button>
                                <a href="{{ route('workers.index') }}" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Отмена
                                </a>
                            </div>

                            {{-- Подсказка если нет телефона --}}
                            @if(!$worker->phone)
                                <div class="alert alert-warning mt-3">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    Для создания пользователя необходимо указать телефон работника.
                                    <a href="{{ route('workers.edit', $worker) }}" class="alert-link">
                                        Перейти к редактированию
                                    </a>
                                </div>
                            @endif
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        .bg-light {
            background-color: #f8f9fa !important;
        }
        .table-borderless td, .table-borderless th {
            border: none;
            padding: 0.3rem 0;
        }
    </style>
@endpush
