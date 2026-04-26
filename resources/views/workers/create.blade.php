@extends('layouts.app')

@section('title', 'Новый работник')

@section('content')
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2 mb-0">👤 Новый работник</h1>
            <a href="{{ route('workers.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> К списку работников
            </a>
        </div>

        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow-sm">
                    <div class="card-body p-4">

                        @if($errors->any())
                            <div class="alert alert-danger">
                                <ul class="mb-0">
                                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                                </ul>
                            </div>
                        @endif

                        <form method="POST" action="{{ route('workers.store') }}">
                            @csrf

                            <div class="mb-3">
                                <label for="name" class="form-label">Имя <span class="text-danger">*</span></label>
                                <input type="text"
                                       class="form-control @error('name') is-invalid @enderror"
                                       id="name" name="name"
                                       value="{{ old('name') }}"
                                       required autofocus>
                                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Должность <span class="text-danger">*</span></label>
                                @error('positions')<div class="text-danger small mb-1">{{ $message }}</div>@enderror
                                <div class="border rounded p-2 @error('positions') border-danger @enderror">
                                    @foreach(App\Models\Worker::POSITIONS as $pos)
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox"
                                                   name="positions[]" value="{{ $pos }}"
                                                   id="pos_{{ $loop->index }}"
                                                   {{ in_array($pos, old('positions', [])) ? 'checked' : '' }}>
                                            <label class="form-check-label" for="pos_{{ $loop->index }}">{{ $pos }}</label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="department_id" class="form-label">Отдел</label>
                                <select class="form-select @error('department_id') is-invalid @enderror"
                                        id="department_id" name="department_id">
                                    <option value="">— Выберите отдел —</option>
                                    @foreach($departments as $department)
                                        <option value="{{ $department->id }}" {{ old('department_id') == $department->id ? 'selected' : '' }}>
                                            {{ $department->name }}
                                            @if($department->code)({{ $department->code }})@endif
                                        </option>
                                    @endforeach
                                </select>
                                @error('department_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email"
                                       class="form-control @error('email') is-invalid @enderror"
                                       id="email" name="email"
                                       value="{{ old('email') }}">
                                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="mb-3">
                                <label for="phone" class="form-label">Телефон</label>
                                <input type="text"
                                       class="form-control @error('phone') is-invalid @enderror"
                                       id="phone" name="phone"
                                       value="{{ old('phone') }}">
                                @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-person-plus"></i> Создать работника
                                </button>
                                <a href="{{ route('workers.index') }}" class="btn btn-outline-secondary">Отмена</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
