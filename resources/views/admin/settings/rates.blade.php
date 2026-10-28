@extends('layouts.app')

@section('title', 'Ставки по умолчанию')

@section('content')
<div class="container py-3" style="max-width:720px">

    <x-page-header
        title="Ставки по умолчанию"
        mobileTitle="Ставки"
        :backUrl="route('admin.settings.index')"
        backLabel="К настройкам">
        <x-slot name="actions">
            @include('partials.help-button', ['page' => 'settings'])
        </x-slot>
        <x-slot name="mobileActions">
            @include('partials.help-button', ['page' => 'settings', 'class' => 'btn-outline-secondary btn-sm'])
        </x-slot>
    </x-page-header>

    @include('partials.alerts')

    <p class="text-muted small mb-3">
        Значения для отделов, где своё не задано. Отдел задаёт своё в карточке, вкладка «Зарплата».
    </p>

    {{-- Поля — из реестра config/department_settings.php; новый ключ появится здесь сам --}}
    <form method="POST" action="{{ route('admin.settings.update') }}" data-submit-guard>
        @csrf

        @php $i = 0; @endphp
        @foreach($groups as $group)
            <div class="card shadow-sm mb-3">
                <div class="card-header fw-semibold py-2">{{ $group['label'] }}</div>
                <div class="card-body">
                    @if(! empty($group['hint']))
                        <div class="text-muted small mb-3">{{ $group['hint'] }}</div>
                    @endif

                    <div class="row g-3">
                        @foreach($group['keys'] as $key => $meta)
                            <div class="col-12 col-md-6">
                                <label for="setting_{{ $key }}" class="form-label fw-semibold small mb-1">
                                    {{ $meta['label'] }}
                                </label>
                                <input type="number" step="any" min="0"
                                       id="setting_{{ $key }}"
                                       name="settings[{{ $i }}][value]"
                                       value="{{ old('settings.' . $i . '.value', $values[$key]) }}"
                                       class="form-control @error('settings.' . $i . '.value') is-invalid @enderror"
                                       required>
                                <input type="hidden" name="settings[{{ $i }}][key]" value="{{ $key }}">
                                <div class="form-text" style="font-size:.7rem">По умолчанию в коде: {{ $meta['default'] ?? 0 }}</div>
                                @error('settings.' . $i . '.value')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            @php $i++; @endphp
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach

        <div class="d-grid d-md-flex justify-content-md-end mb-4">
            <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-check-lg"></i> Сохранить
            </button>
        </div>
    </form>

</div>
@endsection
