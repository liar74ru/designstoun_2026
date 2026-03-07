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
                        <div class="mb-4">
                            <h5>Данные работника:</h5>
                            <p><strong>Имя:</strong> {{ $worker->name }}</p>
                            <p><strong>Должность:</strong> {{ $worker->position }}</p>
                            <p><strong>Телефон:</strong> {{ $worker->phone ?? 'Не указан' }}</p>
                        </div>

                        <form method="POST" action="{{ route('workers.store-user', $worker) }}">
                            @csrf

                            <div class="mb-3">
                                <label for="phone" class="form-label">Телефон для входа *</label>
                                <input type="tel"
                                       class="form-control @error('phone') is-invalid @enderror"
                                       id="phone"
                                       name="phone"
                                       value="{{ old('phone', $worker->phone) }}"
                                       required>
                                @error('phone')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <label for="password" class="form-label">Пароль *</label>
                                <input type="password"
                                       class="form-control @error('password') is-invalid @enderror"
                                       id="password"
                                       name="password"
                                       required>
                                @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <button type="submit" class="btn btn-primary">
                                    Создать пользователя
                                </button>
                                <a href="{{ route('workers.index') }}" class="btn btn-secondary">
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
