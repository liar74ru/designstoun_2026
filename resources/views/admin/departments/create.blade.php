@extends('layouts.app')

@section('title', 'Новый отдел')

@section('content')
<div class="container py-3" style="max-width:600px">

    <x-page-header
        title="Новый отдел"
        mobileTitle="Новый отдел"
        :backUrl="route('admin.settings.index')"
        backLabel="К настройкам" />

    @include('partials.alerts')

    <div class="card shadow-sm">
        <div class="card-body p-4">
            <form method="POST" action="{{ route('admin.departments.store') }}">
                @csrf

                <div class="mb-3">
                    <label for="name" class="form-label fw-semibold">
                        Название <span class="text-danger">*</span>
                    </label>
                    <input type="text"
                           id="name" name="name"
                           value="{{ old('name') }}"
                           class="form-control @error('name') is-invalid @enderror"
                           style="border-radius:.4rem"
                           required autofocus>
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="code" class="form-label fw-semibold">Код</label>
                    <input type="text"
                           id="code" name="code"
                           value="{{ old('code') }}"
                           class="form-control @error('code') is-invalid @enderror"
                           style="border-radius:.4rem">
                    @error('code')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-4">
                    <label for="description" class="form-label fw-semibold">Описание</label>
                    <textarea id="description" name="description" rows="3"
                              class="form-control @error('description') is-invalid @enderror"
                              style="border-radius:.4rem">{{ old('description') }}</textarea>
                    @error('description')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Создать отдел
                    </button>
                    <a href="{{ route('admin.settings.index') }}" class="btn btn-outline-secondary">
                        Отмена
                    </a>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection
