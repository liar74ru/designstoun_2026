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
                                        id="position" name="position" required>
                                    @foreach(App\Models\Worker::POSITIONS as $pos)
                                        <option value="{{ $pos }}" {{ old('position', $worker->position) === $pos ? 'selected' : '' }}>
                                            {{ $pos }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('position')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <!-- Отделы работника -->
                            @include('partials.worker-departments')

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

                @if(auth()->user()->isAdmin())
                    <div class="card shadow-sm mt-3">
                        <div class="card-body p-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                            @if($worker->isArchived())
                                <div>
                                    <span class="badge bg-secondary">
                                        <i class="bi bi-archive"></i> В архиве
                                    </span>
                                    <span class="text-muted small ms-1">
                                        с {{ $worker->archived_at->format('d.m.Y') }}
                                    </span>
                                </div>
                            @else
                                <div class="text-muted small">
                                    Работник уволен? Переведите его в архив — он сохранится в истории,
                                    но не будет предлагаться при выборе в производственных формах.
                                </div>
                            @endif

                            <div class="d-flex gap-2 flex-wrap">
                                @if($worker->isArchived())
                                    <form method="POST" action="{{ route('workers.restore', $worker) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn btn-outline-success">
                                            <i class="bi bi-arrow-counterclockwise"></i> Вернуть из архива
                                        </button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('workers.archive', $worker) }}"
                                          onsubmit="return confirm('Перевести работника {{ $worker->name }} в архив?')">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn btn-outline-warning">
                                            <i class="bi bi-archive"></i> В архив
                                        </button>
                                    </form>
                                @endif

                                <form method="POST" action="{{ route('workers.destroy', $worker) }}"
                                      onsubmit="return confirm('Вы уверены, что хотите удалить работника {{ $worker->name }}? Действие необратимо.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger">
                                        <i class="bi bi-trash"></i> Удалить
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
